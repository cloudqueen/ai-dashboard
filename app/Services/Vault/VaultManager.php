<?php

namespace App\Services\Vault;

use App\Models\VaultNote;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class VaultManager
{
    private string $vaultPath;
    private MarkdownParser $parser;
    private MarkdownWriter $writer;

    public function __construct(MarkdownParser $parser, MarkdownWriter $writer)
    {
        $this->vaultPath = rtrim(config('dashboard.vault.path', ''), '/');
        $this->parser = $parser;
        $this->writer = $writer;
    }

    /**
     * Check if vault path is configured and accessible.
     */
    public function isConfigured(): bool
    {
        return $this->vaultPath !== '' && is_dir($this->vaultPath);
    }

    /**
     * Get the absolute path for a relative vault path.
     */
    public function absolutePath(string $relativePath): string
    {
        return $this->vaultPath . '/' . ltrim($relativePath, '/');
    }

    /**
     * Read and parse a vault note.
     */
    public function readNote(string $relativePath): ?ParsedNote
    {
        $fullPath = $this->absolutePath($relativePath);

        if (! file_exists($fullPath)) {
            return null;
        }

        return $this->parser->parseFile($fullPath);
    }

    /**
     * Write a note to the vault with atomic file operations.
     */
    public function writeNote(string $relativePath, array $frontmatter, string $body): void
    {
        $fullPath = $this->absolutePath($relativePath);
        $dir = dirname($fullPath);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $content = $this->writer->serialize($frontmatter, $body);

        $this->atomicWrite($fullPath, $content);

        // Re-index this note
        $this->indexNote($relativePath);
    }

    /**
     * Update only the frontmatter of an existing note.
     */
    public function updateFrontmatter(string $relativePath, array $frontmatter): void
    {
        $fullPath = $this->absolutePath($relativePath);

        if (! file_exists($fullPath)) {
            throw new \RuntimeException("Note not found: {$relativePath}");
        }

        $existingContent = file_get_contents($fullPath);
        $newContent = $this->writer->updateFrontmatter($existingContent, $frontmatter);

        $this->atomicWrite($fullPath, $newContent);
        $this->indexNote($relativePath);
    }

    /**
     * List all markdown files in a vault folder.
     */
    public function listFiles(?string $folder = null): Collection
    {
        $basePath = $folder ? $this->absolutePath($folder) : $this->vaultPath;

        if (! is_dir($basePath)) {
            return collect();
        }

        $files = collect();
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($basePath, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'md') {
                continue;
            }

            $relativePath = str_replace($this->vaultPath . '/', '', $file->getPathname());

            // Skip .obsidian directory
            if (str_starts_with($relativePath, '.obsidian/')) {
                continue;
            }

            $files->push($relativePath);
        }

        return $files;
    }

    /**
     * Index a single vault note into the database.
     */
    public function indexNote(string $relativePath): ?VaultNote
    {
        $fullPath = $this->absolutePath($relativePath);

        if (! file_exists($fullPath)) {
            VaultNote::where('relative_path', $relativePath)->delete();
            return null;
        }

        $content = file_get_contents($fullPath);
        $parsed = $this->parser->parse($content);
        $hash = hash('sha256', $content);

        return VaultNote::updateOrCreate(
            ['relative_path' => $relativePath],
            [
                'title' => $parsed->title() !== 'Untitled'
                    ? $parsed->title()
                    : pathinfo($relativePath, PATHINFO_FILENAME),
                'vault_folder' => dirname($relativePath),
                'frontmatter' => $parsed->frontmatter,
                'body_preview' => Str::limit($parsed->body, 500),
                'content_hash' => $hash,
                'vault_modified_at' => \Carbon\Carbon::createFromTimestamp(filemtime($fullPath)),
            ]
        );
    }

    /**
     * Full re-index of the vault.
     */
    public function indexAll(bool $changedOnly = false): int
    {
        $files = $this->listFiles();
        $count = 0;

        foreach ($files as $relativePath) {
            if ($changedOnly) {
                $existing = VaultNote::where('relative_path', $relativePath)->first();
                if ($existing) {
                    $currentHash = hash('sha256', file_get_contents($this->absolutePath($relativePath)));
                    if ($existing->content_hash === $currentHash) {
                        continue;
                    }
                }
            }

            $this->indexNote($relativePath);
            $count++;
        }

        // Remove entries for deleted files
        $allPaths = $files->toArray();
        $deleted = VaultNote::whereNotIn('relative_path', $allPaths)->delete();

        if ($deleted > 0) {
            Log::info("Vault index: removed {$deleted} stale entries");
        }

        return $count;
    }

    /**
     * Search notes by title or body content.
     */
    public function search(string $query): Collection
    {
        return VaultNote::where('title', 'LIKE', "%{$query}%")
            ->orWhere('body_preview', 'LIKE', "%{$query}%")
            ->orderBy('vault_modified_at', 'desc')
            ->limit(50)
            ->get();
    }

    /**
     * Resolve a [[wikilink]] to a relative path.
     */
    public function resolveWikilink(string $wikilink): ?string
    {
        // First try exact match
        $note = VaultNote::where('title', $wikilink)->first();
        if ($note) {
            return $note->relative_path;
        }

        // Try matching filename without extension
        $note = VaultNote::where('relative_path', 'LIKE', "%/{$wikilink}.md")
            ->orWhere('relative_path', "{$wikilink}.md")
            ->first();

        return $note?->relative_path;
    }

    /**
     * Create a new note with a slugified filename.
     */
    public function createNote(string $folder, string $title, array $frontmatter, string $body): string
    {
        $slug = Str::slug($title);
        $relativePath = trim($folder, '/') . '/' . $slug . '.md';

        // Avoid overwriting existing files
        $counter = 1;
        while (file_exists($this->absolutePath($relativePath))) {
            $relativePath = trim($folder, '/') . '/' . $slug . '-' . $counter . '.md';
            $counter++;
        }

        $this->writeNote($relativePath, $frontmatter, $body);

        return $relativePath;
    }

    /**
     * Write content to a file atomically using a temp file + rename.
     */
    private function atomicWrite(string $fullPath, string $content): void
    {
        $lockFile = $this->vaultPath . '/.dashboard-lock';
        $lockHandle = fopen($lockFile, 'c');

        if (! $lockHandle || ! flock($lockHandle, LOCK_EX)) {
            throw new \RuntimeException('Could not acquire vault file lock');
        }

        try {
            $tmpDir = $this->vaultPath . '/.tmp';
            if (! is_dir($tmpDir)) {
                mkdir($tmpDir, 0755, true);
            }

            $tmpFile = $tmpDir . '/' . uniqid('vault_', true);
            file_put_contents($tmpFile, $content);
            rename($tmpFile, $fullPath);
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }
}
