<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskEvent extends Model
{
    protected $fillable = [
        'ticket_path',
        'event_type',
        'from_status',
        'to_status',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }
}
