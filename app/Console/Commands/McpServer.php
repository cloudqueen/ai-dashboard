<?php

namespace App\Console\Commands;

use App\Models\DailyCheckin;
use App\Services\Psychology\DailyCheckinService;
use App\Services\Vault\VaultManager;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class McpServer extends Command
{
    protected $signature = 'mcp:serve';
    protected $description = 'Start MCP server for Claude Desktop (STDIO transport)';

    private array $tools = [];

    public function handle(): void
    {
        ini_set('display_errors', '0');

        $this->registerTools();

        $stdin = fopen('php://stdin', 'r');
        stream_set_blocking($stdin, true);

        while (! feof($stdin)) {
            $line = fgets($stdin);
            if ($line === false) {
                usleep(10000);
                continue;
            }

            $line = trim($line);
            if ($line === '') continue;

            $request = json_decode($line, true);
            if (! $request) continue;

            $response = $this->handleRequest($request);
            if ($response !== null) {
                fwrite(STDOUT, json_encode($response, JSON_UNESCAPED_UNICODE) . "\n");
                fflush(STDOUT);
            }
        }

        fclose($stdin);
    }

    private function handleRequest(array $request): ?array
    {
        $method = $request['method'] ?? '';
        $id = $request['id'] ?? null;
        $params = $request['params'] ?? [];

        // Notifications (no id) — don't respond
        if ($id === null && in_array($method, ['notifications/initialized', 'notifications/cancelled'])) {
            return null;
        }

        return match ($method) {
            'initialize' => $this->handleInitialize($id),
            'tools/list' => $this->handleToolsList($id),
            'tools/call' => $this->handleToolCall($id, $params),
            'resources/list' => $this->jsonRpcResult($id, ['resources' => []]),
            'prompts/list' => $this->jsonRpcResult($id, ['prompts' => []]),
            'ping' => $this->jsonRpcResult($id, []),
            default => $this->jsonRpcError($id, -32601, "Method not found: {$method}"),
        };
    }

    private function handleInitialize(mixed $id): array
    {
        return $this->jsonRpcResult($id, [
            'protocolVersion' => '2024-11-05',
            'capabilities' => [
                'tools' => ['listChanged' => false],
            ],
            'serverInfo' => [
                'name' => 'steffi-ai-dashboard',
                'version' => '1.0.0',
            ],
        ]);
    }

    private function handleToolsList(mixed $id): array
    {
        $toolDefs = [];
        foreach ($this->tools as $name => $tool) {
            $toolDefs[] = [
                'name' => $name,
                'description' => $tool['description'],
                'inputSchema' => $tool['schema'],
            ];
        }

        return $this->jsonRpcResult($id, ['tools' => $toolDefs]);
    }

    private function handleToolCall(mixed $id, array $params): array
    {
        $name = $params['name'] ?? '';
        $args = $params['arguments'] ?? [];

        if (! isset($this->tools[$name])) {
            return $this->jsonRpcError($id, -32602, "Unknown tool: {$name}");
        }

        try {
            $result = ($this->tools[$name]['handler'])($args);

            return $this->jsonRpcResult($id, [
                'content' => [
                    ['type' => 'text', 'text' => is_string($result) ? $result : json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)],
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->jsonRpcResult($id, [
                'content' => [
                    ['type' => 'text', 'text' => "Fehler: {$e->getMessage()}"],
                ],
                'isError' => true,
            ]);
        }
    }

    // --- Tool registration ---

    private function registerTools(): void
    {
        $this->tools['create_ticket'] = [
            'description' => 'Erstellt ein Kanban-Ticket. Nutze dies wenn Steffi eine konkrete Aufgabe erwähnt.',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string', 'description' => 'Titel der Aufgabe'],
                    'type' => ['type' => 'string', 'enum' => ['task', 'bug', 'feature', 'research', 'writing', 'email'], 'default' => 'task'],
                    'priority' => ['type' => 'string', 'enum' => ['critical', 'high', 'medium', 'low'], 'default' => 'medium'],
                    'emotional_charge' => ['type' => 'string', 'enum' => ['low', 'medium', 'high'], 'default' => 'low'],
                    'due_date' => ['type' => 'string', 'description' => 'Fälligkeitsdatum YYYY-MM-DD oder YYYY-MM-DD HH:MM'],
                    'description' => ['type' => 'string', 'description' => 'Beschreibung der Aufgabe'],
                    'assigned_to' => ['type' => 'string', 'enum' => ['human', 'agent'], 'default' => 'human'],
                    'agent_skill' => ['type' => 'string', 'description' => 'Skill für Agent-Tickets'],
                ],
                'required' => ['title'],
            ],
            'handler' => fn (array $args) => $this->toolCreateTicket($args),
        ];

        $this->tools['list_tickets'] = [
            'description' => 'Zeigt offene Kanban-Tickets. Optional nach Status filtern.',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'status' => ['type' => 'string', 'description' => 'Filter: backlog, todo, ready_for_agent, in_progress, review, done'],
                    'limit' => ['type' => 'integer', 'default' => 20],
                ],
            ],
            'handler' => fn (array $args) => $this->toolListTickets($args),
        ];

        $this->tools['search_vault'] = [
            'description' => 'Durchsucht das Obsidian Vault nach Notizen.',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Suchbegriff'],
                ],
                'required' => ['query'],
            ],
            'handler' => fn (array $args) => $this->toolSearchVault($args),
        ];

        $this->tools['read_note'] = [
            'description' => 'Liest den Inhalt einer Vault-Notiz.',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Relativer Pfad der Notiz (z.B. "inbox/test.md")'],
                ],
                'required' => ['path'],
            ],
            'handler' => fn (array $args) => $this->toolReadNote($args),
        ];

        $this->tools['get_daily_context'] = [
            'description' => 'Gibt den aktuellen Tageskontext zurück: offene Tickets, fällige Aufgaben, WIP-Status, gestrige Summary. Nutze dies am Anfang eines Daily Check-ins.',
            'schema' => [
                'type' => 'object',
                'properties' => new \stdClass(),
            ],
            'handler' => fn (array $args) => $this->toolGetDailyContext($args),
        ];

        $this->tools['save_checkin'] = [
            'description' => 'Speichert das Daily Check-in als Vault-Notiz. Nutze dies am Ende eines Check-in-Gesprächs.',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'mood' => ['type' => 'string', 'description' => 'Stimmung (great, good, okay, tired, stressed, bad, motivated)'],
                    'energy' => ['type' => 'integer', 'description' => 'Energie-Level 1-5'],
                    'summary' => ['type' => 'string', 'description' => 'Zusammenfassung des Check-ins'],
                    'plan' => ['type' => 'string', 'description' => 'Tagesplan als Text oder JSON Array [{task, priority}]'],
                    'motto_goal' => ['type' => 'string', 'description' => 'Motto-Ziel für den Tag'],
                ],
                'required' => ['mood', 'summary'],
            ],
            'handler' => fn (array $args) => $this->toolSaveCheckin($args),
        ];

        $this->tools['get_biography'] = [
            'description' => 'Gibt Steffis Profil zurück (Stammdaten, Vorlieben, Kontext). Nutze dies am Anfang eines Coaching-Gesprächs.',
            'schema' => [
                'type' => 'object',
                'properties' => new \stdClass(),
            ],
            'handler' => fn (array $args) => $this->toolGetBiography($args),
        ];

        $this->tools['recall_coach_memory'] = [
            'description' => 'Semantische Suche im Coach-Memory: gibt frühere Beobachtungen, Muster und Insights zurück, die zur Anfrage passen.',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Was du suchen willst, in natürlicher Sprache'],
                    'limit' => ['type' => 'integer', 'default' => 5],
                ],
                'required' => ['query'],
            ],
            'handler' => fn (array $args) => $this->toolRecallMemory($args),
        ];

        $this->tools['store_coach_memory'] = [
            'description' => 'Speichert eine Beobachtung, Muster oder Insight als Coach-Memory. Nutze dies während/nach Sessions, um Erkenntnisse über Steffi festzuhalten.',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'content' => ['type' => 'string', 'description' => 'Die Beobachtung in deinen eigenen Worten'],
                    'type' => ['type' => 'string', 'enum' => ['session', 'insight', 'pattern', 'consolidation'], 'default' => 'insight'],
                    'metadata' => ['type' => 'object', 'description' => 'Optional: relevante IDs, mood, etc.'],
                ],
                'required' => ['content'],
            ],
            'handler' => fn (array $args) => $this->toolStoreMemory($args),
        ];

        $this->tools['list_recent_memories'] = [
            'description' => 'Listet die N letzten Coach-Memories chronologisch.',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'limit' => ['type' => 'integer', 'default' => 20],
                ],
            ],
            'handler' => fn (array $args) => $this->toolListMemories($args),
        ];

        $this->tools['get_recent_checkins'] = [
            'description' => 'Gibt die letzten Daily-Checkins zurück (Mood, Energy, Plan, Summary). Nutze dies für Continuity zwischen Sessions.',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'days' => ['type' => 'integer', 'default' => 7, 'description' => 'Wie weit zurück (in Tagen)'],
                ],
            ],
            'handler' => fn (array $args) => $this->toolRecentCheckins($args),
        ];

        $this->tools['get_psych_trends'] = [
            'description' => 'Gibt psychologische Trends zurück: Mood/Energy Verlauf, Completion-Rate, Postpone-Muster, WIP-Schnitt, "stuck" Tickets.',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'days' => ['type' => 'integer', 'default' => 30],
                ],
            ],
            'handler' => fn (array $args) => $this->toolPsychTrends($args),
        ];
    }

    // --- Tool implementations ---

    private function toolCreateTicket(array $args): string
    {
        $tickets = app(\App\Services\TicketService::class);

        $dueDate = null;
        if (! empty($args['due_date'])) {
            try { $dueDate = Carbon::parse($args['due_date'])->format('Y-m-d'); } catch (\Throwable) {}
        }

        $ticket = $tickets->create([
            'title' => $args['title'],
            'type' => $args['type'] ?? 'task',
            'priority' => $args['priority'] ?? 'medium',
            'status' => ($args['assigned_to'] ?? 'human') === 'agent' ? 'ready_for_agent' : 'todo',
            'assigned_to' => $args['assigned_to'] ?? 'human',
            'emotional_charge' => $args['emotional_charge'] ?? null,
            'agent_skill' => $args['agent_skill'] ?? null,
            'due_date' => $dueDate,
            'description' => $args['description'] ?? '',
        ]);

        return "Ticket erstellt: {$ticket->title}\nID: #{$ticket->id}\nPriorität: {$ticket->priority}";
    }

    private function toolListTickets(array $args): array
    {
        $query = \App\Models\Ticket::query()
            ->orderByRaw("CASE priority WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 5 END")
            ->orderBy('due_date');

        if (! empty($args['status'])) {
            $query->where('status', $args['status']);
        } else {
            $query->where('status', '!=', 'done');
        }

        return $query->limit($args['limit'] ?? 20)
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'title' => $t->title,
                'status' => $t->status,
                'priority' => $t->priority,
                'due_date' => $t->due_date?->format('d.m.Y'),
                'assigned_to' => $t->assigned_to,
                'type' => $t->type,
            ])
            ->toArray();
    }

    private function toolSearchVault(array $args): array
    {
        $vault = app(VaultManager::class);
        $results = $vault->search($args['query']);

        return $results->map(fn ($n) => [
            'title' => $n->title,
            'path' => $n->relative_path,
            'folder' => $n->vault_folder,
            'preview' => mb_substr($n->body_preview ?? '', 0, 200),
        ])->take(15)->toArray();
    }

    private function toolReadNote(array $args): string
    {
        $vault = app(VaultManager::class);
        $note = $vault->readNote($args['path']);

        if (! $note) {
            return "Notiz nicht gefunden: {$args['path']}";
        }

        $fm = $note->frontmatter ? "---\n" . json_encode($note->frontmatter, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n---\n\n" : '';
        return $fm . $note->body;
    }

    private function toolGetDailyContext(array $args): string
    {
        $checkinService = app(DailyCheckinService::class);
        $vault = app(VaultManager::class);

        // Load biography
        $bio = $vault->readNote('Stefanie.md');
        $bioSection = $bio ? "## Biografie\n" . mb_substr($bio->body, 0, 2000) . "\n\n" : '';

        // Gather day context (reuse the existing method via reflection or rebuild)
        $lines = [];

        // Due today / overdue — only human tasks
        $dueTodayOrOverdue = \App\Models\Ticket::query()
            ->whereNotNull('due_date')
            ->where('due_date', '<=', now()->endOfDay())
            ->where('status', '!=', 'done')
            ->where('assigned_to', '!=', 'agent')
            ->orderBy('due_date')
            ->get();

        if ($dueTodayOrOverdue->isNotEmpty()) {
            $lines[] = "## Fällig heute / überfällig";
            foreach ($dueTodayOrOverdue as $t) {
                $overdue = $t->due_date->isPast() ? ' ⚠️ ÜBERFÄLLIG' : '';
                $lines[] = "- {$t->title} ({$t->priority}, fällig: {$t->due_date->format('d.m.')}){$overdue}";
            }
        }

        // Open human tasks
        $openTickets = \App\Models\Ticket::query()
            ->whereIn('status', ['todo', 'in_progress'])
            ->where('assigned_to', '!=', 'agent')
            ->orderByRaw("CASE priority WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 5 END")
            ->limit(15)
            ->get();

        if ($openTickets->isNotEmpty()) {
            $lines[] = "\n## Offene Aufgaben";
            foreach ($openTickets as $t) {
                $extra = [];
                if ($t->emotional_charge === 'high') $extra[] = 'hohe emotionale Ladung';
                if ($t->postpone_count >= 2) $extra[] = "{$t->postpone_count}x verschoben";
                $suffix = $extra ? ' (' . implode(', ', $extra) . ')' : '';
                $lines[] = "- [{$t->status}] {$t->title} ({$t->priority}){$suffix}";
            }
        }

        // Agent tickets — separate section, just for info
        $agentTickets = \App\Models\Ticket::query()
            ->whereIn('status', ['ready_for_agent', 'in_progress', 'review'])
            ->where('assigned_to', 'agent')
            ->limit(5)
            ->get();

        if ($agentTickets->isNotEmpty()) {
            $lines[] = "\n## Agent-Aufgaben (Info)";
            foreach ($agentTickets as $t) {
                $lines[] = "- [{$t->status}] {$t->title}";
            }
        }

        // Yesterday's checkin
        $yesterday = DailyCheckin::where('date', today()->subDay())
            ->whereNotNull('completed_at')
            ->first();
        if ($yesterday?->summary) {
            $lines[] = "\n## Gestern\nStimmung: {$yesterday->mood}, Energie: {$yesterday->energy}/5\n{$yesterday->summary}";
        }

        $context = implode("\n", $lines) ?: 'Keine offenen Aufgaben.';

        return "# Tageskontext — " . now()->locale('de')->isoFormat('dddd, D. MMMM YYYY') . "\n\n{$bioSection}{$context}";
    }

    private function toolSaveCheckin(array $args): string
    {
        $checkin = DailyCheckin::where('date', today())->first();

        if (! $checkin) {
            $checkin = DailyCheckin::create([
                'date' => today(),
                'messages' => [],
            ]);
        }

        // Parse plan
        $plan = [];
        if (! empty($args['plan'])) {
            $parsed = json_decode($args['plan'], true);
            if (is_array($parsed)) {
                $plan = $parsed;
            } else {
                // Plain text — split by lines
                foreach (explode("\n", $args['plan']) as $line) {
                    $line = trim($line, "- \t");
                    if ($line) $plan[] = ['task' => $line, 'priority' => 'medium'];
                }
            }
        }

        $checkin->update([
            'mood' => $args['mood'] ?? $checkin->mood,
            'energy' => $args['energy'] ?? $checkin->energy,
            'summary' => $args['summary'] ?? $checkin->summary,
            'plan' => $plan ?: $checkin->plan,
            'motto_goal' => $args['motto_goal'] ?? $checkin->motto_goal,
            'completed_at' => now(),
        ]);

        return "Check-in gespeichert.\nStimmung: {$checkin->mood}, Energie: {$checkin->energy}/5\nMotto: {$checkin->motto_goal}";
    }

    private function toolGetBiography(array $args): string
    {
        $profile = app(\App\Services\SettingsService::class)->getProfile();
        return $profile !== '' ? $profile : 'Profil ist leer (in den Dashboard-Settings befüllen).';
    }

    private function toolRecallMemory(array $args): array
    {
        $svc = app(\App\Services\CoachMemoryService::class);
        $memories = $svc->recall($args['query'], $args['limit'] ?? 5);

        return $memories->map(fn ($m) => [
            'id' => $m->id,
            'type' => $m->type,
            'date' => $m->session_date?->toDateString(),
            'content' => $m->content,
            'metadata' => $m->metadata,
        ])->all();
    }

    private function toolStoreMemory(array $args): string
    {
        $svc = app(\App\Services\CoachMemoryService::class);
        $m = $svc->store(
            $args['content'],
            $args['type'] ?? 'insight',
            $args['metadata'] ?? null,
        );

        return "Memory gespeichert (#{$m->id}, type={$m->type})";
    }

    private function toolListMemories(array $args): array
    {
        $svc = app(\App\Services\CoachMemoryService::class);
        return $svc->listRecent($args['limit'] ?? 20)
            ->map(fn ($m) => [
                'id' => $m->id,
                'type' => $m->type,
                'date' => $m->session_date?->toDateString(),
                'content' => $m->content,
            ])->all();
    }

    private function toolRecentCheckins(array $args): array
    {
        $days = $args['days'] ?? 7;

        return DailyCheckin::query()
            ->whereNotNull('completed_at')
            ->where('date', '>=', now()->subDays($days)->toDateString())
            ->orderByDesc('date')
            ->get(['date', 'mood', 'energy', 'motto_goal', 'plan', 'summary'])
            ->map(fn ($c) => [
                'date' => $c->date->toDateString(),
                'mood' => $c->mood,
                'energy' => $c->energy,
                'motto_goal' => $c->motto_goal,
                'plan' => $c->plan,
                'summary' => $c->summary,
            ])->all();
    }

    private function toolPsychTrends(array $args): array
    {
        $days = $args['days'] ?? 30;
        $since = now()->subDays($days);

        $checkins = DailyCheckin::query()
            ->whereNotNull('completed_at')
            ->where('date', '>=', $since->toDateString())
            ->orderBy('date')
            ->get(['date', 'mood', 'energy']);

        $completed = \App\Models\TaskEvent::where('event_type', 'status_changed')
            ->where('to_status', 'done')
            ->where('created_at', '>=', $since)
            ->count();

        $postponements = \App\Models\TaskEvent::where('event_type', 'postponed')
            ->where('created_at', '>=', $since)
            ->count();

        $stuckTickets = \App\Models\Ticket::where('postpone_count', '>=', 2)
            ->whereNotIn('status', ['done'])
            ->orderByDesc('postpone_count')
            ->limit(10)
            ->get(['id', 'title', 'status', 'postpone_count', 'emotional_charge']);

        $avgWip = \App\Models\Ticket::whereIn('status', ['in_progress', 'ready_for_agent'])->count();

        return [
            'window_days' => $days,
            'mood_energy' => $checkins->map(fn ($c) => [
                'date' => $c->date->toDateString(),
                'mood' => $c->mood,
                'energy' => $c->energy,
            ])->all(),
            'tickets_completed' => $completed,
            'postponements' => $postponements,
            'current_wip' => $avgWip,
            'stuck_tickets' => $stuckTickets->toArray(),
        ];
    }

    // --- JSON-RPC helpers ---

    private function jsonRpcResult(mixed $id, array $result): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ];
    }

    private function jsonRpcError(mixed $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];
    }
}
