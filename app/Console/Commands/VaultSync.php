<?php

namespace App\Console\Commands;

use App\Services\SettingsService;
use App\Services\Vault\GitSync;
use App\Services\Vault\VaultManager;
use Illuminate\Console\Command;

class VaultSync extends Command
{
    protected $signature = 'vault:sync';

    protected $description = 'Sync the Obsidian vault with its remote git repository';

    public function handle(GitSync $gitSync, VaultManager $vault, SettingsService $settings): int
    {
        if (! config('dashboard.vault.sync_enabled')) {
            $this->info('Vault sync is disabled.');
            return self::SUCCESS;
        }

        if (! $vault->isConfigured()) {
            $this->error('Vault path is not configured. Set VAULT_PATH in .env');
            return self::FAILURE;
        }

        $this->info('Pulling remote changes...');
        $result = $gitSync->pull();

        if (! $result->success) {
            $settings->recordVaultSync(false, $result->error);
            $this->error('Sync failed: ' . $result->error);
            return self::FAILURE;
        }

        if (! empty($result->filesChanged)) {
            $this->info('Re-indexing ' . count($result->filesChanged) . ' changed files...');
            foreach ($result->filesChanged as $file) {
                if (str_ends_with($file, '.md')) {
                    $vault->indexNote($file);
                }
            }
        }

        $settings->recordVaultSync(true);
        $this->info('Vault sync complete.');

        return self::SUCCESS;
    }
}
