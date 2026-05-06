<?php

namespace App\Services;

use App\Models\Setting;

class SettingsService
{
    public function agentPaused(): bool
    {
        return (bool) Setting::get('agent.paused', false);
    }

    public function setAgentPaused(bool $value): void
    {
        Setting::set('agent.paused', $value, 'boolean', 'agent');
    }

    public function psychologyEnabled(): bool
    {
        $override = Setting::get('psychology.enabled', null);
        if ($override !== null) {
            return (bool) $override;
        }

        return (bool) config('dashboard.psychology.enabled', false);
    }

    public function setPsychologyEnabled(bool $value): void
    {
        Setting::set('psychology.enabled', $value, 'boolean', 'psychology');
    }

    public function wipSoftLimit(): int
    {
        return (int) Setting::get('psychology.wip_soft_limit', config('dashboard.psychology.wip_soft_limit', 3));
    }

    public function setWipSoftLimit(int $value): void
    {
        Setting::set('psychology.wip_soft_limit', $value, 'integer', 'psychology');
    }

    public function wipHardLimit(): int
    {
        return (int) Setting::get('psychology.wip_hard_limit', config('dashboard.psychology.wip_hard_limit', 5));
    }

    public function setWipHardLimit(int $value): void
    {
        Setting::set('psychology.wip_hard_limit', $value, 'integer', 'psychology');
    }

    public function vaultLastSyncAt(): ?string
    {
        return Setting::get('vault.last_sync_at');
    }

    public function vaultLastSyncStatus(): ?string
    {
        return Setting::get('vault.last_sync_status');
    }

    public function vaultLastSyncError(): ?string
    {
        return Setting::get('vault.last_sync_error');
    }

    public function recordVaultSync(bool $ok, ?string $error = null): void
    {
        Setting::set('vault.last_sync_at', now()->toIso8601String(), 'string', 'vault');
        Setting::set('vault.last_sync_status', $ok ? 'ok' : 'failed', 'string', 'vault');
        Setting::set('vault.last_sync_error', $error ?? '', 'string', 'vault');
    }

    public function profilePath(): string
    {
        return \Illuminate\Support\Facades\Storage::disk('local')->path(
            config('dashboard.storage.profile', 'dashboard/profile.md')
        );
    }

    public function getProfile(): string
    {
        $path = $this->profilePath();
        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    public function setProfile(string $content): void
    {
        $path = $this->profilePath();
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, $content);
    }

    public function dailyCoachProjectId(): ?string
    {
        $v = Setting::get('daily_coach.project_id');
        return $v ?: null;
    }

    public function setDailyCoachProjectId(?string $id): void
    {
        Setting::set('daily_coach.project_id', $id ?? '', 'string', 'daily_coach');
    }
}
