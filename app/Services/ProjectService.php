<?php

namespace App\Services;

use App\Models\CostEvent;
use App\Models\Project;
use App\Models\ProjectRun;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

class ProjectService
{
    public function __construct(
        private TicketService $tickets,
    ) {}

    /**
     * Create a new project. Validates that path is a git repo with at least one commit,
     * detects the default branch, and creates the canonical state folder.
     */
    public function create(array $data): Project
    {
        $path = realpath($data['path']) ?: $data['path'];
        $this->assertWithinAllowedRoots($path);

        if (! is_dir($path . '/.git')) {
            throw new \RuntimeException("Path is not a git repository: {$path}");
        }

        if (! $this->hasAnyCommit($path)) {
            throw new \RuntimeException(
                "Repository has no commits yet. Make at least one commit before adding it as a project: {$path}"
            );
        }

        $defaultBranch = $data['default_branch'] ?? $this->detectDefaultBranch($path);

        $project = Project::create([
            'name' => $data['name'],
            'path' => $path,
            'status' => 'active',
            'default_branch' => $defaultBranch,
            'allowed_tools' => $data['allowed_tools'] ?? 'all',
            'model' => $data['model'] ?? null,
            'max_run_minutes' => $data['max_run_minutes'] ?? 30,
            'max_turns' => $data['max_turns'] ?? 50,
            'nightly_enabled' => $data['nightly_enabled'] ?? true,
            'nightly_schedule' => $data['nightly_schedule'] ?? '0 2 * * *',
        ]);

        $this->setupStateFolder($project);

        return $project;
    }

