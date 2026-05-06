<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Pgvector\Laravel\Vector;

class CoachMemory extends Model
{
    protected $fillable = [
        'type',
        'session_date',
        'content',
        'metadata',
        'embedding',
    ];

    protected function casts(): array
    {
        return [
            'session_date' => 'date',
            'metadata' => 'array',
            'embedding' => Vector::class,
        ];
    }
}
