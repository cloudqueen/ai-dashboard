<?php

namespace App\Services\Agent;

use App\Models\AgentSkill;
use App\Services\Vault\VaultManager;

class PromptBuilder
{
    public function __construct(
        private VaultManager $vault,
        private SkillLoader $skillLoader,
    ) {}

    /**
     * Build a full prompt for an agent run from a ticket and skill.
     * Returns [prompt, model] — model may be null (use default).
     */
    public function build(string $ticketPath, string $skillName): array
    {
        $note = $this->vault->readNote($ticketPath);

        if (! $note) {
            throw new \RuntimeException("Ticket not found: {$ticketPath}");
        }

        // Try to load skill (vault first, then DB)
        $skill = $this->skillLoader->load($skillName);

        if (! $skill) {
            return [$this->buildGenericPrompt($note->body, $note->frontmatter), null];
        }

        // Gather context from wikilinks + context_links + dependencies
        $context = $this->gatherContext($note);

        if ($skill['source'] === 'vault') {
            $prompt = $this->buildVaultSkillPrompt($skill, $note, $context);
        } else {
            $prompt = $this->buildDbSkillPrompt($skill, $note, $context);
        }

        return [$prompt, $skill['model'] ?? null];
    }

    /**
     * Build prompt using a vault-based SKILL.md.
     */
    private function buildVaultSkillPrompt(array $skill, $note, string $context): string
    {
        $prompt = $skill['system_prompt'];

        // Append skill reference files
        $references = $skill['references'] ?? [];
        if (! empty($references)) {
            $prompt .= "\n\n## Skill-Referenzen\n\n";
            foreach ($references as $ref) {
                $prompt .= "--- {$ref['name']} ---\n{$ref['content']}\n\n";
            }
        }

        // Append the task
        $prompt .= "\n\n## Aufgabe\n\n";
        $prompt .= "### {$note->title()}\n\n{$note->body}\n\n";

        // Append frontmatter
        $prompt .= "### Metadaten\n" . json_encode($note->frontmatter, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

        // Append context
        if ($context) {
            $prompt .= "## Kontext\n\n{$context}\n";
        }

        return $prompt;
    }

    /**
     * Build prompt using a DB-based agent_skill (legacy).
     */
    private function buildDbSkillPrompt(array $skill, $note, string $context): string
    {
        $prompt = $skill['prompt_template'] ?? '';

        // Substitute placeholders
        $prompt = str_replace('{{ticket_content}}', $note->body, $prompt);
        $prompt = str_replace('{{ticket_title}}', $note->title(), $prompt);
        $prompt = str_replace('{{ticket_frontmatter}}', json_encode($note->frontmatter, JSON_PRETTY_PRINT), $prompt);
        $prompt = str_replace('{{context_notes}}', $context, $prompt);
        $prompt = str_replace('{{skill_references}}', '', $prompt);

        // Prepend system prompt
        if ($skill['system_prompt'] ?? null) {
            $prompt = $skill['system_prompt'] . "\n\n" . $prompt;
        }

        return $prompt;
    }

    /**
     * Gather all context: wikilinks + context_links + dependency outputs.
     */
    private function gatherContext($note): string
    {
        $context = '';

        // Wikilinks
        foreach ($note->wikilinks as $link) {
            $linkedPath = $this->vault->resolveWikilink($link['target']);
            if ($linkedPath) {
                $linkedNote = $this->vault->readNote($linkedPath);
                if ($linkedNote) {
                    $context .= "--- Context: {$link['target']} ---\n{$linkedNote->body}\n\n";
                }
            }
        }

        // Explicit context_links
        $contextLinks = $note->frontmatter['context_links'] ?? [];
        foreach ($contextLinks as $linkPath) {
            $linkedNote = $this->vault->readNote($linkPath);
            if ($linkedNote) {
                $label = $linkedNote->title();
                $context .= "--- Context: {$label} ---\n{$linkedNote->body}\n\n";
            }
        }

        // Dependency outputs
        $dependsOn = $note->frontmatter['depends_on'] ?? [];
        foreach ($dependsOn as $depPath) {
            $lastRun = \App\Models\AgentRun::where('ticket_path', $depPath)
                ->where('status', 'completed')
                ->latest('completed_at')
                ->first();
            if ($lastRun) {
                $depNote = $this->vault->readNote($depPath);
                $label = $depNote ? $depNote->title() : $depPath;
                $context .= "--- Dependency output: {$label} ---\n{$lastRun->summary}\n\n";
            }
        }

        return $context;
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
