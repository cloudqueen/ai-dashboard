<?php

namespace App\Console\Commands;

use App\Services\Vault\VaultManager;
use Illuminate\Console\Command;

class VaultIndex extends Command
{
    protected $signature = 'vault:index {--changed-only : Only re-index files with changed content}';

    protected $description = 'Index all markdown files in the Obsidian vault into the database';

    public function handle(VaultManager $vault): int
    {
        if (! $vault->isConfigured()) {
            $this->error('Vault path is not configured. Set VAULT_PATH in .env');
            return self::FAILURE;
        }

        $changedOnly = $this->option('changed-only');

        $this->info($changedOnly ? 'Indexing changed vault files...' : 'Full vault re-index...');

        $count = $vault->indexAll($changedOnly);

        $this->info("Indexed {$count} files.");

        return self::SUCCESS;
    }
}
