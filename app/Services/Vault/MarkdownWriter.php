<?php

namespace App\Services\Vault;

use Symfony\Component\Yaml\Yaml;

class MarkdownWriter
{
    /**
     * Serialize frontmatter and body back to a markdown string.
     */
    public function serialize(array $frontmatter, string $body): string
    {
        $content = '';

        if (! empty($frontmatter)) {
            $yaml = Yaml::dump($frontmatter, 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
            $content .= "---\n{$yaml}---\n";
        }

        // Ensure body starts with a newline after frontmatter
        if ($content !== '' && $body !== '' && ! str_starts_with($body, "\n")) {
            $content .= "\n";
        }

        $content .= $body;

        // Ensure file ends with newline
        if (! str_ends_with($content, "\n")) {
            $content .= "\n";
        }

        return $content;
    }

    /**
     * Update only the frontmatter of a file, preserving the body.
     */
    public function updateFrontmatter(string $existingContent, array $newFrontmatter): string
    {
        $parser = new MarkdownParser();
        $parsed = $parser->parse($existingContent);

        $merged = array_merge($parsed->frontmatter, $newFrontmatter);

        return $this->serialize($merged, $parsed->body);
    }
}
