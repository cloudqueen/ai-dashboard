<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class DashboardEventBus
{
    public function emit(string $type, array $payload = []): void
    {
        DB::table('dashboard_events')->insert([
            'type' => $type,
            'payload' => json_encode($payload),
            'created_at' => now(),
        ]);

        // Cleanup: remove events older than 1 hour
        DB::table('dashboard_events')
            ->where('created_at', '<', now()->subHour())
            ->delete();
    }

    public function since(int $lastId): array
    {
        return DB::table('dashboard_events')
            ->where('id', '>', $lastId)
            ->orderBy('id')
            ->limit(50)
            ->get()
            ->map(fn ($e) => [
                'id' => $e->id,
                'type' => $e->type,
                'payload' => json_decode($e->payload, true),
                'at' => $e->created_at,
            ])
            ->toArray();
    }
}
