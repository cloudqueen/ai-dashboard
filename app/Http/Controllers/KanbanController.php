<?php

namespace App\Http\Controllers;

use App\Services\Kanban\KanbanService;
use App\Services\Vault\VaultManager;
use Illuminate\Http\Request;
use Inertia\Inertia;

class KanbanController extends Controller
{
    public function index(Request $request, KanbanService $kanban, VaultManager $vault)
    {
        $filters = $request->only(['tags', 'priority', 'assigned_to']);

        return Inertia::render('Kanban/Index', [
            'columns' => $vault->isConfigured() ? $kanban->getBoard($filters) : [],
            'filterOptions' => $vault->isConfigured() ? $kanban->getFilterOptions() : [],
            'filters' => $filters,
            'vaultConfigured' => $vault->isConfigured(),
        ]);
    }

    public function move(Request $request, KanbanService $kanban)
    {
        $request->validate([
            'path' => 'required|string',
            'status' => 'required|string',
        ]);

        $kanban->moveTicket($request->input('path'), $request->input('status'));

        return back()->with('success', 'Ticket moved.');
    }

    public function store(Request $request, KanbanService $kanban)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'type' => 'nullable|string',
            'status' => 'nullable|string',
            'priority' => 'nullable|string',
            'assigned_to' => 'nullable|string',
            'description' => 'nullable|string',
            'tags' => 'nullable|array',
            'due_date' => 'nullable|date',
            'folder' => 'nullable|string',
        ]);

        $kanban->createTicket($request->all());

        return back()->with('success', 'Ticket created.');
    }

    public function promote(Request $request, KanbanService $kanban)
    {
        $request->validate([
            'path' => 'required|string',
            'type' => 'nullable|string',
            'priority' => 'nullable|string',
            'status' => 'nullable|string',
        ]);

        $kanban->promoteNote($request->input('path'), $request->except('path'));

        return back()->with('success', 'Note promoted to ticket.');
    }
}
