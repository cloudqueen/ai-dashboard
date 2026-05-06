<?php

namespace App\Http\Controllers;

use App\Models\AgentRun;
use App\Models\CostEvent;
use App\Models\VaultNote;
use App\Services\SettingsService;
use App\Services\Vault\VaultManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class SettingsController extends Controller
{
    public function index(SettingsService $settings, VaultManager $vault)
    {
        $monthlyBudget = config('dashboard.agent.monthly_budget_usd');
        $monthlySpend = (float) CostEvent::whereYear('occurred_at', now()->year)
            ->whereMonth('occurred_at', now()->month)
            ->where('source', 'agent')
            ->sum('cost_usd');

        $lastNote = VaultNote::orderBy('vault_modified_at', 'desc')
            ->first(['relative_path', 'vault_modified_at']);

        return Inertia::render('Settings/Index', [
            'vault' => [
                'path' => config('dashboard.vault.path'),
                'git_remote' => config('dashboard.vault.git_remote'),
                'sync_enabled' => (bool) config('dashboard.vault.sync_enabled'),
                'sync_branch' => config('dashboard.vault.sync_branch'),
                'configured' => $vault->isConfigured(),
                'notes_count' => VaultNote::count(),
                'last_note_modified_at' => $lastNote?->vault_modified_at,
                'last_sync_at' => $settings->vaultLastSyncAt(),
                'last_sync_status' => $settings->vaultLastSyncStatus(),
                'last_sync_error' => $settings->vaultLastSyncError(),
            ],
            'agent' => [
                'cli_path' => config('dashboard.agent.cli_path'),
                'max_concurrent' => (int) config('dashboard.agent.max_concurrent'),
                'timeout_minutes' => (int) config('dashboard.agent.timeout_minutes'),
                'heartbeat_minutes' => (int) config('dashboard.agent.heartbeat_minutes'),
                'monthly_budget_usd' => $monthlyBudget !== null ? (float) $monthlyBudget : null,
                'monthly_spend_usd' => round($monthlySpend, 4),
                'active_runs' => AgentRun::active()->count(),
                'paused' => $settings->agentPaused(),
            ],
            'psychology' => [
                'enabled' => $settings->psychologyEnabled(),
                'wip_soft_limit' => $settings->wipSoftLimit(),
                'wip_hard_limit' => $settings->wipHardLimit(),
            ],
            'queue' => [
                'pending' => DB::table('jobs')->count(),
                'failed' => DB::table('failed_jobs')->count(),
            ],
        ]);
    }

    public function update(Request $request, SettingsService $settings, string $key)
    {
        $allowed = [
            'agent.paused' => ['boolean'],
            'psychology.enabled' => ['boolean'],
            'psychology.wip_soft_limit' => ['integer', 'min:1', 'max:20'],
            'psychology.wip_hard_limit' => ['integer', 'min:1', 'max:20'],
        ];

        abort_unless(array_key_exists($key, $allowed), 404);

        $data = $request->validate(['value' => $allowed[$key]]);
        $value = $data['value'];

        match ($key) {
            'agent.paused' => $settings->setAgentPaused((bool) $value),
            'psychology.enabled' => $settings->setPsychologyEnabled((bool) $value),
            'psychology.wip_soft_limit' => $settings->setWipSoftLimit((int) $value),
            'psychology.wip_hard_limit' => $settings->setWipHardLimit((int) $value),
        };

        return response()->json(['key' => $key, 'value' => $value]);
    }

    public function syncNow()
    {
        Artisan::call('vault:sync');

        return back();
    }

    public function retryFailedJobs()
    {
        Artisan::call('queue:retry', ['id' => ['all']]);

        return back();
    }
}
