<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $paths = collect()
            ->merge(DB::table('agent_runs')->whereNotNull('ticket_path')->pluck('ticket_path'))
            ->merge(DB::table('routine_runs')->whereNotNull('ticket_path')->pluck('ticket_path'))
            ->merge(DB::table('task_events')->whereNotNull('ticket_path')->pluck('ticket_path'))
            ->unique()
            ->values();

        $pathToTicketId = [];

        foreach ($paths as $path) {
            $note = DB::table('vault_notes')->where('relative_path', $path)->first();

            $title = $note->title ?? Str::of(basename($path, '.md'))
                ->replace(['-', '_'], ' ')
                ->title()
                ->__toString();

            $frontmatter = $note?->frontmatter ? json_decode($note->frontmatter, true) : [];

            $ticketId = DB::table('tickets')->insertGetId([
                'title' => $title,
                'description' => $note->body_preview ?? null,
                'type' => $frontmatter['type'] ?? 'task',
                'status' => $frontmatter['status'] ?? 'done',
                'priority' => $frontmatter['priority'] ?? 'medium',
                'assigned_to' => $frontmatter['assigned_to'] ?? 'agent',
                'agent_skill' => $frontmatter['agent_skill'] ?? null,
                'tags' => isset($frontmatter['tags']) ? json_encode($frontmatter['tags']) : null,
                'due_date' => $frontmatter['due_date'] ?? null,
                'postpone_count' => (int) ($frontmatter['postpone_count'] ?? 0),
                'emotional_charge' => $frontmatter['emotional_charge'] ?? null,
                'system_level' => isset($frontmatter['system_level']) ? (int) $frontmatter['system_level'] : null,
                'source_vault_note_id' => $note->id ?? null,
                'completed_at' => $note->updated_at ?? now(),
                'created_at' => $note->created_at ?? now(),
                'updated_at' => $note->updated_at ?? now(),
            ]);

            $pathToTicketId[$path] = $ticketId;
        }

        foreach ($pathToTicketId as $path => $ticketId) {
            DB::table('agent_runs')->where('ticket_path', $path)->update(['ticket_id' => $ticketId]);
            DB::table('routine_runs')->where('ticket_path', $path)->update(['ticket_id' => $ticketId]);
            DB::table('task_events')->where('ticket_path', $path)->update(['ticket_id' => $ticketId]);
        }
    }

    public function down(): void
    {
        DB::table('agent_runs')->update(['ticket_id' => null]);
        DB::table('routine_runs')->update(['ticket_id' => null]);
        DB::table('task_events')->update(['ticket_id' => null]);
        DB::table('tickets')->truncate();
    }
};
