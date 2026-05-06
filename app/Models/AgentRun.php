<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentRun extends Model
{
    protected $fillable = [
        'ticket_id', 'skill', 'status', 'prompt',
        'raw_output', 'summary', 'output_note_path', 'tokens_used',
        'duration_seconds', 'error_message', 'started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', ['queued', 'running']);
    }
}
