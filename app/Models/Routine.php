<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Routine extends Model
{
    protected $fillable = [
        'name', 'description', 'cron_expression', 'skill',
        'prompt_template', 'context_links', 'priority', 'model',
        'output_folder', 'enabled', 'last_run_at', 'next_run_at',
    ];

    protected function casts(): array
    {
        return [
            'context_links' => 'array',
            'enabled' => 'boolean',
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
        ];
    }

    public function runs(): HasMany
    {
        return $this->hasMany(RoutineRun::class);
    }

    public function latestRun()
    {
        return $this->hasOne(RoutineRun::class)->latestOfMany();
    }

    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }

    public function isDue(): bool
    {
        if (! $this->next_run_at) {
            return true;
        }

        return $this->next_run_at->isPast();
    }
}
