<?php

namespace App\Http\Controllers;

use App\Models\AgentRun;
use App\Models\CoachMemory;
use App\Models\CostEvent;
use App\Models\VaultNote;
use App\Services\CoachMemoryService;
use App\Services\SettingsService;
use App\Services\Vault\VaultManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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
            'profile' => $settings->getProfile(),
            'dailyCoach' => [
                'project_id' => $settings->dailyCoachProjectId(),
                'greeting' => $settings->dailyCoachGreeting(),
                'deep_link' => $settings->dailyCoachDeepLink(),
                'mcp_command' => 'php ' . base_path('artisan') . ' mcp:serve',
            ],
            'memories' => CoachMemory::query()
                ->orderByDesc('created_at')
                ->limit(20)
                ->get(['id', 'type', 'session_date', 'content', 'metadata', 'created_at'])
                ->toArray(),
            'embeddings' => [
                'voyage_configured' => (bool) config('dashboard.embeddings.voyage_api_key'),
                'voyage_model' => config('dashboard.embeddings.voyage_model'),
                'monthly_embedding_cost_usd' => round((float) CostEvent::whereYear('occurred_at', now()->year)
                    ->whereMonth('occurred_at', now()->month)
                    ->where('source', 'embedding')
                    ->sum('cost_usd'), 6),
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

    public function updateProfile(Request $request, SettingsService $settings)
    {
        $data = $request->validate(['content' => 'required|string|max:50000']);
        $settings->setProfile($data['content']);

        return back()->with('success', 'Profil gespeichert.');
    }

    public function updateDailyCoachProjectId(Request $request, SettingsService $settings)
    {
        $data = $request->validate([
            'project_id' => 'nullable|string|max:200',
            'greeting' => 'nullable|string|max:5000',
        ]);

        if (array_key_exists('project_id', $data)) {
            $settings->setDailyCoachProjectId($data['project_id'] ?? null);
        }
        if (array_key_exists('greeting', $data)) {
            $settings->setDailyCoachGreeting($data['greeting'] ?? '');
        }

        return back()->with('success', 'Daily-Coach-Einstellungen gespeichert.');
    }

    public function searchMemories(Request $request, CoachMemoryService $memories)
    {
        $q = trim((string) $request->input('q', ''));
        $limit = (int) $request->input('limit', 5);

        if ($q === '') {
            return response()->json([]);
        }

        return response()->json(
            $memories->recall($q, $limit)
                ->map(fn ($m) => [
                    'id' => $m->id,
                    'type' => $m->type,
                    'session_date' => $m->session_date?->toDateString(),
                    'content' => $m->content,
                ])
        );
    }

    public function deleteMemory(CoachMemory $memory)
    {
        $memory->delete();
        return back();
    }
}
