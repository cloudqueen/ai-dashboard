<?php

namespace App\Services\Vault;

class ParsedNote
{
    public function __construct(
        public readonly array $frontmatter,
        public readonly string $body,
        public readonly array $wikilinks,
        public readonly string $rawContent,
    ) {}

    public function title(): string
    {
        return $this->frontmatter['title']
            ?? $this->extractFirstHeading()
            ?? 'Untitled';
    }

    private function extractFirstHeading(): ?string
    {
        if (preg_match('/^#\s+(.+)$/m', $this->body, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }
}
