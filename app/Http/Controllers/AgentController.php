<?php

namespace App\Http\Controllers;

use App\Models\AgentRun;
use App\Models\AgentSkill;
use App\Models\Ticket;
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
            'run' => $agentRun->load('ticket'),
        ]);
    }

    public function triggerRun(Request $request, AgentOrchestrator $orchestrator)
    {
        $request->validate([
            'ticket_id' => 'required|integer|exists:tickets,id',
            'skill' => 'nullable|string',
        ]);

        $ticket = Ticket::findOrFail($request->integer('ticket_id'));
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
