<?php

namespace App\Services\Agent;

use App\Models\AgentRun;
use App\Services\Vault\VaultManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class OutputProcessor
{
    public function __construct(
        private VaultManager $vault,
    ) {}

    /**
     * Process a completed agent run: write output to vault, update ticket.
     */
    public function process(AgentRun $run): void
    {
        $outputFolder = config('dashboard.vault.folders.agent_outputs', 'agent/outputs');
        $ticketTitle = $run->vaultNote?->title ?? Str::slug(basename($run->ticket_path ?? 'unknown', '.md'));
        $date = Carbon::now()->format('Y-m-d');

        $frontmatter = [
            'type' => 'agent_output',
            'source_ticket' => $run->ticket_path ? '[[' . basename($run->ticket_path, '.md') . ']]' : null,
            'agent_skill' => $run->skill,
            'agent_run_id' => $run->id,
            'status' => 'completed',
            'duration_seconds' => $run->duration_seconds,
            'created_at' => Carbon::now()->toIso8601String(),
        ];

        // Extract clean text from raw output (may be JSON)
        $cleanOutput = $run->summary ?? '';
        if ($run->raw_output) {
            $parsed = json_decode($run->raw_output, true);
            if (is_array($parsed) && isset($parsed['result'])) {
                $cleanOutput = $parsed['result'];
            } elseif (! is_array($parsed)) {
                // raw_output is plain text
                $cleanOutput = $run->raw_output;
            }
        }

        $body = "# Agent Output: {$ticketTitle}\n\n";
        $body .= "- Skill: {$run->skill}\n";
        $body .= "- Run: #{$run->id}\n";
        $body .= "- Dauer: {$run->duration_seconds}s\n\n";
        $body .= "---\n\n";
        $body .= $cleanOutput . "\n";

        $relativePath = $this->vault->createNote(
            $outputFolder,
            "{$date}-{$ticketTitle}-run-{$run->id}",
            $frontmatter,
            $body
        );

        // Update the agent run with the output path
        $run->update(['output_note_path' => $relativePath]);

        // Update the source ticket to link the output and move to review
        if ($run->ticket_path) {
            try {
                $this->vault->updateFrontmatter($run->ticket_path, [
                    'status' => 'review',
                    'agent_output' => '[[' . basename($relativePath, '.md') . ']]',
                    'updated_at' => Carbon::now()->toIso8601String(),
                ]);
                // Ensure the DB index is updated too
                $this->vault->indexNote($run->ticket_path);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('Failed to update ticket after agent run', [
                    'run_id' => $run->id,
                    'ticket' => $run->ticket_path,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
