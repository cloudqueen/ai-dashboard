<?php

namespace App\Services\Psychology;

use App\Models\PsychIntervention;
use App\Models\TaskEvent;
use App\Models\Ticket;

class PsychEngine
{
    /**
     * Evaluate a trigger and generate an intervention if needed.
     */
    public function evaluate(string $trigger, Ticket|int|null $ticket = null): ?PsychIntervention
    {
        if (! app(\App\Services\SettingsService::class)->psychologyEnabled()) {
            return null;
        }

        $ticket = is_int($ticket) ? Ticket::find($ticket) : $ticket;

        return match ($trigger) {
            'status_change' => $this->onStatusChange($ticket),
            'task_start' => $this->onTaskStart($ticket),
            'task_complete' => $this->onTaskComplete($ticket),
            'dashboard_load' => $this->onDashboardLoad(),
            'wip_exceeded' => $this->onWipExceeded(),
            default => null,
        };
    }

    public function getActiveInterventions(int $limit = 3): array
    {
        return PsychIntervention::active()
            ->latest()
            ->limit($limit)
            ->get()
            ->toArray();
    }

    public function getDashboardInsights(): array
    {
        $thirtyDaysAgo = now()->copy()->subDays(30);

        $completed = TaskEvent::where('event_type', 'status_changed')
            ->where('to_status', 'done')
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->count();

        $postponements = TaskEvent::where('event_type', 'status_changed')
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->whereColumn('from_status', '!=', 'to_status')
            ->whereIn('from_status', ['in_progress', 'todo'])
            ->whereIn('to_status', ['backlog', 'todo'])
            ->count();

        $avoidedTasks = Ticket::query()
            ->whereIn('status', ['backlog', 'todo'])
            ->where('emotional_charge', 'high')
            ->count();

        $wipCount = Ticket::query()
            ->whereIn('status', ['in_progress', 'ready_for_agent'])
            ->count();

        $settings = app(\App\Services\SettingsService::class);
        $wipSoft = $settings->wipSoftLimit();
        $wipHard = $settings->wipHardLimit();

        return [
            'completed_30d' => $completed,
            'postponements_30d' => $postponements,
            'avoided_tasks' => $avoidedTasks,
            'wip_count' => $wipCount,
            'wip_soft_limit' => $wipSoft,
            'wip_hard_limit' => $wipHard,
            'wip_status' => $wipCount >= $wipHard ? 'overloaded' : ($wipCount >= $wipSoft ? 'warning' : 'ok'),
            'streak_days' => $this->calculateStreak(),
            'active_interventions' => $this->getActiveInterventions(),
        ];
    }

    private function onStatusChange(?Ticket $ticket): ?PsychIntervention
    {
        if (! $ticket) {
            return null;
        }

        $threshold = config('dashboard.psychology.postpone_threshold', 3);

        if ($ticket->postpone_count >= $threshold) {
            return $this->createChimpIntervention('avoidance_nudge', 'status_change', $ticket,
                $this->generateAvoidanceNudge($ticket));
        }

        return null;
    }

    private function onTaskStart(?Ticket $ticket): ?PsychIntervention
    {
        if (! $ticket) {
            return null;
        }

        $charge = $ticket->emotional_charge ?? 'low';

        if ($charge === 'high') {
            return $this->createChimpIntervention('reframe', 'task_start', $ticket,
                $this->generateReframe($ticket));
        }

        if (in_array($charge, ['medium', 'high'])) {
            return $this->createZrmIntervention('somatic_checkin', 'task_start', $ticket,
                $this->generateSomaticCheckin($ticket));
        }

        return null;
    }

    private function onTaskComplete(?Ticket $ticket): ?PsychIntervention
    {
        $charge = $ticket?->emotional_charge ?? 'low';

        if ($charge === 'high') {
            return $this->createChimpIntervention('celebration', 'task_complete', $ticket,
                $this->generateCelebration($ticket, intense: true));
        }

        if ($ticket) {
            return $this->createZrmIntervention('resource_activation', 'task_complete', $ticket,
                $this->generateResourceActivation($ticket));
        }

        return null;
    }

    private function onDashboardLoad(): ?PsychIntervention
    {
        $avoided = Ticket::query()
            ->whereIn('status', ['backlog', 'todo'])
            ->where('emotional_charge', 'high')
            ->where('postpone_count', '>=', 2)
            ->oldest('updated_at')
            ->first();

        if ($avoided) {
            $recent = PsychIntervention::where('ticket_id', $avoided->id)
                ->where('intervention_type', 'avoidance_nudge')
                ->where('created_at', '>=', now()->subHours(24))
                ->exists();

            if (! $recent) {
                return $this->createChimpIntervention('avoidance_nudge', 'dashboard_load', $avoided,
                    $this->generateAvoidanceNudge($avoided));
            }
        }

        return $this->dailyMottoGoal();
    }

    private function onWipExceeded(): ?PsychIntervention
    {
        $wipCount = Ticket::query()
            ->whereIn('status', ['in_progress', 'ready_for_agent'])
            ->count();

        $wipHard = app(\App\Services\SettingsService::class)->wipHardLimit();

        if ($wipCount >= $wipHard) {
            return $this->createChimpIntervention('reframe', 'wip_exceeded', null,
                "Your chimp is excited about new things — that's natural! But you have **{$wipCount} tasks in progress**. "
                . "Your human brain knows finishing one thing creates more momentum than starting five. "
                . "Pick the one task closest to done and finish it first. The other ideas aren't going anywhere."
            );
        }

        return null;
    }

