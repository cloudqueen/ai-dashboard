<?php

namespace App\Services\Kanban;

use App\Enums\TicketStatus;
use App\Models\TaskEvent;
use App\Models\VaultNote;
use App\Services\Vault\VaultManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class KanbanService
{
    public function __construct(
        private VaultManager $vault,
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
    public function moveTicket(string $path, string $newStatus): void
    {
        $note = VaultNote::where('relative_path', $path)->firstOrFail();
        $oldStatus = $note->status;

        $this->vault->updateFrontmatter($path, [
            'status' => $newStatus,
            'updated_at' => Carbon::now()->toIso8601String(),
        ]);

        TaskEvent::create([
            'ticket_path' => $path,
            'event_type' => 'status_changed',
            'from_status' => $oldStatus,
            'to_status' => $newStatus,
        ]);
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
