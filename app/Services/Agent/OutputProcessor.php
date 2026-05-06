<?php

namespace App\Services\Agent;

use App\Models\AgentRun;
use App\Services\TicketService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class OutputProcessor
{
    public function __construct(
        private TicketService $tickets,
    ) {}

    /**
     * Process a completed agent run: write markdown to local storage,
     * record path on the run, move the source ticket to review.
     */
    public function process(AgentRun $run): void
    {
        $ticket = $run->ticket;
        $title = $ticket?->title ?? "Run #{$run->id}";
        $slug = Str::slug($title);
        $date = Carbon::now()->format('Y-m-d');

        $cleanOutput = $run->summary ?? '';
        if ($run->raw_output) {
            $parsed = json_decode($run->raw_output, true);
            if (is_array($parsed) && isset($parsed['result'])) {
                $cleanOutput = $parsed['result'];
            } elseif (! is_array($parsed)) {
                $cleanOutput = $run->raw_output;
            }
        }

        $body = "# Agent Output: {$title}\n\n";
        $body .= "- Skill: {$run->skill}\n";
        $body .= "- Run: #{$run->id}\n";
        if ($ticket) {
            $body .= "- Ticket: #{$ticket->id} ({$ticket->status})\n";
        }
        $body .= "- Dauer: {$run->duration_seconds}s\n\n";
        $body .= "---\n\n";
        $body .= $cleanOutput . "\n";

        $relativePath = "dashboard/agent-outputs/{$date}-{$run->id}-{$slug}.md";
        Storage::disk('local')->put($relativePath, $body);

        $run->update(['output_note_path' => $relativePath]);

        if ($ticket) {
            $this->tickets->move($ticket, 'review');
        }
    }
}
