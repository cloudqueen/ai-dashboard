<?php

namespace App\Http\Controllers;

use App\Models\AgentRun;
use App\Models\VaultNote;
use App\Services\Vault\VaultManager;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index(VaultManager $vault)
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

        // Recent activity (task events)
        $recentActivity = \App\Models\TaskEvent::orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->toArray();

        return Inertia::render('Dashboard', [
            'vaultConfigured' => $vaultConfigured,
            'kanbanSummary' => $kanbanSummary,
            'dueSoon' => $dueSoon,
            'agentStatus' => $agentStatus,
            'recentActivity' => $recentActivity,
        ]);
    }
}
