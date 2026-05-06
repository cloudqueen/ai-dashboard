<?php

namespace App\Jobs;

use App\Models\AgentRun;
use App\Services\Agent\ClaudeRunner;
use App\Services\Agent\OutputProcessor;
use App\Services\Agent\PromptBuilder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ExecuteAgentRun implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;
    public int $tries = 1;

    public function __construct(
        public AgentRun $run,
    ) {
        $this->onQueue('agent');
    }

    public function handle(PromptBuilder $promptBuilder, ClaudeRunner $runner, OutputProcessor $outputProcessor): void
    {
        $this->run->update([
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            [$prompt, $skillModel] = $promptBuilder->build($this->run->ticket_id, $this->run->skill);
            $this->run->update(['prompt' => $prompt]);

            $timeoutSeconds = config('dashboard.agent.timeout_minutes', 30) * 60;
            $model = $this->run->ticket?->model ?? $skillModel;
            $output = $runner->run($prompt, $timeoutSeconds, $model);

            if ($output->success) {
                $this->run->update([
                    'status' => 'completed',
                    'raw_output' => $output->rawOutput,
                    'summary' => $output->summary,
                    'tokens_used' => $output->tokensUsed,
                    'duration_seconds' => $output->durationSeconds,
                    'completed_at' => now(),
                ]);

                $outputProcessor->process($this->run);

                app(\App\Services\ActivityLogger::class)->log(
                    'agent', 'completed', 'agent_run', (string) $this->run->id,
                    $this->run->id, ['ticket_id' => $this->run->ticket_id, 'skill' => $this->run->skill]
                );

                Log::info("Agent run completed", ['run_id' => $this->run->id]);
            } else {
                $this->run->update([
                    'status' => 'failed',
                    'error_message' => $output->errorMessage,
                    'duration_seconds' => $output->durationSeconds,
                    'completed_at' => now(),
                ]);

                Log::error("Agent run failed", [
                    'run_id' => $this->run->id,
                    'error' => $output->errorMessage,
                ]);
            }
        } catch (\Throwable $e) {
            $this->run->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);

            Log::error("Agent run exception", [
                'run_id' => $this->run->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
