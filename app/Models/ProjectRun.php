<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectRun extends Model
{
    protected $fillable = [
        'project_id',
        'branch_name',
        'worktree_path',
        'status',
        'started_at',
        'completed_at',
        'prompt',
        'raw_output',
        'summary',
        'tokens_used',
        'duration_seconds',
        'error_message',
        'review_ticket_id',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function reviewTicket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'review_ticket_id');
    }
}
