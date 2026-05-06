<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ticket extends Model
{
    protected $fillable = [
        'title',
        'description',
        'type',
        'status',
        'priority',
        'assigned_to',
        'agent_skill',
        'tags',
        'due_date',
        'postpone_count',
        'emotional_charge',
        'system_level',
        'context_links',
        'depends_on',
        'model',
        'routine_id',
        'source_vault_note_id',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'context_links' => 'array',
            'depends_on' => 'array',
            'due_date' => 'date',
            'completed_at' => 'datetime',
            'system_level' => 'integer',
            'postpone_count' => 'integer',
        ];
    }

    public function routine(): BelongsTo
    {
        return $this->belongsTo(Routine::class);
    }

    public function sourceVaultNote(): BelongsTo
    {
        return $this->belongsTo(VaultNote::class, 'source_vault_note_id');
    }

    public function agentRuns(): HasMany
    {
        return $this->hasMany(AgentRun::class);
    }

    public function routineRuns(): HasMany
    {
        return $this->hasMany(RoutineRun::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(TaskEvent::class);
    }

    public function scopeActive($query)
    {
        return $query->whereNotIn('status', ['done']);
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeReadyForAgent($query)
    {
        return $query->where('status', 'ready_for_agent');
    }

    public function scopeDueSoon($query, int $days = 7)
    {
        return $query->whereNotNull('due_date')
            ->where('due_date', '<=', now()->addDays($days))
            ->where('status', '!=', 'done');
    }
}
