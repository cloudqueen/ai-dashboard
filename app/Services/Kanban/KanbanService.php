<?php

namespace App\Services\Kanban;

use App\Enums\TicketStatus;
use App\Models\TaskEvent;
use App\Models\VaultNote;
use App\Services\Psychology\PsychEngine;
use App\Services\Vault\VaultManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class KanbanService
{
    public function __construct(
        private VaultManager $vault,
        private PsychEngine $psych,
    ) {}

    /**
     * Get the full kanban board grouped by status columns.
     */
    public function getBoard(array $filters = []): array
    {
        $query = VaultNote::tickets();

        if (! empty($filters['tags'])) {
            $query->whereJsonContains('tags', $filters['tags']);
        }

        if (! empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }

        if (! empty($filters['assigned_to'])) {
            $query->where('assigned_to', $filters['assigned_to']);
        }

        $tickets = $query->orderBy('priority')->orderBy('due_date')->get();

        $columns = [];
        foreach (TicketStatus::cases() as $status) {
            $columns[$status->value] = [
                'key' => $status->value,
                'label' => $status->label(),
                'tickets' => $tickets->where('status', $status->value)->values()->toArray(),
            ];
        }

        return $columns;
    }

    /**
     * Move a ticket to a new status.
     */
    public function moveTicket(string $path, string $newStatus): ?array
    {
        $note = VaultNote::where('relative_path', $path)->firstOrFail();
        $oldStatus = $note->status;

        $frontmatterUpdate = [
            'status' => $newStatus,
            'updated_at' => Carbon::now()->toIso8601String(),
        ];

        // Detect postponement (moved backwards)
        $backwardMoves = ['in_progress' => ['backlog', 'todo'], 'todo' => ['backlog']];
        $isPostpone = isset($backwardMoves[$oldStatus]) && in_array($newStatus, $backwardMoves[$oldStatus]);

        if ($isPostpone) {
            $count = ($note->frontmatter['postpone_count'] ?? 0) + 1;
            $frontmatterUpdate['postpone_count'] = $count;
        }

        $this->vault->updateFrontmatter($path, $frontmatterUpdate);

        $eventType = $isPostpone ? 'postponed' : 'status_changed';
        TaskEvent::create([
            'ticket_path' => $path,
            'event_type' => $eventType,
            'from_status' => $oldStatus,
            'to_status' => $newStatus,
        ]);

        // Log activity
        app(\App\Services\ActivityLogger::class)->log(
            'human', $isPostpone ? 'postponed' : 'moved', 'ticket', $path,
            details: ['from' => $oldStatus, 'to' => $newStatus]
        );

        // Emit dashboard event
        app(\App\Services\DashboardEventBus::class)->emit('ticket_moved', [
            'ticket' => $path,
            'from' => $oldStatus,
            'to' => $newStatus,
        ]);

        // Trigger psychology
        $intervention = null;
        if ($newStatus === 'done') {
            $intervention = $this->psych->evaluate('task_complete', $path);
        } elseif ($newStatus === 'in_progress') {
            $intervention = $this->psych->evaluate('task_start', $path);
        } elseif ($isPostpone) {
            $intervention = $this->psych->evaluate('status_change', $path);
        }

        return $intervention?->toArray();
    }

    /**
     * Create a new ticket as a vault note.
     */
    public function createTicket(array $data): string
    {
        $frontmatter = [
            'type' => $data['type'] ?? 'task',
            'status' => $data['status'] ?? 'backlog',
            'priority' => $data['priority'] ?? 'medium',
            'assigned_to' => $data['assigned_to'] ?? 'human',
            'tags' => $data['tags'] ?? [],
            'due_date' => $data['due_date'] ?? null,
            'agent_skill' => $data['agent_skill'] ?? null,
            'postpone_count' => 0,
            'emotional_charge' => $data['emotional_charge'] ?? 'low',
            'system_level' => $data['system_level'] ?? 2,
            'created_at' => Carbon::now()->toIso8601String(),
            'updated_at' => Carbon::now()->toIso8601String(),
        ];

        $body = "# {$data['title']}\n\n" . ($data['description'] ?? '') . "\n";

        $folder = $data['folder'] ?? dirname($data['relative_path'] ?? 'inbox/untitled');

        $relativePath = $this->vault->createNote($folder, $data['title'], $frontmatter, $body);

        TaskEvent::create([
            'ticket_path' => $relativePath,
            'event_type' => 'created',
            'to_status' => $frontmatter['status'],
        ]);

        return $relativePath;
    }

    /**
     * Delete a ticket — sets status to 'cancelled' in frontmatter, removes from index.
     */
    public function deleteTicket(string $path): void
    {
        $this->vault->updateFrontmatter($path, [
            'status' => 'cancelled',
            'updated_at' => Carbon::now()->toIso8601String(),
        ]);

        // Remove from vault index so it doesn't appear on the board
        VaultNote::where('relative_path', $path)->delete();

        TaskEvent::create([
            'ticket_path' => $path,
            'event_type' => 'status_changed',
            'to_status' => 'cancelled',
        ]);
    }

    /**
     * Update agent-related metadata on a ticket's frontmatter.
     */
    public function updateTicketMeta(string $path, array $fields): void
    {
        $this->vault->updateFrontmatter($path, $fields);
    }

    /**
     * Promote an existing vault note to a kanban ticket.
     */
    public function promoteNote(string $path, array $ticketData = []): void
    {
        $frontmatter = array_merge([
            'type' => 'task',
            'status' => 'backlog',
            'priority' => 'medium',
            'assigned_to' => 'human',
            'postpone_count' => 0,
            'emotional_charge' => 'low',
            'system_level' => 2,
            'created_at' => Carbon::now()->toIso8601String(),
            'updated_at' => Carbon::now()->toIso8601String(),
        ], $ticketData);

        $this->vault->updateFrontmatter($path, $frontmatter);

        TaskEvent::create([
            'ticket_path' => $path,
            'event_type' => 'created',
            'to_status' => $frontmatter['status'],
        ]);
    }

    /**
     * Add a context link to a ticket's frontmatter.
     */
    public function addContextLink(string $ticketPath, string $linkPath): void
    {
        $note = $this->vault->readNote($ticketPath);
        if (! $note) {
            throw new \RuntimeException("Ticket not found: {$ticketPath}");
        }

        $links = $note->frontmatter['context_links'] ?? [];

        if (! in_array($linkPath, $links, true)) {
            $links[] = $linkPath;
            $this->vault->updateFrontmatter($ticketPath, ['context_links' => $links]);
        }
    }

    /**
     * Remove a context link from a ticket's frontmatter.
     */
    public function removeContextLink(string $ticketPath, string $linkPath): void
    {
        $note = $this->vault->readNote($ticketPath);
        if (! $note) {
            throw new \RuntimeException("Ticket not found: {$ticketPath}");
        }

        $links = $note->frontmatter['context_links'] ?? [];
        $links = array_values(array_filter($links, fn ($l) => $l !== $linkPath));

        $this->vault->updateFrontmatter($ticketPath, ['context_links' => $links]);
    }

    /**
     * Get the context links for a ticket with their titles.
     */
    public function getContextLinks(string $ticketPath): array
    {
        $note = $this->vault->readNote($ticketPath);
        if (! $note) {
            return [];
        }

        $links = $note->frontmatter['context_links'] ?? [];

        return collect($links)->map(function ($path) {
            $vaultNote = VaultNote::where('relative_path', $path)->first();
            return $vaultNote ? [
                'relative_path' => $vaultNote->relative_path,
                'title' => $vaultNote->title,
                'vault_folder' => $vaultNote->vault_folder,
            ] : null;
        })->filter()->values()->toArray();
    }

    /**
     * Get available filter values from existing tickets.
     */
    public function getFilterOptions(): array
    {
        $tickets = VaultNote::tickets();

        return [
            'tags' => VaultNote::tickets()
                ->whereNotNull('tags')
                ->pluck('tags')
                ->flatten()
                ->unique()
                ->sort()
                ->values(),
            'assignees' => VaultNote::tickets()
                ->whereNotNull('assigned_to')
                ->distinct()
                ->pluck('assigned_to')
                ->sort()
                ->values(),
        ];
    }
}
