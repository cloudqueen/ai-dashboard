<?php

namespace App\Services;

use App\Models\Routine;
use App\Models\RoutineRun;
use App\Services\TicketService;
use Cron\CronExpression;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class RoutineScheduler
{
    public function __construct(
        private TicketService $tickets,
    ) {}

    /**
     * Check all enabled routines and create tickets for due ones.
     */
    public function checkAndDispatch(): int
    {
        $dispatched = 0;

        $routines = Routine::enabled()->get();

        foreach ($routines as $routine) {
            if (! $routine->isDue()) {
                continue;
            }

            // Skip if there's already a pending/running run
            $activeRun = $routine->runs()
                ->whereIn('status', ['pending', 'running'])
                ->exists();

            if ($activeRun) {
                continue;
            }

            $this->dispatch($routine);
            $dispatched++;
        }

        return $dispatched;
    }

    /**
     * Dispatch a single routine — create a ticket and a routine run.
     */
    public function dispatch(Routine $routine): RoutineRun
    {
        $date = now()->format('Y-m-d');
        $title = "{$routine->name} — {$date}";

        $ticket = $this->tickets->create([
            'title' => $title,
            'type' => 'task',
            'status' => 'ready_for_agent',
            'priority' => $routine->priority,
            'assigned_to' => 'agent',
            'agent_skill' => $routine->skill,
            'description' => $routine->prompt_template,
            'due_date' => now()->format('Y-m-d'),
            'context_links' => $routine->context_links ?? [],
            'model' => $routine->model,
            'routine_id' => $routine->id,
        ]);

        $run = RoutineRun::create([
            'routine_id' => $routine->id,
            'ticket_id' => $ticket->id,
            'status' => 'pending',
            'started_at' => now(),
        ]);

        $this->updateNextRun($routine);

        app(ActivityLogger::class)->log(
            'system', 'routine_dispatched', 'routine', (string) $routine->id,
            details: ['name' => $routine->name, 'ticket_id' => $ticket->id]
        );

        Log::info("Routine dispatched", [
            'routine' => $routine->name,
            'ticket_id' => $ticket->id,
        ]);

        return $run;
    }

    /**
     * Calculate and set the next run time based on cron expression.
     */
    private function updateNextRun(Routine $routine): void
    {
        try {
            $cron = new CronExpression($routine->cron_expression);
            $nextRun = Carbon::instance($cron->getNextRunDate());

            $routine->update([
                'last_run_at' => now(),
                'next_run_at' => $nextRun,
            ]);
        } catch (\Throwable $e) {
            Log::error("Invalid cron expression for routine", [
                'routine' => $routine->name,
                'cron' => $routine->cron_expression,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Sync routine run status from agent runs.
     */
    public function syncRunStatuses(): void
    {
        $pendingRuns = RoutineRun::whereIn('status', ['pending', 'running'])
            ->with('routine')
            ->get();

        foreach ($pendingRuns as $run) {
            if (! $run->ticket_id) {
                continue;
            }

            $agentRun = \App\Models\AgentRun::where('ticket_id', $run->ticket_id)
                ->latest()
                ->first();

            if (! $agentRun) {
                continue;
            }

            // Link agent run
            if (! $run->agent_run_id) {
                $run->update(['agent_run_id' => $agentRun->id]);
            }

            if ($agentRun->status === 'running') {
                $run->update(['status' => 'running']);
            } elseif ($agentRun->status === 'completed') {
                $run->update([
                    'status' => 'completed',
                    'summary' => $agentRun->summary,
                    'output_note_path' => $agentRun->output_note_path,
                    'completed_at' => $agentRun->completed_at,
                ]);

                app(DashboardEventBus::class)->emit('routine_completed', [
                    'routine' => $run->routine?->name,
                    'run_id' => $run->id,
                    'output' => $agentRun->output_note_path,
                ]);
            } elseif (in_array($agentRun->status, ['failed', 'timed_out'])) {
                $run->update([
                    'status' => 'failed',
                    'completed_at' => now(),
                ]);
            }
        }
    }
}
