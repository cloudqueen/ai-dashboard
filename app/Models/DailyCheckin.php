<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailyCheckin extends Model
{
    protected $fillable = [
        'date',
        'claude_session_id',
        'mood',
        'energy',
        'messages',
        'plan',
        'motto_goal',
        'summary',
        'vault_note_path',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'messages' => 'array',
            'plan' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }

    public function addMessage(string $role, string $content, array $options = [], array $tickets = []): void
    {
        $messages = $this->messages ?? [];
        $msg = [
            'role' => $role,
            'content' => $content,
            'at' => now()->toIso8601String(),
        ];
        if ($options) {
            $msg['options'] = $options;
        }
        if ($tickets) {
            $msg['tickets'] = $tickets;
        }
        $messages[] = $msg;
        $this->update(['messages' => $messages]);
    }
}
