<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PsychIntervention extends Model
{
    protected $fillable = [
        'framework',
        'intervention_type',
        'trigger',
        'ticket_path',
        'content',
        'metadata',
        'was_helpful',
        'dismissed',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'was_helpful' => 'boolean',
            'dismissed' => 'boolean',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('dismissed', false)->whereNull('was_helpful');
    }

    public function scopeForFramework($query, string $framework)
    {
        return $query->where('framework', $framework);
    }
}
