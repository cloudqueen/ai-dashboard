<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\VaultNote;
use App\Services\Kanban\KanbanService;
use App\Services\TicketService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class KanbanController extends Controller
{
    public function index(Request $request, KanbanService $kanban)
    {
        $filters = $request->only(['tags', 'priority', 'assigned_to']);

        return Inertia::render('Kanban/Index', [
            'columns' => $kanban->getBoard($filters),
            'filterOptions' => $kanban->getFilterOptions(),
            'filters' => $filters,
        ]);
    }

    public function move(Request $request, TicketService $tickets)
    {
        $request->validate([
            'id' => 'required|integer|exists:tickets,id',
            'status' => 'required|string',
            'assigned_to' => 'nullable|in:human,agent',
        ]);

        $ticket = Ticket::findOrFail($request->integer('id'));

        if ($newAssignee = $request->input('assigned_to')) {
            if ($ticket->assigned_to !== $newAssignee) {
                $ticket->update(['assigned_to' => $newAssignee]);
            }
        }

        $result = $tickets->move($ticket, $request->input('status'));

        $flash = ['success' => 'Ticket moved.'];
        if ($result['intervention']) {
            $flash['intervention'] = $result['intervention'];
        }

        return back()->with($flash);
    }

    public function store(Request $request, TicketService $tickets)
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'type' => 'nullable|string',
            'status' => 'nullable|string',
            'priority' => 'nullable|string',
            'assigned_to' => 'nullable|string',
            'agent_skill' => 'nullable|string',
            'description' => 'nullable|string',
            'tags' => 'nullable|array',
            'due_date' => 'nullable|date',
            'emotional_charge' => 'nullable|string',
            'system_level' => 'nullable|integer',
        ]);

        $tickets->create($data);

        return back()->with('success', 'Ticket created.');
    }

    public function show(int $id, KanbanService $kanban)
    {
        $ticket = Ticket::findOrFail($id);
        $contextLinks = $kanban->getContextLinks($ticket);

        $depIds = $ticket->depends_on ?? [];
        $dependencies = empty($depIds) ? [] : Ticket::whereIn('id', $depIds)
            ->get(['id', 'title', 'status'])
            ->toArray();

        return response()->json([
            'ticket' => $ticket,
            'contextLinks' => $contextLinks,
            'dependencies' => $dependencies,
        ]);
    }

    public function searchTickets(Request $request)
    {
        $q = trim((string) $request->input('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        return response()->json(
            Ticket::query()
                ->where('title', 'LIKE', '%' . $q . '%')
                ->orderBy('title')
                ->limit(15)
                ->get(['id', 'title', 'status'])
        );
    }

    public function addLink(Request $request, TicketService $tickets)
    {
        $request->validate([
            'ticket_id' => 'required|integer|exists:tickets,id',
            'link_path' => 'required|string',
        ]);

        $ticket = Ticket::findOrFail($request->integer('ticket_id'));
        $tickets->addContextLink($ticket, $request->input('link_path'));

        return back()->with('success', 'Context link added.');
    }

    public function removeLink(Request $request, TicketService $tickets)
    {
        $request->validate([
            'ticket_id' => 'required|integer|exists:tickets,id',
            'link_path' => 'required|string',
        ]);

        $ticket = Ticket::findOrFail($request->integer('ticket_id'));
        $tickets->removeContextLink($ticket, $request->input('link_path'));

        return back()->with('success', 'Context link removed.');
    }

    public function destroy(Request $request, TicketService $tickets)
    {
        $request->validate(['id' => 'required|integer|exists:tickets,id']);

        $ticket = Ticket::findOrFail($request->integer('id'));
        $tickets->delete($ticket);

        return back()->with('success', 'Ticket gelöscht.');
    }

    public function updateMeta(Request $request, TicketService $tickets)
    {
        $request->validate([
            'id' => 'required|integer|exists:tickets,id',
            'model' => 'nullable|string',
            'agent_skill' => 'nullable|string',
            'depends_on' => 'nullable|array',
            'priority' => 'nullable|string',
            'due_date' => 'nullable|date',
            'tags' => 'nullable|array',
            'emotional_charge' => 'nullable|string',
            'system_level' => 'nullable|integer',
        ]);

        $ticket = Ticket::findOrFail($request->integer('id'));
        $fields = $request->only(['model', 'agent_skill', 'depends_on', 'priority', 'due_date', 'tags', 'emotional_charge', 'system_level']);

        $tickets->update($ticket, array_filter($fields, fn ($v) => $v !== null));

        return back()->with('success', 'Ticket updated.');
    }

    public function promote(Request $request, TicketService $tickets)
    {
        $request->validate([
            'path' => 'required|string',
            'type' => 'nullable|string',
            'priority' => 'nullable|string',
            'status' => 'nullable|string',
        ]);

        $note = VaultNote::where('relative_path', $request->input('path'))->firstOrFail();
        $tickets->promoteFromVaultNote($note, $request->only(['type', 'priority', 'status']));

        return back()->with('success', 'Note promoted to ticket.');
    }
}
