<?php

namespace App\Services\Agent;

use App\Models\AgentSkill;
use App\Services\Vault\VaultManager;

class PromptBuilder
{
    public function __construct(
        private VaultManager $vault,
    ) {}

    /**
     * Build a full prompt for an agent run from a ticket and skill.
     */
    public function build(string $ticketPath, string $skillName): string
    {
        $note = $this->vault->readNote($ticketPath);

        if (! $note) {
            throw new \RuntimeException("Ticket not found: {$ticketPath}");
        }

        $skill = AgentSkill::where('name', $skillName)->enabled()->first();

        if (! $skill) {
            // Fallback to a generic prompt if skill not found
            return $this->buildGenericPrompt($note->body, $note->frontmatter);
        }

        $prompt = $skill->prompt_template;

        // Substitute placeholders
        $prompt = str_replace('{{ticket_content}}', $note->body, $prompt);
        $prompt = str_replace('{{ticket_title}}', $note->title(), $prompt);
        $prompt = str_replace('{{ticket_frontmatter}}', json_encode($note->frontmatter, JSON_PRETTY_PRINT), $prompt);

        // Gather context notes from wikilinks
        $context = '';
        foreach ($note->wikilinks as $link) {
            $linkedPath = $this->vault->resolveWikilink($link['target']);
            if ($linkedPath) {
                $linkedNote = $this->vault->readNote($linkedPath);
                if ($linkedNote) {
                    $context .= "--- Context: {$link['target']} ---\n{$linkedNote->body}\n\n";
                }
            }
        }

        $prompt = str_replace('{{context_notes}}', $context, $prompt);

        // Prepend system prompt
        if ($skill->system_prompt) {
            $prompt = $skill->system_prompt . "\n\n" . $prompt;
        }

        return $prompt;
    }

    private function buildGenericPrompt(string $body, array $frontmatter): string
    {
        $type = $frontmatter['type'] ?? 'task';

        return "You are an AI assistant helping with a {$type}. "
            . "Read the following task description and complete it thoroughly.\n\n"
            . "## Task\n\n{$body}\n\n"
            . "Provide your complete response. Be thorough and actionable.";
    }
}
