<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\ProjectService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;
use Inertia\Inertia;

class ProjectController extends Controller
{
    public function index()
    {
        $projects = Project::query()
            ->orderBy('status')
            ->orderBy('name')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'path' => $p->path,
                'status' => $p->status,
                'default_branch' => $p->default_branch,
                'nightly_enabled' => $p->nightly_enabled,
                'last_run_at' => $p->last_run_at?->toIso8601String(),
                'consecutive_failures' => $p->consecutive_failures,
                'awaiting_review' => trim($p->readReview()) !== '',
            ]);

        return Inertia::render('Projects/Index', [
            'projects' => $projects,
            'allowedRoots' => config('dashboard.projects.allowed_roots', []),
        ]);
    }

    public function show(Project $project)
    {
        $runs = $project->runs()->limit(20)->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'status' => $r->status,
                'branch_name' => $r->branch_name,
                'worktree_path' => $r->worktree_path,
                'started_at' => $r->started_at?->toIso8601String(),
                'completed_at' => $r->completed_at?->toIso8601String(),
                'duration_seconds' => $r->duration_seconds,
                'tokens_used' => $r->tokens_used,
                'summary' => $r->summary,
                'error_message' => $r->error_message,
                'review_ticket_id' => $r->review_ticket_id,
            ]);

        return Inertia::render('Projects/Show', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'path' => $project->path,
                'status' => $project->status,
                'default_branch' => $project->default_branch,
                'allowed_tools' => $project->allowed_tools,
                'model' => $project->model,
                'max_run_minutes' => $project->max_run_minutes,
                'max_turns' => $project->max_turns,
                'nightly_enabled' => $project->nightly_enabled,
                'nightly_schedule' => $project->nightly_schedule,
                'consecutive_failures' => $project->consecutive_failures,
                'last_run_at' => $project->last_run_at?->toIso8601String(),
                'state_path' => $project->statePath(),
            ],
            'nightlyMd' => $project->readNightly(),
            'reviewMd' => $project->readReview(),
            'runs' => $runs,
            'activeWorktrees' => $this->listActiveWorktrees($project),
        ]);
    }

    public function store(Request $request, ProjectService $service)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100|regex:/^[a-z0-9_-]+$/',
            'path' => 'required|string',
            'default_branch' => 'nullable|string|max:100',
            'allowed_tools' => 'nullable|string|max:500',
            'model' => 'nullable|string|max:100',
        ]);

        try {
            $service->create($data);
        } catch (\Throwable $e) {
            return back()->withErrors(['path' => $e->getMessage()]);
        }

        return back()->with('success', "Projekt '{$data['name']}' angelegt.");
    }

    public function update(Request $request, Project $project)
    {
        $data = $request->validate([
            'status' => 'nullable|in:active,paused,done',
            'nightly_enabled' => 'nullable|boolean',
            'allowed_tools' => 'nullable|string|max:500',
            'model' => 'nullable|string|max:100',
            'max_run_minutes' => 'nullable|integer|min:1|max:240',
            'max_turns' => 'nullable|integer|min:1|max:200',
            'default_branch' => 'nullable|string|max:100',
        ]);

        // Resuming clears failure counter
        if (($data['status'] ?? null) === 'active' && $project->status === 'paused') {
            $data['consecutive_failures'] = 0;
            $data['paused_at'] = null;
        }

        $project->update(array_filter($data, fn ($v) => $v !== null));

        return back()->with('success', 'Projekt aktualisiert.');
    }

    public function destroy(Project $project)
    {
        $project->delete();
        return redirect()->route('projects')->with('success', "Projekt '{$project->name}' gelöscht.");
    }

    public function updateNightly(Request $request, Project $project)
    {
        $data = $request->validate(['content' => 'required|string|max:200000']);
        $project->writeNightly($data['content']);
        return back()->with('success', 'nightly.md gespeichert.');
    }

    public function runNow(Project $project, ProjectService $service)
    {
        if ($project->status !== 'active') {
            return back()->withErrors(['status' => 'Projekt ist nicht active.']);
        }

        // Run synchronously — for nightly we use the schedule
        $run = $service->runNightly($project);
        return back()->with('success', "Run #{$run->id} {$run->status}.");
    }

    private function listActiveWorktrees(Project $project): array
    {
        if (! is_dir($project->path . '/.git')) return [];
        try {
            $result = Process::path($project->path)->run(['git', 'worktree', 'list', '--porcelain']);
            if (! $result->successful()) return [];

            $worktrees = [];
            $current = [];
            foreach (explode("\n", $result->output()) as $line) {
                if (str_starts_with($line, 'worktree ')) {
                    if ($current) $worktrees[] = $current;
                    $current = ['path' => substr($line, 9)];
                } elseif (str_starts_with($line, 'branch ')) {
                    $current['branch'] = substr($line, 7);
                } elseif (str_starts_with($line, 'HEAD ')) {
                    $current['head'] = substr($line, 5);
                }
            }
            if ($current) $worktrees[] = $current;

            // Filter to nightly worktrees only
            return array_values(array_filter($worktrees, fn ($w) => isset($w['branch']) && str_contains($w['branch'], 'nightly/')));
        } catch (\Throwable) {
            return [];
        }
    }
}
