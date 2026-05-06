<?php

namespace App\Services\Agent;

use App\Models\AgentSkill;
use App\Services\Vault\VaultManager;

class SkillLoader
{
    public function __construct(
        private VaultManager $vault,
    ) {}

    /**
     * List all available skills (vault + DB).
     */
    public function listAll(): array
    {
        $skills = [];

        // Vault-based skills
        foreach ($this->listVaultSkills() as $skill) {
            $skills[] = $skill;
        }

        // DB-based skills (only if not overridden by vault)
        $vaultNames = array_column($skills, 'name');
        $dbSkills = AgentSkill::enabled()->get();
        foreach ($dbSkills as $dbSkill) {
            if (! in_array($dbSkill->name, $vaultNames)) {
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
     * Scan the vault skills/ folder for SKILL.md files.
     */
    public function listVaultSkills(): array
    {
        $skillsFolder = config('dashboard.vault.folders.skills', 'skills');
        $basePath = $this->vault->absolutePath($skillsFolder);

        if (! is_dir($basePath)) {
            return [];
        }

        $skills = [];
        $dirs = new \DirectoryIterator($basePath);

        foreach ($dirs as $dir) {
            if (! $dir->isDir() || $dir->isDot()) {
                continue;
            }

            $skillMdPath = $dir->getPathname() . '/SKILL.md';
            if (! file_exists($skillMdPath)) {
                continue;
            }

            $relativePath = $skillsFolder . '/' . $dir->getFilename() . '/SKILL.md';
            $parsed = $this->vault->readNote($relativePath);

            if (! $parsed) {
                continue;
            }

            $fm = $parsed->frontmatter;
            $skills[] = [
                'name' => $fm['name'] ?? $dir->getFilename(),
                'display_name' => $fm['display_name'] ?? ucfirst($fm['name'] ?? $dir->getFilename()),
                'description' => $fm['description'] ?? '',
                'source' => 'vault',
                'folder' => $dir->getFilename(),
                'model' => $fm['model'] ?? null,
                'max_context_files' => $fm['max_context_files'] ?? 10,
                'references' => $this->listReferences($dir->getPathname(), $skillsFolder . '/' . $dir->getFilename()),
            ];
        }

        return $skills;
    }

    /**
     * Load a skill by name. Tries vault first, then DB.
     */
    public function load(string $name): ?array
    {
        // Try vault first
        $vaultSkill = $this->loadFromVault($name);
        if ($vaultSkill) {
            return $vaultSkill;
        }

        // Fallback to DB
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

    /**
     * Load a vault skill with its SKILL.md body as system prompt + references.
     */
    private function loadFromVault(string $name): ?array
    {
        $skillsFolder = config('dashboard.vault.folders.skills', 'skills');

        // Try direct folder name match
        $candidates = [$name];

        // Also try with different casings
        $basePath = $this->vault->absolutePath($skillsFolder);
        if (is_dir($basePath)) {
            foreach (new \DirectoryIterator($basePath) as $dir) {
                if ($dir->isDir() && ! $dir->isDot()) {
                    $fm = $this->getSkillFrontmatter($skillsFolder . '/' . $dir->getFilename());
                    if (($fm['name'] ?? $dir->getFilename()) === $name) {
                        $candidates = [$dir->getFilename()];
                        break;
                    }
                }
            }
        }

        foreach ($candidates as $folder) {
            $skillMdPath = $skillsFolder . '/' . $folder . '/SKILL.md';
            $parsed = $this->vault->readNote($skillMdPath);

            if (! $parsed) {
                continue;
            }

            $fm = $parsed->frontmatter;
            $folderPath = $this->vault->absolutePath($skillsFolder . '/' . $folder);
            $references = $this->loadReferenceContents($folderPath, $fm['max_context_files'] ?? 10);

            return [
                'name' => $fm['name'] ?? $folder,
                'source' => 'vault',
                'system_prompt' => $parsed->body,
                'prompt_template' => null,
                'model' => $fm['model'] ?? null,
                'references' => $references,
                'frontmatter' => $fm,
            ];
        }

        return null;
    }

    /**
     * Get frontmatter from a skill folder's SKILL.md.
     */
    private function getSkillFrontmatter(string $relativeFolder): array
    {
        $parsed = $this->vault->readNote($relativeFolder . '/SKILL.md');
        return $parsed ? $parsed->frontmatter : [];
    }

    /**
     * List reference files in a skill folder (everything except SKILL.md).
     */
    private function listReferences(string $absoluteFolder, string $relativeFolder): array
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
            $refs[] = [
                'path' => $relativeFolder . '/' . $relativePath,
                'name' => $relativePath,
            ];
        }

        return $refs;
    }

    /**
     * Load contents of reference files for prompt injection.
     */
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
     * Create a new skill in the vault with a template SKILL.md.
     */
    public function create(string $name, string $description = '', string $body = ''): string
    {
        $skillsFolder = config('dashboard.vault.folders.skills', 'skills');
        $folderPath = $this->vault->absolutePath($skillsFolder . '/' . $name);

        if (! is_dir($folderPath)) {
            mkdir($folderPath, 0755, true);
        }

        $frontmatter = [
            'name' => $name,
            'description' => $description,
        ];

        if (! $body) {
            $body = "# {$name}\n\nBeschreibe hier die Fähigkeiten und Anweisungen für diesen Skill.\n\n## Workflow\n\n## Output-Format\n";
        }

        $relativePath = $skillsFolder . '/' . $name . '/SKILL.md';
        $this->vault->createNote($skillsFolder . '/' . $name, 'SKILL', $frontmatter, $body);

        return $relativePath;
    }
}
