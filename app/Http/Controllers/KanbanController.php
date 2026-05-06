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

        $intervention = $kanban->moveTicket($request->input('path'), $request->input('status'));

        $flash = ['success' => 'Ticket moved.'];
        if ($intervention) {
            $flash['intervention'] = $intervention;
        }

        return back()->with($flash);
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

    public function show(string $path, KanbanService $kanban)
    {
        $path = urldecode($path);
        $note = \App\Models\VaultNote::where('relative_path', $path)->firstOrFail();
        $contextLinks = $kanban->getContextLinks($path);

        return response()->json([
            'ticket' => $note,
            'contextLinks' => $contextLinks,
        ]);
    }

    public function addLink(Request $request, KanbanService $kanban)
    {
        $request->validate([
            'ticket_path' => 'required|string',
            'link_path' => 'required|string',
        ]);

        $kanban->addContextLink($request->input('ticket_path'), $request->input('link_path'));

        return back()->with('success', 'Context link added.');
    }

    public function removeLink(Request $request, KanbanService $kanban)
    {
        $request->validate([
            'ticket_path' => 'required|string',
            'link_path' => 'required|string',
        ]);

        $kanban->removeContextLink($request->input('ticket_path'), $request->input('link_path'));

        return back()->with('success', 'Context link removed.');
    }

    public function destroy(Request $request, KanbanService $kanban)
    {
        $request->validate([
            'path' => 'required|string',
        ]);

        $kanban->deleteTicket($request->input('path'));

        return back()->with('success', 'Ticket gelöscht.');
    }

    public function updateMeta(Request $request, KanbanService $kanban)
    {
        $request->validate([
            'path' => 'required|string',
            'model' => 'nullable|string',
            'agent_skill' => 'nullable|string',
            'depends_on' => 'nullable|array',
        ]);

        $fields = array_filter(
            $request->only(['model', 'agent_skill', 'depends_on']),
            fn ($v) => $v !== null
        );

        $kanban->updateTicketMeta($request->input('path'), $fields);

        return back()->with('success', 'Ticket updated.');
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
