<?php

namespace App\Services;

use App\Models\ActivityLog;

class ActivityLogger
{
    public function __construct(
        private DashboardEventBus $eventBus,
    ) {}

    public function log(
        string $actorType,
        string $action,
        string $entityType,
        ?string $entityId = null,
        ?int $agentRunId = null,
        array $details = [],
    ): ActivityLog {
        $entry = ActivityLog::create([
            'actor_type' => $actorType,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'agent_run_id' => $agentRunId,
            'details' => $details ?: null,
            'created_at' => now(),
        ]);

        // Also emit as dashboard event
        $this->eventBus->emit('activity', [
            'id' => $entry->id,
            'actor_type' => $actorType,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
        ]);

        return $entry;
    }

    public static function recent(int $limit = 15): array
    {
        return ActivityLog::orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->toArray();
    }
}
