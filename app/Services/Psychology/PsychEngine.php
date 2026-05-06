<?php

namespace App\Services\Psychology;

use App\Models\PsychIntervention;
use App\Models\TaskEvent;
use App\Models\VaultNote;

class PsychEngine
{
    /**
     * Evaluate a ticket event and generate interventions if needed.
     */
    public function evaluate(string $trigger, ?string $ticketPath = null): ?PsychIntervention
    {
        if (! app(\App\Services\SettingsService::class)->psychologyEnabled()) {
            return null;
        }

        return match ($trigger) {
            'status_change' => $this->onStatusChange($ticketPath),
            'task_start' => $this->onTaskStart($ticketPath),
            'task_complete' => $this->onTaskComplete($ticketPath),
            'dashboard_load' => $this->onDashboardLoad(),
            'wip_exceeded' => $this->onWipExceeded(),
            default => null,
        };
    }

    /**
     * Get active (undismissed, unrated) interventions.
     */
    public function getActiveInterventions(int $limit = 3): array
    {
        return PsychIntervention::active()
            ->latest()
            ->limit($limit)
            ->get()
            ->toArray();
    }

    /**
     * Get psychology insights for the dashboard.
     */
    public function getDashboardInsights(): array
    {
        $now = now();
        $thirtyDaysAgo = $now->copy()->subDays(30);

        // Completion stats
        $completed = TaskEvent::where('event_type', 'status_changed')
            ->where('to_status', 'done')
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->count();

        // Postponement detection
        $postponements = TaskEvent::where('event_type', 'status_changed')
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->whereColumn('from_status', '!=', 'to_status')
            ->whereIn('from_status', ['in_progress', 'todo'])
            ->whereIn('to_status', ['backlog', 'todo'])
            ->count();

        // High emotional_charge tasks sitting unfinished
        $avoidedTasks = VaultNote::tickets()
            ->whereIn('status', ['backlog', 'todo'])
            ->whereRaw("json_extract(frontmatter, '$.emotional_charge') = 'high'")
            ->count();

        // Current WIP
        $wipCount = VaultNote::tickets()
            ->whereIn('status', ['in_progress', 'ready_for_agent'])
            ->count();

        $settings = app(\App\Services\SettingsService::class);
        $wipSoft = $settings->wipSoftLimit();
        $wipHard = $settings->wipHardLimit();

        // Streak: consecutive days with at least one completion
        $streak = $this->calculateStreak();

        return [
            'completed_30d' => $completed,
            'postponements_30d' => $postponements,
            'avoided_tasks' => $avoidedTasks,
            'wip_count' => $wipCount,
            'wip_soft_limit' => $wipSoft,
            'wip_hard_limit' => $wipHard,
            'wip_status' => $wipCount >= $wipHard ? 'overloaded' : ($wipCount >= $wipSoft ? 'warning' : 'ok'),
            'streak_days' => $streak,
            'active_interventions' => $this->getActiveInterventions(),
        ];
    }

    // --- Chimp Paradox ---

    private function onStatusChange(?string $ticketPath): ?PsychIntervention
    {
        if (! $ticketPath) {
            return null;
        }

        $note = VaultNote::where('relative_path', $ticketPath)->first();
        if (! $note) {
            return null;
        }

        // Detect avoidance: ticket moved backwards
        $postponeCount = $note->frontmatter['postpone_count'] ?? 0;
        $threshold = config('dashboard.psychology.postpone_threshold', 3);

        if ($postponeCount >= $threshold) {
            return $this->createChimpIntervention(
                'avoidance_nudge',
                'status_change',
                $ticketPath,
                $this->generateAvoidanceNudge($note)
            );
        }

        return null;
    }

    private function onTaskStart(?string $ticketPath): ?PsychIntervention
    {
        if (! $ticketPath) {
            return null;
        }

        $note = VaultNote::where('relative_path', $ticketPath)->first();
        if (! $note) {
            return null;
        }

        $emotionalCharge = $note->frontmatter['emotional_charge'] ?? 'low';

        // Chimp: high emotional charge — offer reframe
        if ($emotionalCharge === 'high') {
            return $this->createChimpIntervention(
                'reframe',
                'task_start',
                $ticketPath,
                $this->generateReframe($note)
            );
        }

        // ZRM: medium+ emotional charge — somatic check-in
        if (in_array($emotionalCharge, ['medium', 'high'])) {
            return $this->createZrmIntervention(
                'somatic_checkin',
                'task_start',
                $ticketPath,
                $this->generateSomaticCheckin($note)
            );
        }

        return null;
    }

