<?php

namespace App\Http\Controllers;

use App\Models\AgentRun;
use App\Models\VaultNote;
use App\Services\Psychology\PsychEngine;
use App\Services\Vault\VaultManager;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index(VaultManager $vault, PsychEngine $psych)
    {
        $vaultConfigured = $vault->isConfigured();

        $kanbanSummary = [];
        $dueSoon = [];
        $recentActivity = [];
        $agentStatus = ['active' => 0, 'recent' => []];

        if ($vaultConfigured) {
            // Kanban summary
            $statuses = config('dashboard.kanban.statuses', []);
            foreach ($statuses as $key => $label) {
                $count = VaultNote::tickets()->where('status', $key)->count();
                $kanbanSummary[] = ['key' => $key, 'label' => $label, 'count' => $count];
            }

            // Due soon (next 7 days)
            $dueSoon = VaultNote::tickets()
                ->whereNotNull('due_date')
                ->where('due_date', '<=', now()->addDays(7))
                ->where('status', '!=', 'done')
                ->orderBy('due_date')
                ->limit(5)
                ->get(['title', 'relative_path', 'due_date', 'priority', 'status'])
                ->toArray();
        }

        // Agent status
        $agentStatus = [
            'active' => AgentRun::active()->count(),
            'recent' => AgentRun::orderBy('created_at', 'desc')
                ->limit(5)
                ->get(['id', 'ticket_path', 'skill', 'status', 'duration_seconds', 'created_at'])
                ->toArray(),
        ];

        // Recent activity — prefer new activity_log, fallback to task_events
        $recentActivity = \App\Models\ActivityLog::orderBy('created_at', 'desc')
            ->limit(15)
            ->get()
            ->toArray();

        if (empty($recentActivity)) {
            $recentActivity = \App\Models\TaskEvent::orderBy('created_at', 'desc')
                ->limit(10)
                ->get()
                ->map(fn ($e) => [
                    'actor_type' => 'system',
                    'action' => $e->event_type,
                    'entity_type' => 'ticket',
                    'entity_id' => $e->ticket_path,
                    'details' => ['from' => $e->from_status, 'to' => $e->to_status],
                    'created_at' => $e->created_at,
                ])
                ->toArray();
        }

        // Recent routine results
        $routineResults = \App\Models\RoutineRun::with('routine:id,name')
            ->where('status', 'completed')
            ->orderBy('completed_at', 'desc')
            ->limit(5)
            ->get(['id', 'routine_id', 'summary', 'output_note_path', 'completed_at'])
            ->toArray();

        // Usage stats
        $usageStats = [
            'today' => \App\Models\CostEvent::todayStats(),
            'month' => \App\Models\CostEvent::monthStats(),
        ];

        // Psychology insights
        $psychInsights = config('dashboard.psychology.enabled', false)
            ? $psych->getDashboardInsights()
            : null;

        // Trigger dashboard_load intervention
        if (config('dashboard.psychology.enabled', false)) {
            $psych->evaluate('dashboard_load');
        }

        return Inertia::render('Dashboard', [
            'vaultConfigured' => $vaultConfigured,
            'kanbanSummary' => $kanbanSummary,
            'dueSoon' => $dueSoon,
            'agentStatus' => $agentStatus,
            'recentActivity' => $recentActivity,
            'psychInsights' => $psychInsights,
            'routineResults' => $routineResults,
            'usageStats' => $usageStats,
        ]);
    }
}
