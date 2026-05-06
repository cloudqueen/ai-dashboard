<?php

namespace App\Services\Agent;

use App\Models\AgentRun;
use App\Models\Ticket;
use App\Services\Vault\VaultManager;

class PromptBuilder
{
    public function __construct(
        private VaultManager $vault,
        private SkillLoader $skillLoader,
    ) {}

    /**
     * Build a full prompt for an agent run.
     * Returns [prompt, model] — model may be null (use default).
     */
    public function build(int $ticketId, string $skillName): array
    {
        $ticket = Ticket::findOrFail($ticketId);

        $skill = $this->skillLoader->load($skillName);

        if (! $skill) {
            return [$this->buildGenericPrompt($ticket), null];
        }

        $context = $this->gatherContext($ticket);

        $prompt = $skill['source'] === 'vault'
            ? $this->buildVaultSkillPrompt($skill, $ticket, $context)
            : $this->buildDbSkillPrompt($skill, $ticket, $context);

        return [$prompt, $skill['model'] ?? null];
    }

    private function buildVaultSkillPrompt(array $skill, Ticket $ticket, string $context): string
    {
        $prompt = $skill['system_prompt'];

        $references = $skill['references'] ?? [];
        if (! empty($references)) {
            $prompt .= "\n\n## Skill-Referenzen\n\n";
            foreach ($references as $ref) {
                $prompt .= "--- {$ref['name']} ---\n{$ref['content']}\n\n";
            }
        }

        $prompt .= "\n\n## Aufgabe\n\n";
        $prompt .= "### {$ticket->title}\n\n" . ($ticket->description ?? '') . "\n\n";

        $prompt .= "### Metadaten\n" . json_encode($this->ticketMeta($ticket), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

        if ($context) {
            $prompt .= "## Kontext\n\n{$context}\n";
        }

        return $prompt;
    }

    private function buildDbSkillPrompt(array $skill, Ticket $ticket, string $context): string
    {
        $prompt = $skill['prompt_template'] ?? '';

        $prompt = str_replace('{{ticket_content}}', $ticket->description ?? '', $prompt);
        $prompt = str_replace('{{ticket_title}}', $ticket->title, $prompt);
        $prompt = str_replace('{{ticket_frontmatter}}', json_encode($this->ticketMeta($ticket), JSON_PRETTY_PRINT), $prompt);
        $prompt = str_replace('{{context_notes}}', $context, $prompt);
        $prompt = str_replace('{{skill_references}}', '', $prompt);

        if ($skill['system_prompt'] ?? null) {
            $prompt = $skill['system_prompt'] . "\n\n" . $prompt;
        }

        return $prompt;
    }

    /**
     * Gather context from context_links (vault notes) + dependency outputs (other tickets' last agent run).
     */
    private function gatherContext(Ticket $ticket): string
    {
        $context = '';

        foreach ($ticket->context_links ?? [] as $linkPath) {
            $note = $this->vault->readNote($linkPath);
            if ($note) {
                $label = $note->title();
                $context .= "--- Context: {$label} ---\n{$note->body}\n\n";
            }
        }

        foreach ($ticket->depends_on ?? [] as $depTicketId) {
            $lastRun = AgentRun::where('ticket_id', $depTicketId)
                ->where('status', 'completed')
                ->latest('completed_at')
                ->first();
            if ($lastRun) {
                $depTicket = Ticket::find($depTicketId);
                $label = $depTicket?->title ?? "Ticket #{$depTicketId}";
                $context .= "--- Dependency output: {$label} ---\n{$lastRun->summary}\n\n";
            }
        }

        return $context;
    }

    private function ticketMeta(Ticket $ticket): array
    {
        return [
            'type' => $ticket->type,
            'status' => $ticket->status,
            'priority' => $ticket->priority,
            'tags' => $ticket->tags,
            'due_date' => $ticket->due_date?->toDateString(),
            'emotional_charge' => $ticket->emotional_charge,
            'system_level' => $ticket->system_level,
        ];
    }

    private function buildGenericPrompt(Ticket $ticket): string
    {
        return "You are an AI assistant helping with a {$ticket->type}. "
            . "Read the following task description and complete it thoroughly.\n\n"
            . "## Task\n\n### {$ticket->title}\n\n" . ($ticket->description ?? '') . "\n\n"
            . "Provide your complete response. Be thorough and actionable.";
    }
}