    private function onTaskComplete(?string $ticketPath): ?PsychIntervention
    {
        $note = $ticketPath ? VaultNote::where('relative_path', $ticketPath)->first() : null;
        $emotionalCharge = $note ? ($note->frontmatter['emotional_charge'] ?? 'low') : 'low';

        // Celebrate high-charge completions more
        if ($emotionalCharge === 'high') {
            return $this->createChimpIntervention(
                'celebration',
                'task_complete',
                $ticketPath,
                $this->generateCelebration($note, intense: true)
            );
        }

        // ZRM: resource activation after completing something
        if ($note) {
            return $this->createZrmIntervention(
                'resource_activation',
                'task_complete',
                $ticketPath,
                $this->generateResourceActivation($note)
            );
        }

        return null;
    }

    private function onDashboardLoad(): ?PsychIntervention
    {
        // Check for avoided high-charge tasks
        $avoided = VaultNote::tickets()
            ->whereIn('status', ['backlog', 'todo'])
            ->whereRaw("json_extract(frontmatter, '$.emotional_charge') = 'high'")
            ->whereRaw("json_extract(frontmatter, '$.postpone_count') >= ?", [2])
            ->oldest('vault_modified_at')
            ->first();

        if ($avoided) {
            // Don't spam — check if we already nudged recently
            $recent = PsychIntervention::where('ticket_path', $avoided->relative_path)
                ->where('intervention_type', 'avoidance_nudge')
                ->where('created_at', '>=', now()->subHours(24))
                ->exists();

            if (! $recent) {
                return $this->createChimpIntervention(
                    'avoidance_nudge',
                    'dashboard_load',
                    $avoided->relative_path,
                    $this->generateAvoidanceNudge($avoided)
                );
            }
        }

        // ZRM: daily motto-goal reminder
        return $this->dailyMottoGoal();
    }

    private function onWipExceeded(): ?PsychIntervention
    {
        $wipCount = VaultNote::tickets()
            ->whereIn('status', ['in_progress', 'ready_for_agent'])
            ->count();

        $wipHard = app(\App\Services\SettingsService::class)->wipHardLimit();

        if ($wipCount >= $wipHard) {
            return $this->createChimpIntervention(
                'reframe',
                'wip_exceeded',
                null,
                "Your chimp is excited about new things — that's natural! But you have **{$wipCount} tasks in progress**. "
                . "Your human brain knows finishing one thing creates more momentum than starting five. "
                . "Pick the one task closest to done and finish it first. The other ideas aren't going anywhere."
            );
        }

        return null;
    }

    // --- Content generators ---

    private function generateReframe(VaultNote $note): string
    {
        $title = $note->title;
        $charge = $note->frontmatter['emotional_charge'] ?? 'high';

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

    private function generateAvoidanceNudge(VaultNote $note): string
    {
        $title = $note->title;
        $count = $note->frontmatter['postpone_count'] ?? 0;

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

    private function generateSomaticCheckin(VaultNote $note): string
    {
        $title = $note->title;

        return "Before starting **\"{$title}\"**, take a moment for a body check-in (ZRM Strudelmodell):\n\n"
            . "1. Close your eyes briefly. How does your body feel right now?\n"
            . "2. Think about this task. Notice any changes — tension, energy, heaviness, lightness?\n"
            . "3. Rate your somatic marker: Does your body signal **green** (go), **yellow** (caution), or **red** (stop)?\n\n"
            . "If yellow or red: What small adjustment would shift it toward green? "
            . "Maybe change the environment, the approach, or the scope.";
    }

    private function generateResourceActivation(VaultNote $note): string
    {
        $title = $note->title;

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

    private function generateCelebration(VaultNote $note, bool $intense = false): string
    {
        $title = $note->title;

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
        // Only once per day
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

        return $this->createZrmIntervention(
            'motto_goal',
            'dashboard_load',
            null,
            $mottos[array_rand($mottos)]
        );
    }

    // --- Helpers ---

    private function createChimpIntervention(string $type, string $trigger, ?string $ticketPath, string $content): PsychIntervention
    {
        return PsychIntervention::create([
            'framework' => 'chimp',
            'intervention_type' => $type,
            'trigger' => $trigger,
            'ticket_path' => $ticketPath,
            'content' => $content,
        ]);
    }

    private function createZrmIntervention(string $type, string $trigger, ?string $ticketPath, string $content): PsychIntervention
    {
        return PsychIntervention::create([
            'framework' => 'zrm',
            'intervention_type' => $type,
            'trigger' => $trigger,
            'ticket_path' => $ticketPath,
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