    private function generateReframe(Ticket $ticket): string
    {
        $title = $ticket->title;

        $reframes = [
            "Your chimp sees **\"{$title}\"** as a threat — that's just your inner alarm system. "
            . "Your human brain knows this task is manageable. Break it into the smallest possible first step. "
            . "What's one thing you can do in the next 5 minutes?",

            "The emotional resistance to **\"{$title}\"** is your chimp talking. "
            . "Acknowledge it: \"I feel uneasy about this, and that's okay.\" "
            . "Now, what would a calm, rational version of you do? Just the first step.",

            "Notice the feeling that comes up with **\"{$title}\"**. That's your chimp. "
            . "You don't need to fight it — just don't let it drive. "
            . "Set a timer for 10 minutes and commit to starting. You can stop after that.",
        ];

        return $reframes[array_rand($reframes)];
    }

    private function generateAvoidanceNudge(Ticket $ticket): string
    {
        $title = $ticket->title;
        $count = $ticket->postpone_count;

        $nudges = [
            "You've postponed **\"{$title}\"** {$count} times. Your chimp has been winning this one. "
            . "That's not a failure — it's information. What specifically feels uncomfortable about it? "
            . "Name the feeling, then decide: delegate it, break it smaller, or timebox 15 minutes on it right now.",

            "**\"{$title}\"** keeps getting pushed back ({$count}x). Your chimp is protecting you from something. "
            . "Ask yourself: \"What's the worst that happens if I just start?\" "
            . "Usually the starting is the hardest part. Give it 5 minutes.",

            "Pattern detected: **\"{$title}\"** has been avoided {$count} times. "
            . "This is classic chimp behavior — the task feels bigger in your head than it really is. "
            . "What's the absolute minimum viable version of this task?",
        ];

        return $nudges[array_rand($nudges)];
    }

    private function generateSomaticCheckin(Ticket $ticket): string
    {
        $title = $ticket->title;

        return "Before starting **\"{$title}\"**, take a moment for a body check-in (ZRM Strudelmodell):\n\n"
            . "1. Close your eyes briefly. How does your body feel right now?\n"
            . "2. Think about this task. Notice any changes — tension, energy, heaviness, lightness?\n"
            . "3. Rate your somatic marker: Does your body signal **green** (go), **yellow** (caution), or **red** (stop)?\n\n"
            . "If yellow or red: What small adjustment would shift it toward green? "
            . "Maybe change the environment, the approach, or the scope.";
    }

    private function generateResourceActivation(Ticket $ticket): string
    {
        $title = $ticket->title;

        $activations = [
            "You just completed **\"{$title}\"**. Take a moment to notice how that feels in your body. "
            . "That sense of accomplishment? That's your somatic marker for success. Remember this feeling — "
            . "it's a resource you can draw on when the next challenging task comes up.",

            "Done with **\"{$title}\"**! In ZRM terms, you just activated a resource. "
            . "What strength did you use to get this done? Name it. "
            . "That strength is available to you anytime.",

            "**\"{$title}\"** is complete. Notice: the resistance you may have felt before starting was temporary. "
            . "The capability that got you through it is permanent. Store this as a resource.",
        ];

        return $activations[array_rand($activations)];
    }

    private function generateCelebration(Ticket $ticket, bool $intense = false): string
    {
        $title = $ticket->title;

        if ($intense) {
            return "You conquered **\"{$title}\"** — a task your chimp really didn't want to face. "
                . "That took real courage. Your human brain overruled the chimp, and you came through. "
                . "This is proof that emotional resistance is not a reliable predictor of difficulty. "
                . "You're stronger than your chimp thinks.";
        }

        return "Nice work completing **\"{$title}\"**! Every finished task builds momentum.";
    }

    private function dailyMottoGoal(): ?PsychIntervention
    {
        $today = PsychIntervention::where('intervention_type', 'motto_goal')
            ->where('trigger', 'dashboard_load')
            ->whereDate('created_at', today())
            ->exists();

        if ($today) {
            return null;
        }

        $mottos = [
            "Today's motto-goal (ZRM): **\"I do things step by step, with calm strength.\"** "
            . "Let this guide your decisions today. When you feel pulled in many directions, return to it.",

            "Today's motto-goal (ZRM): **\"I start with what matters, not what's easy.\"** "
            . "Before picking your next task, check: is this the important one, or the comfortable one?",

            "Today's motto-goal (ZRM): **\"I trust my pace and honor my energy.\"** "
            . "Match your tasks to your energy level today. Hard things when sharp, routine when tired.",

            "Today's motto-goal (ZRM): **\"I finish what I start before starting something new.\"** "
            . "Your shiny-object chimp will suggest detours. Smile at it and stay the course.",
        ];

        return $this->createZrmIntervention('motto_goal', 'dashboard_load', null,
            $mottos[array_rand($mottos)]);
    }

    private function createChimpIntervention(string $type, string $trigger, ?Ticket $ticket, string $content): PsychIntervention
    {
        return PsychIntervention::create([
            'framework' => 'chimp',
            'intervention_type' => $type,
            'trigger' => $trigger,
            'ticket_id' => $ticket?->id,
            'content' => $content,
        ]);
    }

    private function createZrmIntervention(string $type, string $trigger, ?Ticket $ticket, string $content): PsychIntervention
    {
        return PsychIntervention::create([
            'framework' => 'zrm',
            'intervention_type' => $type,
            'trigger' => $trigger,
            'ticket_id' => $ticket?->id,
            'content' => $content,
        ]);
    }

    private function calculateStreak(): int
    {
        $days = 0;
        $date = today();

        while (true) {
            $hasCompletion = TaskEvent::where('event_type', 'status_changed')
                ->where('to_status', 'done')
                ->whereDate('created_at', $date)
                ->exists();

            if (! $hasCompletion) {
                break;
            }

            $days++;
            $date = $date->subDay();
        }

        return $days;
    }
}
