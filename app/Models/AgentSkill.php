<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentSkill extends Model
{
    protected $fillable = [
        'name', 'display_name', 'description', 'system_prompt',
        'prompt_template', 'required_context', 'enabled',
    ];

    protected function casts(): array
    {
        return [
            'required_context' => 'array',
            'enabled' => 'boolean',
        ];
    }

    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }
}
