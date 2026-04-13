<?php

namespace App\Http\Controllers;

use App\Models\AgentRun;
use App\Models\AgentSkill;
use App\Models\VaultNote;
use App\Services\Agent\AgentOrchestrator;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AgentController extends Controller
{
    public function index()
    {
        $runs = AgentRun::orderBy('created_at', 'desc')
            ->paginate(25);

        $activeCount = AgentRun::active()->count();
        $skills = AgentSkill::enabled()->get();

        return Inertia::render('Agents/Index', [
            'runs' => $runs,
            'activeCount' => $activeCount,
            'skills' => $skills,
        ]);
    }

    public function show(AgentRun $agentRun)
    {
        return Inertia::render('Agents/Show', [
            'run' => $agentRun->load('vaultNote'),
        ]);
    }

    public function triggerRun(Request $request, AgentOrchestrator $orchestrator)
    {
        $request->validate([
            'ticket_path' => 'required|string',
            'skill' => 'nullable|string',
        ]);

        $ticket = VaultNote::where('relative_path', $request->input('ticket_path'))->firstOrFail();
        $orchestrator->dispatchAgentRun($ticket, $request->input('skill'));

        return back()->with('success', 'Agent run dispatched.');
    }

    public function status()
    {
        return response()->json([
            'active' => AgentRun::active()->count(),
            'recent' => AgentRun::orderBy('created_at', 'desc')->limit(5)->get(),
        ]);
    }
}
