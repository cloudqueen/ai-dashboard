<?php

namespace App\Services;

use App\Models\TaskEvent;
use App\Models\Ticket;
use App\Models\VaultNote;
use App\Services\Psychology\PsychEngine;

class TicketService
{
    public function __construct(
        private PsychEngine $psych,
        private ActivityLogger $activity,
        private DashboardEventBus $events,
    ) {}

    public function create(array $data): Ticket
    {
        $ticket = Ticket::create([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'type' => $data['type'] ?? 'task',
            'status' => $data['status'] ?? 'backlog',
            'priority' => $data['priority'] ?? 'medium',
            'assigned_to' => $data['assigned_to'] ?? 'human',
            'agent_skill' => $data['agent_skill'] ?? null,
            'tags' => $data['tags'] ?? [],
            'due_date' => $data['due_date'] ?? null,
            'emotional_charge' => $data['emotional_charge'] ?? null,
            'system_level' => $data['system_level'] ?? null,
            'context_links' => $data['context_links'] ?? [],
            'depends_on' => $data['depends_on'] ?? [],
            'model' => $data['model'] ?? null,
            'routine_id' => $data['routine_id'] ?? null,
            'source_vault_note_id' => $data['source_vault_note_id'] ?? null,
        ]);

        TaskEvent::create([
            'ticket_id' => $ticket->id,
            'ticket_path' => null,
            'event_type' => 'created',
            'to_status' => $ticket->status,
        ]);

        $this->activity->log('human', 'created', 'ticket', (string) $ticket->id,
            details: ['title' => $ticket->title, 'status' => $ticket->status]
        );

        $this->events->emit('ticket_created', [
            'ticket_id' => $ticket->id,
            'title' => $ticket->title,
            'status' => $ticket->status,
        ]);

        return $ticket;
    }

    public function update(Ticket $ticket, array $data): Ticket
    {
        $ticket->fill(array_intersect_key($data, array_flip([
            'title', 'description', 'type', 'priority', 'assigned_to',
            'agent_skill', 'tags', 'due_date', 'emotional_charge',
            'system_level', 'context_links', 'depends_on', 'model',
        ])));
        $ticket->save();

        $this->activity->log('human', 'updated', 'ticket', (string) $ticket->id,
            details: ['fields' => array_keys($data)]
        );

        return $ticket;
    }

    public function move(Ticket $ticket, string $newStatus): array
    {
        $oldStatus = $ticket->status;
        if ($oldStatus === $newStatus) {
            return ['ticket' => $ticket->fresh(), 'intervention' => null];
        }

        $backwardMoves = ['in_progress' => ['backlog', 'todo'], 'todo' => ['backlog']];
        $isPostpone = isset($backwardMoves[$oldStatus]) && in_array($newStatus, $backwardMoves[$oldStatus]);

        $ticket->status = $newStatus;
        if ($isPostpone) {
            $ticket->postpone_count = $ticket->postpone_count + 1;
        }
        if ($newStatus === 'done' && ! $ticket->completed_at) {
            $ticket->completed_at = now();
        }
        $ticket->save();

        TaskEvent::create([
            'ticket_id' => $ticket->id,
            'ticket_path' => null,
            'event_type' => $isPostpone ? 'postponed' : 'status_changed',
            'from_status' => $oldStatus,
            'to_status' => $newStatus,
        ]);

        $this->activity->log('human', $isPostpone ? 'postponed' : 'moved', 'ticket', (string) $ticket->id,
            details: ['from' => $oldStatus, 'to' => $newStatus]
        );

        $this->events->emit('ticket_moved', [
            'ticket_id' => $ticket->id,
            'from' => $oldStatus,
            'to' => $newStatus,
        ]);

        $intervention = match (true) {
            $newStatus === 'done' => $this->psych->evaluate('task_complete', (string) $ticket->id),
            $newStatus === 'in_progress' => $this->psych->evaluate('task_start', (string) $ticket->id),
            $isPostpone => $this->psych->evaluate('status_change', (string) $ticket->id),
            default => null,
        };

        return ['ticket' => $ticket->fresh(), 'intervention' => $intervention?->toArray()];
    }

    public function delete(Ticket $ticket): void
    {
        $id = $ticket->id;
        $ticket->delete();

        $this->activity->log('human', 'deleted', 'ticket', (string) $id);
        $this->events->emit('ticket_deleted', ['ticket_id' => $id]);
    }

    /**
     * Promote a vault note to a ticket — note stays as-is, ticket links back to it.
     */
    public function promoteFromVaultNote(VaultNote $note, array $overrides = []): Ticket
    {
        $fm = $note->frontmatter ?? [];

        return $this->create(array_merge([
            'title' => $note->title,
            'description' => $note->body_preview,
            'type' => $fm['type'] ?? 'task',
            'status' => 'backlog',
            'priority' => $fm['priority'] ?? 'medium',
            'tags' => $fm['tags'] ?? [],
            'context_links' => [$note->relative_path],
            'source_vault_note_id' => $note->id,
        ], $overrides));
    }

    public function addContextLink(Ticket $ticket, string $relativePath): Ticket
    {
        $links = $ticket->context_links ?? [];
        if (! in_array($relativePath, $links, true)) {
            $links[] = $relativePath;
            $ticket->context_links = $links;
            $ticket->save();
        }
        return $ticket;
    }

    public function removeContextLink(Ticket $ticket, string $relativePath): Ticket
    {
        $ticket->context_links = array_values(array_filter(
            $ticket->context_links ?? [],
            fn ($p) => $p !== $relativePath
        ));
        $ticket->save();
        return $ticket;
    }
}