    /**
     * Run the nightly agent for one project. Always returns a ProjectRun
     * (with status=failed/timed_out on errors).
     */
    public function runNightly(Project $project): ProjectRun
    {
        $run = ProjectRun::create([
            'project_id' => $project->id,
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $this->doRun($project, $run);
            $project->update([
                'last_run_at' => now(),
                'consecutive_failures' => 0,
            ]);
        } catch (ProcessTimedOutException $e) {
            $run->update([
                'status' => 'timed_out',
                'completed_at' => now(),
                'error_message' => $e->getMessage(),
                'duration_seconds' => $this->elapsed($run),
            ]);
            $this->markFailure($project);
        } catch (\Throwable $e) {
            $run->update([
                'status' => 'failed',
                'completed_at' => now(),
                'error_message' => $e->getMessage(),
                'duration_seconds' => $this->elapsed($run),
            ]);
            $this->markFailure($project);
            Log::error('Nightly project run failed', [
                'project' => $project->name,
                'run_id' => $run->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $run->fresh();
    }

    private function doRun(Project $project, ProjectRun $run): void
    {
        // 1. Read canonical nightly.md
        $nightlyContent = $project->readNightly();
        if (trim($nightlyContent) === '') {
            $run->update([
                'status' => 'completed',
                'completed_at' => now(),
                'summary' => 'nightly.md ist leer — keine Aufgaben.',
                'duration_seconds' => $this->elapsed($run),
            ]);
            return;
        }

        // 2. Create worktree on a new branch
        $branchName = 'nightly/' . now()->format('Y-m-d') . '-' . $run->id;
        $worktreePath = $project->path . '/.nightly/' . now()->format('Y-m-d') . '-' . $run->id;
        $base = $this->resolveBranchBase($project);

        $this->git($project->path, ['worktree', 'add', $worktreePath, '-b', $branchName, $base]);

        $run->update([
            'branch_name' => $branchName,
            'worktree_path' => $worktreePath,
        ]);

        // 3. Locally exclude .dashboard/ in this worktree (so claude can't accidentally commit it)
        $excludePath = trim($this->git($worktreePath, ['rev-parse', '--git-path', 'info/exclude']));
        if ($excludePath && is_file($excludePath)) {
            file_put_contents($excludePath, "\n.dashboard/\n", FILE_APPEND);
        }

        // 4. Seed worktree's .dashboard/ with the nightly tasks
        $worktreeDashboard = $worktreePath . '/.dashboard';
        @mkdir($worktreeDashboard, 0755, true);
        file_put_contents("$worktreeDashboard/nightly.md", $nightlyContent);
        file_put_contents("$worktreeDashboard/review.md", '');

        // 5. Build briefing + run claude -p
        $briefing = $this->buildBriefing($project);
        $run->update(['prompt' => $briefing]);

        $cli = config('dashboard.agent.cli_path', 'claude');
        $cmd = [$cli, '-p', $briefing, '--output-format', 'json',
                '--max-turns', (string) $project->max_turns,
                '--dangerously-skip-permissions'];

        if ($project->allowed_tools !== 'all' && $project->allowed_tools !== '') {
            $cmd[] = '--allowed-tools';
            $cmd[] = $project->allowed_tools;
        }
        if ($project->model) {
            $cmd[] = '--model';
            $cmd[] = $project->model;
        }

        $result = Process::path($worktreePath)
            ->timeout($project->max_run_minutes * 60)
            ->run($cmd);

        if (! $result->successful()) {
            throw new \RuntimeException(
                'claude -p exited with code ' . $result->exitCode() . ': ' . $result->errorOutput()
            );
        }

        $output = $result->output();
        $parsed = json_decode($output, true) ?: [];

        $tokens = (int) (($parsed['usage']['input_tokens'] ?? 0) + ($parsed['usage']['output_tokens'] ?? 0));
        $summary = $parsed['result'] ?? '';

        // 6. Read worktree's review.md and updated nightly.md
        $reviewMd = is_file("$worktreeDashboard/review.md") ? (string) file_get_contents("$worktreeDashboard/review.md") : '';
        $newNightlyMd = is_file("$worktreeDashboard/nightly.md") ? (string) file_get_contents("$worktreeDashboard/nightly.md") : '';

        // 7. Write back to canonical state
        $project->writeReview($reviewMd);
        $project->writeNightly($newNightlyMd);

        // 8. Append run log
        $logFile = $project->statePath() . '/log/' . now()->format('Y-m-d') . '-' . $run->id . '.md';
        @mkdir(dirname($logFile), 0755, true);
        file_put_contents($logFile, sprintf(
            "# Run #%d — %s\n\nBranch: `%s`\nWorktree: `%s`\nDuration: %ds\nTokens: %d\n\n## Summary\n\n%s\n\n## Review\n\n%s\n",
            $run->id,
            $run->started_at->toDateTimeString(),
            $branchName,
            $worktreePath,
            $this->elapsed($run),
            $tokens,
            $summary,
            $reviewMd
        ));

        // 9. Create review ticket if non-empty
        $reviewTicket = null;
        if (trim($reviewMd) !== '') {
            $description = "**Branch:** `{$branchName}`\n**Worktree:** `{$worktreePath}`\n\n---\n\n"
                . mb_substr($reviewMd, 0, 4000)
                . (mb_strlen($reviewMd) > 4000 ? "\n\n…(gekürzt — voller Inhalt in `{$project->reviewMdPath()}`)" : '');

            $reviewTicket = $this->tickets->create([
                'title' => "Review: {$project->name} — " . now()->format('Y-m-d'),
                'description' => $description,
                'type' => 'task',
                'status' => 'review',
                'priority' => 'medium',
                'assigned_to' => 'human',
                'tags' => [$project->name],
            ]);
            $reviewTicket->update(['project_id' => $project->id]);
        }

        $run->update([
            'status' => 'completed',
            'completed_at' => now(),
            'raw_output' => $output,
            'summary' => $summary,
            'tokens_used' => $tokens,
            'duration_seconds' => $this->elapsed($run),
            'review_ticket_id' => $reviewTicket?->id,
        ]);

        // Cost tracking — Max-Plan covers compute, so cost_usd is null
        CostEvent::create([
            'source' => 'project_nightly',
            'model' => $parsed['model'] ?? ($project->model ?? config('dashboard.daily.model', 'claude-sonnet-4-6')),
            'input_tokens' => (int) ($parsed['usage']['input_tokens'] ?? 0),
            'output_tokens' => (int) ($parsed['usage']['output_tokens'] ?? 0),
            'cost_usd' => null,
            'occurred_at' => now(),
        ]);

        app(ActivityLogger::class)->log(
            'system', 'project_run_completed', 'project', (string) $project->id,
            details: ['name' => $project->name, 'run_id' => $run->id, 'branch' => $branchName, 'tokens' => $tokens]
        );
    }

    private function setupStateFolder(Project $project): void
    {
        @mkdir($project->statePath(), 0755, true);
        @mkdir($project->statePath() . '/log', 0755, true);

        if (! is_file($project->nightlyMdPath())) {
            file_put_contents($project->nightlyMdPath(), $this->initialNightlyMd($project));
        }
        if (! is_file($project->reviewMdPath())) {
            file_put_contents($project->reviewMdPath(), '');
        }
    }

    private function initialNightlyMd(Project $project): string
    {
        return <<<MD
# Nightly Tasks — {$project->name}

Hier kommen die Tasks rein, die der Agent nachts ausführen soll.
Eine Aufgabe pro Bullet, klar und so konkret wie möglich.

## Beispiele

- Refactor Modul X für bessere Lesbarkeit
- Schreibe Tests für die Funktion Y in Z.php
- Aktualisiere die README mit den letzten Änderungen
- Recherchiere wie wir Feature W implementieren können

(Wird vom Agenten beim Run ersetzt mit den nächsten sinnvollen Tasks. Wenn leer: Projekt
hat heute Nacht nichts zu tun.)
MD;
    }

    private function buildBriefing(Project $project): string
    {
        return <<<BRIEFING
Du bist der Nightly-Agent für das Projekt "{$project->name}".

ARBEITSWEISE:
1. Lies `.dashboard/nightly.md` — dort stehen deine Tasks für diesen Run.
2. Arbeite die Tasks der Reihe nach ab. Du hast volle Tool-Permission auf
   diesem Worktree-Branch.
3. Wenn du Code änderst: in sinnvollen Schritten committen.
4. Schreibe deine Ergebnisse strukturiert in `.dashboard/review.md`:
   - Was wurde gemacht (mit Datei-Verweisen wo sinnvoll)
   - Was ist offen / braucht Steffis Aufmerksamkeit
   - Wie wurde getestet (falls relevant)
   - Vorschläge für Folge-Tasks
5. Aktualisiere `.dashboard/nightly.md` mit den Tasks für den nächsten
   Run — entweder die Folge-Tasks aus deinem Output oder eine leere Liste,
   wenn das Projekt-Ziel erreicht ist.

WICHTIG:
- Du arbeitest auf einem isolierten Branch — Steffis Hauptverzeichnis ist
  nicht betroffen.
- Das `.dashboard/`-Verzeichnis ist worktree-lokal git-ignoriert; commite
  es nicht.
- Wenn eine Task unklar ist: in `.dashboard/review.md` als offene Frage
  vermerken und mit dem Rest weitermachen.

Beginne jetzt.
BRIEFING;
    }

    private function git(string $cwd, array $args): string
    {
        $result = Process::path($cwd)->run(array_merge(['git'], $args));
        if (! $result->successful()) {
            throw new \RuntimeException(
                'git ' . implode(' ', $args) . ' failed: ' . trim($result->errorOutput() ?: $result->output())
            );
        }
        return $result->output();
    }

    private function detectDefaultBranch(string $path): string
    {
        try {
            $out = trim($this->git($path, ['symbolic-ref', '--quiet', '--short', 'refs/remotes/origin/HEAD']));
            if ($out !== '' && str_starts_with($out, 'origin/')) {
                return substr($out, strlen('origin/'));
            }
        } catch (\Throwable) {}

        foreach (['main', 'master', 'stage', 'develop'] as $candidate) {
            try {
                $this->git($path, ['rev-parse', '--verify', '--quiet', $candidate]);
                return $candidate;
            } catch (\Throwable) {}
        }

        return 'main';
    }

    private function resolveBranchBase(Project $project): string
    {
        // Prefer origin/<default> if remote exists, else local <default>.
        try {
            $remotes = trim($this->git($project->path, ['remote']));
            if ($remotes !== '') {
                $origin = "origin/{$project->default_branch}";
                $this->git($project->path, ['rev-parse', '--verify', '--quiet', $origin]);
                return $origin;
            }
        } catch (\Throwable) {}

        return $project->default_branch;
    }

    private function hasAnyCommit(string $path): bool
    {
        try {
            return trim($this->git($path, ['rev-list', '-n', '1', '--all'])) !== '';
        } catch (\Throwable) {
            return false;
        }
    }

    private function assertWithinAllowedRoots(string $path): void
    {
        $allowed = config('dashboard.projects.allowed_roots', []);
        foreach ($allowed as $root) {
            $rootReal = realpath($root) ?: $root;
            if (str_starts_with($path, rtrim($rootReal, '/') . '/') || $path === $rootReal) {
                return;
            }
        }
        throw new \RuntimeException("Project path is outside allowed roots: {$path}");
    }

    private function elapsed(ProjectRun $run): int
    {
        if (! $run->started_at) return 0;
        return (int) max(0, now()->diffInSeconds($run->started_at, true));
    }

    private function markFailure(Project $project): void
    {
        $project->increment('consecutive_failures');
        if ($project->consecutive_failures >= 2) {
            $project->update([
                'status' => 'paused',
                'paused_at' => now(),
            ]);
            Log::warning('Project auto-paused after 2 consecutive failures', ['project' => $project->name]);
        }
    }
}
