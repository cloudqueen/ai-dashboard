<?php

namespace App\Services\Agent;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class ClaudeRunner
{
    /**
     * Execute a prompt via the Claude CLI.
     */
    public function run(string $prompt, int $timeoutSeconds = 1800): AgentOutput
    {
        $cliPath = config('dashboard.agent.cli_path', 'claude');
        $startTime = microtime(true);

        $result = Process::timeout($timeoutSeconds)
            ->run([
                $cliPath,
                '-p', $prompt,
                '--output-format', 'json',
            ]);

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

        if (is_array($parsed)) {
            $summary = $parsed['result'] ?? $parsed['summary'] ?? null;
            if (is_array($summary)) {
                $summary = json_encode($summary);
            }
        } else {
            // If not JSON, use the raw text as the summary
            $summary = mb_substr($rawOutput, 0, 1000);
        }

        return new AgentOutput(
            success: true,
            rawOutput: $rawOutput,
            summary: $summary,
            durationSeconds: $duration,
        );
    }
}
