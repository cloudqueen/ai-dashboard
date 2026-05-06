<?php

namespace App\Services\Agent;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class ClaudeRunner
{
    /**
     * Execute a prompt via the Claude CLI.
     */
    public function run(string $prompt, int $timeoutSeconds = 1800, ?string $model = null): AgentOutput
    {
        $cliPath = config('dashboard.agent.cli_path', 'claude');
        $startTime = microtime(true);

        $command = [
            $cliPath,
            '-p', $prompt,
            '--output-format', 'json',
            '--allowedTools', 'WebSearch', 'WebFetch', 'Read', 'Grep', 'Glob',
        ];

        if ($model) {
            $command[] = '--model';
            $command[] = $model;
        }

        $result = Process::timeout($timeoutSeconds)
            ->path(base_path())
            ->run($command);

        $duration = (int) (microtime(true) - $startTime);

        if (! $result->successful()) {
            Log::error('Claude CLI failed', [
                'exit_code' => $result->exitCode(),
                'stderr' => $result->errorOutput(),
            ]);

            return new AgentOutput(
                success: false,
                rawOutput: $result->output(),
                errorMessage: $result->errorOutput() ?: 'Claude CLI exited with code ' . $result->exitCode(),
                durationSeconds: $duration,
            );
        }

        $rawOutput = $result->output();

        // Try to parse JSON output
        $parsed = json_decode($rawOutput, true);
        $summary = null;
        $tokensUsed = null;

        if (is_array($parsed)) {
            $resultText = $parsed['result'] ?? $parsed['summary'] ?? null;
            if (is_array($resultText)) {
                $resultText = json_encode($resultText, JSON_UNESCAPED_UNICODE);
            }
            $summary = $resultText ? mb_substr($resultText, 0, 2000) : null;

            // Extract token usage
            $usage = $parsed['usage'] ?? [];
            $tokensUsed = ($usage['input_tokens'] ?? 0) + ($usage['output_tokens'] ?? 0);

            // Track cost
            if (! empty($usage)) {
                try {
                    \App\Models\CostEvent::create([
                        'source' => 'agent',
                        'model' => array_key_first($parsed['modelUsage'] ?? ['unknown' => null]),
                        'input_tokens' => $usage['input_tokens'] ?? 0,
                        'output_tokens' => $usage['output_tokens'] ?? 0,
                        'cache_read_tokens' => $usage['cache_read_input_tokens'] ?? 0,
                        'cache_creation_tokens' => $usage['cache_creation_input_tokens'] ?? 0,
                        'cost_usd' => $parsed['total_cost_usd'] ?? null,
                        'occurred_at' => now(),
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('Failed to track agent cost', ['error' => $e->getMessage()]);
                }
            }
        } else {
            // If not JSON, use the raw text as the summary
            $summary = mb_substr($rawOutput, 0, 1000);
        }

        return new AgentOutput(
            success: true,
            rawOutput: $rawOutput,
            summary: $summary,
            tokensUsed: $tokensUsed,
            durationSeconds: $duration,
        );
    }
}
