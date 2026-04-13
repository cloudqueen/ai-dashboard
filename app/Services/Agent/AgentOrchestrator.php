<?php

namespace App\Services\Agent;

use App\Models\AgentRun;
use App\Models\VaultNote;
use App\Jobs\ExecuteAgentRun;
use Illuminate\Support\Facades\Log;

class AgentOrchestrator
{
    /**
     * Main heartbeat: check for ready tickets, process completions, handle timeouts.
     */
    public function heartbeat(): void
    {
        $this->checkForTimedOutRuns();
        $this->dispatchReadyTickets();
    }

    /**
     * Check if we can accept new agent runs (WIP limit).
     */
    public function canAcceptNewRun(): bool
    {
        $activeCount = AgentRun::active()->count();
        $maxConcurrent = config('dashboard.agent.max_concurrent', 2);

        return $activeCount < $maxConcurrent;
    }

    /**
     * Find and dispatch ready tickets.
     */
    public function dispatchReadyTickets(): void
    {
        if (! $this->canAcceptNewRun()) {
            return;
        }

        $readyTickets = VaultNote::where('status', 'ready_for_agent')
            ->whereNotIn('relative_path', AgentRun::active()->pluck('ticket_path'))
            ->orderByRaw("CASE priority
                WHEN 'critical' THEN 1
                WHEN 'high' THEN 2
                WHEN 'medium' THEN 3
                WHEN 'low' THEN 4
                ELSE 5
            END")
            ->orderBy('due_date')
            ->get();

        foreach ($readyTickets as $ticket) {
            if (! $this->canAcceptNewRun()) {
                break;
            }

            $this->dispatchAgentRun($ticket);
        }
    }

    /**
     * Dispatch an agent run for a specific ticket.
     */
    public function dispatchAgentRun(VaultNote $ticket, ?string $skillOverride = null): AgentRun
    {
        $skill = $skillOverride ?? $ticket->frontmatter['agent_skill'] ?? $this->inferSkill($ticket);

        $run = AgentRun::create([
            'vault_note_id' => $ticket->id,
            'ticket_path' => $ticket->relative_path,
            'skill' => $skill,
            'status' => 'queued',
        ]);

        ExecuteAgentRun::dispatch($run);

        Log::info("Agent run dispatched", [
            'run_id' => $run->id,
            'ticket' => $ticket->relative_path,
            'skill' => $skill,
        ]);

        return $run;
    }

    /**
     * Check for timed-out runs and mark them.
     */
    public function checkForTimedOutRuns(): void
    {
        $timeout = config('dashboard.agent.timeout_minutes', 30);

        $timedOut = AgentRun::where('status', 'running')
            ->where('started_at', '<', now()->subMinutes($timeout))
            ->get();

        foreach ($timedOut as $run) {
            $run->update([
                'status' => 'timed_out',
                'error_message' => "Run timed out after {$timeout} minutes",
                'completed_at' => now(),
            ]);

            Log::warning("Agent run timed out", ['run_id' => $run->id]);
        }
    }

    /**
     * Infer the best skill for a ticket based on its type.
     */
    private function inferSkill(VaultNote $ticket): string
    {
        return match ($ticket->type) {
            'research' => 'research',
            'writing' => 'writing',
            'bug', 'feature' => 'coding',
            'email' => 'email',
            default => 'analysis',
        };
    }
}
