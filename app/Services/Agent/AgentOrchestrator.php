<?php

namespace App\Services\Agent;

use App\Models\AgentRun;
use App\Models\CostEvent;
use App\Models\Ticket;
use App\Jobs\ExecuteAgentRun;
use Illuminate\Support\Facades\DB;
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
     * Check monthly budget before dispatching.
     */
    private function withinBudget(): bool
    {
        $budgetLimit = config('dashboard.agent.monthly_budget_usd');

        if (! $budgetLimit) {
            return true;
        }

        $monthlySpend = CostEvent::whereYear('occurred_at', now()->year)
            ->whereMonth('occurred_at', now()->month)
            ->where('source', 'agent')
            ->sum('cost_usd');

        if ($monthlySpend >= $budgetLimit) {
            Log::warning('Agent budget limit reached', [
                'spent' => $monthlySpend,
                'limit' => $budgetLimit,
            ]);
            return false;
        }

        return true;
    }

    /**
     * Find and dispatch ready tickets with atomic checkout.
     */
    public function dispatchReadyTickets(): void
    {
        if (app(\App\Services\SettingsService::class)->agentPaused()) {
            return;
        }

        if (! $this->canAcceptNewRun() || ! $this->withinBudget()) {
            return;
        }

        $activeTicketIds = AgentRun::active()->pluck('ticket_id')->filter()->all();

        $readyTickets = Ticket::readyForAgent()
            ->whereNotIn('id', $activeTicketIds)
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
            if (! $this->canAcceptNewRun() || ! $this->withinBudget()) {
                break;
            }

            if (! $this->dependenciesMet($ticket)) {
                continue;
            }

            $this->dispatchAgentRun($ticket);
        }
    }

    /**
     * Dispatch an agent run with atomic checkout to prevent double-dispatch.
     */
    public function dispatchAgentRun(Ticket $ticket, ?string $skillOverride = null): ?AgentRun
    {
        $skill = $skillOverride ?? $ticket->agent_skill ?? $this->inferSkill($ticket);

        return DB::transaction(function () use ($ticket, $skill) {
            $exists = AgentRun::where('ticket_id', $ticket->id)
                ->whereIn('status', ['queued', 'running'])
                ->lockForUpdate()
                ->exists();

            if ($exists) {
                Log::info("Skipping already-active ticket", ['ticket_id' => $ticket->id]);
                return null;
            }

            $run = AgentRun::create([
                'ticket_id' => $ticket->id,
                'skill' => $skill,
                'status' => 'queued',
            ]);

            ExecuteAgentRun::dispatch($run);

            app(\App\Services\ActivityLogger::class)->log(
                'system', 'dispatched', 'agent_run', (string) $run->id,
                $run->id, ['ticket_id' => $ticket->id, 'title' => $ticket->title, 'skill' => $skill]
            );

            Log::info("Agent run dispatched", [
                'run_id' => $run->id,
                'ticket_id' => $ticket->id,
                'skill' => $skill,
            ]);

            return $run;
        });
    }

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

            app(\App\Services\ActivityLogger::class)->log(
                'system', 'timed_out', 'agent_run', (string) $run->id,
                $run->id, ['ticket_id' => $run->ticket_id]
            );

            Log::warning("Agent run timed out", ['run_id' => $run->id]);
        }
    }

    /**
     * Check if all depends_on tickets are done.
     */
    private function dependenciesMet(Ticket $ticket): bool
    {
        $dependsOn = $ticket->depends_on ?? [];

        if (empty($dependsOn)) {
            return true;
        }

        $undone = Ticket::whereIn('id', $dependsOn)
            ->where('status', '!=', 'done')
            ->exists();

        return ! $undone;
    }

    /**
     * Infer the best skill for a ticket based on its type.
     */
    private function inferSkill(Ticket $ticket): string
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
