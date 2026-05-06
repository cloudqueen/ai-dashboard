<?php

namespace App\Services\Agent;

use App\Models\AgentSkill;
use App\Services\Vault\MarkdownParser;
use Illuminate\Support\Facades\Storage;

class SkillLoader
{
    public function __construct(
        private MarkdownParser $parser,
    ) {}

    private function basePath(): string
    {
        return Storage::disk('local')->path(config('dashboard.storage.skills', 'dashboard/skills'));
    }

    /**
     * List all available skills (storage folders + DB).
     */
    public function listAll(): array
    {
        $skills = [];

        foreach ($this->listFileSkills() as $skill) {
            $skills[] = $skill;
        }

        $fileNames = array_column($skills, 'name');
        foreach (AgentSkill::enabled()->get() as $dbSkill) {
            if (! in_array($dbSkill->name, $fileNames, true)) {
                $skills[] = [
                    'name' => $dbSkill->name,
                    'display_name' => $dbSkill->display_name,
                    'description' => $dbSkill->description,
                    'source' => 'database',
                    'model' => null,
                    'references' => [],
                ];
            }
        }

        return $skills;
    }

    /**
     * Scan the skills storage folder for SKILL.md files.
     */
    public function listFileSkills(): array
    {
        $basePath = $this->basePath();
        if (! is_dir($basePath)) {
            return [];
        }

        $skills = [];

        foreach (new \DirectoryIterator($basePath) as $dir) {
            if (! $dir->isDir() || $dir->isDot()) {
                continue;
            }

            $skillMdPath = $dir->getPathname() . '/SKILL.md';
            if (! file_exists($skillMdPath)) {
                continue;
            }

            $parsed = $this->parser->parse(file_get_contents($skillMdPath));
            $fm = $parsed->frontmatter;

            $skills[] = [
                'name' => $fm['name'] ?? $dir->getFilename(),
                'display_name' => $fm['display_name'] ?? ucfirst($fm['name'] ?? $dir->getFilename()),
                'description' => $fm['description'] ?? '',
                'source' => 'file',
                'folder' => $dir->getFilename(),
                'model' => $fm['model'] ?? null,
                'max_context_files' => $fm['max_context_files'] ?? 10,
                'references' => $this->listReferences($dir->getPathname()),
            ];
        }

        return $skills;
    }

    /**
     * Load a skill by name. Tries storage first, then DB.
     */
    public function load(string $name): ?array
    {
        $fileSkill = $this->loadFromStorage($name);
        if ($fileSkill) {
            return $fileSkill;
        }

        $dbSkill = AgentSkill::where('name', $name)->enabled()->first();
        if ($dbSkill) {
            return [
                'name' => $dbSkill->name,
                'source' => 'database',
                'system_prompt' => $dbSkill->system_prompt,
                'prompt_template' => $dbSkill->prompt_template,
                'model' => null,
                'references' => [],
            ];
        }

        return null;
    }

    private function loadFromStorage(string $name): ?array
    {
        $basePath = $this->basePath();
        if (! is_dir($basePath)) {
            return null;
        }

        // Direct folder match, then frontmatter name match
        $candidates = [$name];
        foreach (new \DirectoryIterator($basePath) as $dir) {
            if ($dir->isDir() && ! $dir->isDot()) {
                $skillMd = $dir->getPathname() . '/SKILL.md';
                if (file_exists($skillMd)) {
                    $fm = $this->parser->parse(file_get_contents($skillMd))->frontmatter;
                    if (($fm['name'] ?? $dir->getFilename()) === $name) {
                        $candidates = [$dir->getFilename()];
                        break;
                    }
                }
            }
        }

        foreach ($candidates as $folder) {
            $folderPath = $basePath . '/' . $folder;
            $skillMd = $folderPath . '/SKILL.md';
            if (! file_exists($skillMd)) {
                continue;
            }

            $parsed = $this->parser->parse(file_get_contents($skillMd));
            $fm = $parsed->frontmatter;
            $references = $this->loadReferenceContents($folderPath, $fm['max_context_files'] ?? 10);

            return [
                'name' => $fm['name'] ?? $folder,
                'source' => 'file',
                'system_prompt' => $parsed->body,
                'prompt_template' => null,
                'model' => $fm['model'] ?? null,
                'references' => $references,
                'frontmatter' => $fm,
            ];
        }

        return null;
    }

    private function listReferences(string $absoluteFolder): array
    {
        $refs = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($absoluteFolder, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            $filename = $file->getFilename();
            if ($filename === 'SKILL.md' || str_starts_with($filename, '.')) {
                continue;
            }
            if (! in_array($file->getExtension(), ['md', 'txt', 'json', 'yaml', 'yml'])) {
                continue;
            }

            $relativePath = str_replace($absoluteFolder . '/', '', $file->getPathname());
            $refs[] = ['path' => $relativePath, 'name' => $relativePath];
        }

        return $refs;
    }

    private function loadReferenceContents(string $absoluteFolder, int $maxFiles): array
    {
        $refs = [];
        $count = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($absoluteFolder, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if ($count >= $maxFiles) break;
            $filename = $file->getFilename();
            if ($filename === 'SKILL.md' || str_starts_with($filename, '.')) {
                continue;
            }
            if (! in_array($file->getExtension(), ['md', 'txt', 'json', 'yaml', 'yml'])) {
                continue;
            }

            $content = file_get_contents($file->getPathname());
            if ($content !== false) {
                $refs[] = [
                    'name' => str_replace($absoluteFolder . '/', '', $file->getPathname()),
                    'content' => $content,
                ];
                $count++;
            }
        }

        return $refs;
    }

    /**
     * Create a new skill folder with a template SKILL.md.
     */
    public function create(string $name, string $description = '', string $body = ''): string
    {
        $basePath = $this->basePath();
        $folderPath = $basePath . '/' . $name;

        if (! is_dir($folderPath)) {
            mkdir($folderPath, 0755, true);
        }

        if (! $body) {
            $body = "# {$name}\n\nBeschreibe hier die Fähigkeiten und Anweisungen für diesen Skill.\n\n## Workflow\n\n## Output-Format\n";
        }

        $frontmatter = "---\nname: {$name}\ndescription: " . str_replace("\n", ' ', $description) . "\n---\n\n";
        file_put_contents($folderPath . '/SKILL.md', $frontmatter . $body);

        return 'dashboard/skills/' . $name . '/SKILL.md';
    }
}
