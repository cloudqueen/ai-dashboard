<?php

namespace App\Services\Kanban;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\VaultNote;

class KanbanService
{
    /**
     * Get the full kanban board grouped by status columns.
     */
    public function getBoard(array $filters = []): array
    {
        $query = Ticket::query();

        if (! empty($filters['tags'])) {
            $query->whereJsonContains('tags', $filters['tags']);
        }

        if (! empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }

        if (! empty($filters['assigned_to'])) {
            $query->where('assigned_to', $filters['assigned_to']);
        }

        $tickets = $query
            ->orderByRaw("CASE priority
                WHEN 'critical' THEN 1
                WHEN 'high' THEN 2
                WHEN 'medium' THEN 3
                WHEN 'low' THEN 4
                ELSE 5
            END")
            ->orderBy('due_date')
            ->get();

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
     * Resolve context links to vault notes.
     */
    public function getContextLinks(Ticket $ticket): array
    {
        $links = $ticket->context_links ?? [];

        return collect($links)
            ->map(function ($path) {
                $note = VaultNote::where('relative_path', $path)->first();
                return $note ? [
                    'relative_path' => $note->relative_path,
                    'title' => $note->title,
                    'vault_folder' => $note->vault_folder,
                ] : ['relative_path' => $path, 'title' => basename($path, '.md'), 'vault_folder' => null];
            })
            ->values()
            ->toArray();
    }

    /**
     * Get available filter values from existing tickets.
     */
    public function getFilterOptions(): array
    {
        return [
            'tags' => Ticket::query()
                ->whereNotNull('tags')
                ->pluck('tags')
                ->flatten()
                ->unique()
                ->sort()
                ->values(),
            'assignees' => Ticket::query()
                ->whereNotNull('assigned_to')
                ->distinct()
                ->pluck('assigned_to')
                ->sort()
                ->values(),
        ];
    }
}
