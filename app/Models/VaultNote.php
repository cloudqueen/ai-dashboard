<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VaultNote extends Model
{
    protected $fillable = [
        'relative_path',
        'title',
        'vault_folder',
        'frontmatter',
        'body_preview',
        'status',
        'priority',
        'type',
        'assigned_to',
        'tags',
        'due_date',
        'content_hash',
        'vault_modified_at',
    ];

    protected function casts(): array
    {
        return [
            'frontmatter' => 'array',
            'tags' => 'array',
            'due_date' => 'date',
            'vault_modified_at' => 'datetime',
        ];
    }

    public function isTicket(): bool
    {
        $ticketTypes = config('dashboard.kanban.ticket_types', []);
        return in_array($this->type, $ticketTypes);
    }

    public function scopeTickets($query)
    {
        return $query->whereIn('type', config('dashboard.kanban.ticket_types', []));
    }

    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }
}
