<?php

namespace App\Services\Vault;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class GitSync
{
    private string $vaultPath;
    private string $branch;

    public function __construct()
    {
        $this->vaultPath = config('dashboard.vault.path');
        $this->branch = config('dashboard.vault.sync_branch', 'main');
    }

    /**
     * Pull remote changes into the vault.
     */
    public function pull(): SyncResult
    {
        return $this->withLock(function () {
            $fetch = $this->git('fetch origin ' . $this->branch);
            if (! $fetch->successful()) {
                return new SyncResult(false, [], [], 'Fetch failed: ' . $fetch->errorOutput());
            }

            // Check if there are incoming changes
            $status = $this->git('status --porcelain');
            $localChanges = ! empty(trim($status->output()));

            if ($localChanges) {
                // Stash local changes before pulling
                $this->git('stash');
            }

            $pull = $this->git('pull --rebase origin ' . $this->branch);

            if (! $pull->successful()) {
                // Abort rebase on failure
                $this->git('rebase --abort');
                if ($localChanges) {
                    $this->git('stash pop');
                }
                return new SyncResult(false, [], [], 'Pull failed: ' . $pull->errorOutput());
            }

            if ($localChanges) {
                $stashPop = $this->git('stash pop');
                if (! $stashPop->successful() && str_contains($stashPop->errorOutput(), 'CONFLICT')) {
                    Log::warning('Vault sync: merge conflict on stash pop', [
                        'error' => $stashPop->errorOutput(),
                    ]);
                    return new SyncResult(false, [], ['stash-conflict'], 'Merge conflict during sync');
                }
            }

            // Get list of changed files
            $diffOutput = $this->git('diff --name-only HEAD~1 HEAD 2>/dev/null');
            $changedFiles = array_filter(explode("\n", trim($diffOutput->output())));

            return new SyncResult(true, $changedFiles);
        });
    }

    /**
     * Push specific files to remote.
     */
    public function push(array $files, string $commitMessage): SyncResult
    {
        return $this->withLock(function () use ($files, $commitMessage) {
            foreach ($files as $file) {
                $this->git('add ' . escapeshellarg($file));
            }

            $status = $this->git('status --porcelain');
            if (empty(trim($status->output()))) {
                return new SyncResult(true, [], [], 'Nothing to push');
            }

            $commit = $this->git('commit -m ' . escapeshellarg($commitMessage));
            if (! $commit->successful()) {
                return new SyncResult(false, [], [], 'Commit failed: ' . $commit->errorOutput());
            }

            $push = $this->git('push origin ' . $this->branch);
            if (! $push->successful()) {
                return new SyncResult(false, [], [], 'Push failed: ' . $push->errorOutput());
            }

            return new SyncResult(true, $files);
        });
    }

    /**
     * Check if there are remote changes to pull.
     */
    public function hasRemoteChanges(): bool
    {
        $this->git('fetch origin ' . $this->branch);
        $result = $this->git('rev-list HEAD..origin/' . $this->branch . ' --count');

        return (int) trim($result->output()) > 0;
    }

    /**
     * Check if there are uncommitted local changes.
     */
    public function hasLocalChanges(): bool
    {
        $result = $this->git('status --porcelain');

        return ! empty(trim($result->output()));
    }

    /**
     * Execute a git command in the vault directory.
     */
    private function git(string $command): \Illuminate\Process\ProcessResult
    {
        return Process::path($this->vaultPath)
            ->timeout(60)
            ->run("git {$command}");
    }

    /**
     * Execute a callback while holding the git sync lock.
     */
    private function withLock(callable $callback): SyncResult
    {
        $lock = Cache::lock('vault-git-sync', 120);

        if (! $lock->get()) {
            return new SyncResult(false, [], [], 'Could not acquire git sync lock');
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }
}
