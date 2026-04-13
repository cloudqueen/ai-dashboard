<?php

namespace App\Services\Vault;

use Symfony\Component\Yaml\Yaml;

class MarkdownParser
{
    /**
     * Parse a markdown file with optional YAML frontmatter and wikilinks.
     */
    public function parse(string $content): ParsedNote
    {
        $frontmatter = [];
        $body = $content;

        // Extract YAML frontmatter between --- delimiters
        if (preg_match('/\A---\r?\n(.+?)\r?\n---\r?\n?(.*)\z/s', $content, $matches)) {
            try {
                $frontmatter = Yaml::parse($matches[1]) ?? [];
            } catch (\Exception) {
                $frontmatter = [];
            }
            $body = $matches[2];
        }

        // Extract [[wikilinks]]
        $wikilinks = $this->extractWikilinks($body);

        return new ParsedNote(
            frontmatter: $frontmatter,
            body: $body,
            wikilinks: $wikilinks,
            rawContent: $content,
        );
    }

    /**
     * Parse a file from disk.
     */
    public function parseFile(string $filePath): ParsedNote
    {
        $content = file_get_contents($filePath);

        if ($content === false) {
            throw new \RuntimeException("Cannot read file: {$filePath}");
        }

        return $this->parse($content);
    }

    /**
     * Extract all [[wikilinks]] from body text.
     * Supports [[link]] and [[link|display text]] syntax.
     */
    private function extractWikilinks(string $text): array
    {
        $links = [];

        if (preg_match_all('/\[\[([^\]]+)\]\]/', $text, $matches)) {
            foreach ($matches[1] as $match) {
                $parts = explode('|', $match, 2);
                $links[] = [
                    'target' => trim($parts[0]),
                    'display' => trim($parts[1] ?? $parts[0]),
                ];
            }
        }

        return $links;
    }
}
