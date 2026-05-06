<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CostEvent extends Model
{
    protected $fillable = [
        'agent_run_id', 'source', 'model',
        'input_tokens', 'output_tokens',
        'cache_read_tokens', 'cache_creation_tokens',
        'cost_usd', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'cost_usd' => 'decimal:6',
            'occurred_at' => 'datetime',
        ];
    }

    public function agentRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class);
    }

    public static function todayStats(): array
    {
        $today = self::whereDate('occurred_at', today());

        return [
            'input_tokens' => $today->sum('input_tokens'),
            'output_tokens' => $today->sum('output_tokens'),
            'cost_usd' => $today->sum('cost_usd'),
            'calls' => $today->count(),
        ];
    }

    public static function monthStats(): array
    {
        $month = self::whereYear('occurred_at', now()->year)
            ->whereMonth('occurred_at', now()->month);

        return [
            'input_tokens' => $month->sum('input_tokens'),
            'output_tokens' => $month->sum('output_tokens'),
            'cost_usd' => $month->sum('cost_usd'),
            'calls' => $month->count(),
        ];
    }
}
