<?php

namespace App\Http\Controllers;

use App\Models\Routine;
use App\Services\Agent\SkillLoader;
use App\Services\RoutineScheduler;
use Illuminate\Http\Request;
use Inertia\Inertia;

class RoutineController extends Controller
{
    public function index(SkillLoader $skillLoader)
    {
        $routines = Routine::with('latestRun')
            ->orderBy('enabled', 'desc')
            ->orderBy('next_run_at')
            ->get();

        return Inertia::render('Routines/Index', [
            'routines' => $routines,
            'skills' => $skillLoader->listAll(),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'cron_expression' => 'required|string|max:100',
            'skill' => 'required|string',
            'prompt_template' => 'required|string',
            'priority' => 'nullable|string',
            'model' => 'nullable|string',
            'output_folder' => 'nullable|string',
        ]);

        // Calculate initial next_run_at
        $nextRun = null;
        try {
            $cron = new \Cron\CronExpression($request->input('cron_expression'));
            $nextRun = \Carbon\Carbon::instance($cron->getNextRunDate());
        } catch (\Throwable) {}

        Routine::create([
            ...$request->only(['name', 'description', 'cron_expression', 'skill', 'prompt_template', 'priority', 'model', 'output_folder']),
            'next_run_at' => $nextRun,
        ]);

        return back()->with('success', 'Routine erstellt.');
    }

    public function update(Request $request, Routine $routine)
    {
        $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'cron_expression' => 'sometimes|string|max:100',
            'skill' => 'sometimes|string',
            'prompt_template' => 'sometimes|string',
            'priority' => 'nullable|string',
            'model' => 'nullable|string',
            'enabled' => 'sometimes|boolean',
        ]);

        $routine->update($request->only([
            'name', 'description', 'cron_expression', 'skill',
            'prompt_template', 'priority', 'model', 'enabled',
        ]));

        // Recalculate next run if cron changed
        if ($request->has('cron_expression')) {
            try {
                $cron = new \Cron\CronExpression($request->input('cron_expression'));
                $routine->update(['next_run_at' => \Carbon\Carbon::instance($cron->getNextRunDate())]);
            } catch (\Throwable) {}
        }

        return back()->with('success', 'Routine aktualisiert.');
    }

    public function destroy(Routine $routine)
    {
        $routine->delete();
        return back()->with('success', 'Routine gelöscht.');
    }

    public function trigger(Routine $routine, RoutineScheduler $scheduler)
    {
        $run = $scheduler->dispatch($routine);

        return back()->with('success', "Routine '{$routine->name}' manuell gestartet.");
    }

    public function runs(Routine $routine)
    {
        $runs = $routine->runs()
            ->with('agentRun:id,status,duration_seconds,completed_at')
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get();

        return response()->json($runs);
    }
}
