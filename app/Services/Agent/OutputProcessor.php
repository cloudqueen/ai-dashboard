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

        $body = "# Agent Output: {$ticketTitle} (Run #{$run->id})\n\n";
        $body .= "## Summary\n\n" . ($run->summary ?? 'No summary available.') . "\n\n";
        $body .= "## Full Output\n\n" . ($run->raw_output ?? '') . "\n";

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
            $this->vault->updateFrontmatter($run->ticket_path, [
                'status' => 'review',
                'updated_at' => Carbon::now()->toIso8601String(),
            ]);
        }
    }
}
