<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\ProjectService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class NightlyProjects extends Command
{
    protected $signature = 'projects:nightly {--project= : Run only this project name}';
    protected $description = 'Run the nightly agent for active projects (sequential)';

    public function handle(ProjectService $service): int
    {
        $query = Project::nightlyDue();
        if ($name = $this->option('project')) {
            $query->where('name', $name);
        }

        $projects = $query->orderBy('id')->get();

        if ($projects->isEmpty()) {
            $this->info('No active projects with nightly_enabled.');
            return self::SUCCESS;
        }

        foreach ($projects as $project) {
            $this->info("→ Running nightly for {$project->name}");
            try {
                $run = $service->runNightly($project);
                $this->line("  status={$run->status} branch={$run->branch_name} duration={$run->duration_seconds}s tokens={$run->tokens_used}");
                if ($run->status !== 'completed') {
                    $this->warn("  error: {$run->error_message}");
                }
            } catch (\Throwable $e) {
                Log::error('Nightly command threw uncaught', [
                    'project' => $project->name,
                    'error' => $e->getMessage(),
                ]);
                $this->error("  uncaught: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
