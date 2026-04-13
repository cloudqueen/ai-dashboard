<?php

namespace App\Console\Commands;

use App\Models\AgentRun;
use App\Services\Agent\AgentOrchestrator;
use App\Services\Vault\VaultManager;
use Illuminate\Console\Command;

class AgentHeartbeat extends Command
{
    protected $signature = 'agent:heartbeat';

    protected $description = 'Check for ready tickets and dispatch agent runs';

    public function handle(AgentOrchestrator $orchestrator, VaultManager $vault): int
    {
        if (! $vault->isConfigured()) {
            return self::SUCCESS;
        }

        $this->info('Agent heartbeat...');

        $orchestrator->heartbeat();

        $active = AgentRun::active()->count();
        $this->info("Active runs: {$active}");

        return self::SUCCESS;
    }
}
