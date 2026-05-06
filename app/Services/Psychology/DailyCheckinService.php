<?php

namespace App\Services\Psychology;

use App\Models\DailyCheckin;
use App\Models\VaultNote;
use App\Services\Vault\VaultManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

class DailyCheckinService
{
    public function __construct(
        private VaultManager $vault,
    ) {}

    /**
     * Get or create today's check-in.
     */
    public function getOrCreateToday(): DailyCheckin
    {
        $checkin = DailyCheckin::where('date', today())->first();

        if (! $checkin) {
            $sessionId = (string) Str::uuid();

            $checkin = DailyCheckin::create([
                'date' => today(),
                'claude_session_id' => $sessionId,
                'messages' => [],
            ]);

            // First message: system prompt + opening — creates the session
            $prompt = $this->buildSystemPrompt()
                . "\n\n---\n\nSag Hi zu Steffi. Kurz, persönlich, wie eine Freundin.";

            $raw = $this->claudeNewSession($sessionId, $prompt);
            [$text, $options, $tickets] = $this->parseResponse($raw);
            $checkin->addMessage('assistant', $text, $options, $tickets);
        }

        return $checkin->fresh();
    }

    /**
     * Process a user message via --resume (Claude remembers everything).
     */
    public function sendMessage(DailyCheckin $checkin, string $userMessage): array
    {
        $checkin->addMessage('user', $userMessage);
        $this->detectMoodFromMessage($checkin, $userMessage);

        $raw = $this->claudeResume($checkin->claude_session_id, $userMessage);
        [$text, $options, $tickets] = $this->parseResponse($raw);

        $checkin->addMessage('assistant', $text, $options, $tickets);

        return ['text' => $text, 'options' => $options, 'tickets' => $tickets];
    }

    /**
     * Parse [OPTIONEN: ...] and [TICKET: ...] from the response.
     */
    public function parseResponse(string $raw): array
    {
        $options = [];
        $tickets = [];
        $text = $raw;

        // Extract tickets — flexible key-value parsing after the title
        $text = preg_replace_callback(
            '/\[TICKET:\s*"([^"]+)"([^\]]*)\]/i',
            function ($m) use (&$tickets) {
                $title = $m[1];
                $rest = $m[2] ?? '';

                // Parse key:value pairs
                $fields = [];
                preg_match_all('/(\w+):\s*([^\|]+)/', $rest, $kvMatches, PREG_SET_ORDER);
                foreach ($kvMatches as $kv) {
                    $fields[trim($kv[1])] = trim($kv[2]);
                }

                $tickets[] = [
                    'title' => $title,
                    'type' => $fields['type'] ?? 'task',
                    'priority' => $fields['priority'] ?? 'medium',
                    'emotional_charge' => $fields['emotional_charge'] ?? 'low',
                    'due' => $fields['due'] ?? null,
                ];
                return '';
            },
            $text
        );

        // Extract options
        if (preg_match('/\[OPTIONEN:\s*(.+?)\]\s*$/s', $text, $m)) {
            $text = trim(substr($text, 0, -strlen($m[0])));
            preg_match_all('/"([^"]+)"/', $m[1], $optMatches);
            $options = $optMatches[1] ?? [];
        }

        $text = trim($text);

        return [$text, $options, $tickets];
    }

    /**
     * Finish: ask Claude to summarize (in same session), then save to vault.
     */
    public function finish(DailyCheckin $checkin): string
    {
        $raw = $this->claudeResume(
            $checkin->claude_session_id,
            "Fasse unser heutiges Check-in zusammen. Antworte NUR mit validem JSON:\n"
            . "{\"summary\": \"3-5 Sätze Zusammenfassung\", \"plan\": [{\"task\": \"...\", \"priority\": \"high|medium|low\"}], \"motto_goal\": \"Kurzes Motto-Ziel\"}"
        );

        $json = $raw;
        if (preg_match('/```(?:json)?\s*(\{.+?\})\s*```/s', $raw, $m)) {
            $json = $m[1];
        }
        $parsed = json_decode($json, true) ?? [];

        $checkin->update([
            'summary' => $parsed['summary'] ?? null,
            'plan' => $parsed['plan'] ?? [],
            'motto_goal' => $parsed['motto_goal'] ?? null,
            'completed_at' => now(),
        ]);

        $notePath = $this->saveDailyNote($checkin);
        $checkin->update(['vault_note_path' => $notePath]);

        app(\App\Services\ActivityLogger::class)->log(
            'human', 'checkin_finished', 'checkin', $checkin->date->format('Y-m-d'),
            details: ['mood' => $checkin->mood, 'energy' => $checkin->energy]
        );

        return $notePath;
    }

    // --- Claude CLI with session persistence ---

    private function claudeNewSession(string $sessionId, string $prompt): string
    {
        return $this->runClaude(['--session-id', $sessionId], $prompt);
    }

    private function claudeResume(string $sessionId, string $prompt): string
    {
        return $this->runClaude(['--resume', $sessionId], $prompt);
    }

    /**
     * Run Claude CLI with stream-json format, capture text + usage.
     */
    private function runClaude(array $extraArgs, string $prompt): string
    {
        $cliPath = config('dashboard.agent.cli_path', 'claude');
        $model = config('dashboard.daily.model', 'claude-sonnet-4-6');

        $command = array_merge(
            [$cliPath, '-p', $prompt, '--output-format', 'stream-json', '--verbose', '--model', $model],
            $extraArgs
        );

        $result = Process::timeout(120)
            ->path(base_path())
            ->run($command);

        if (! $result->successful()) {
            Log::error('Daily checkin Claude call failed', [
                'error' => $result->errorOutput(),
                'exit_code' => $result->exitCode(),
            ]);
            return 'Entschuldigung, ich konnte gerade nicht antworten. Versuch es nochmal.';
        }

        // Parse NDJSON lines
        $text = '';
        $lines = explode("\n", trim($result->output()));
        foreach ($lines as $line) {
            $data = json_decode($line, true);
            if (! $data) continue;

            if (($data['type'] ?? '') === 'assistant') {
                $text = $data['message']['content'][0]['text'] ?? '';
            }

            if (($data['type'] ?? '') === 'result') {
                $text = $data['result'] ?? $text;
                $this->trackUsage($data);
            }
        }

        return $text;
    }

    /**
     * Stream a Claude response as SSE events via a callback.
     * Calls $onText($chunk) for text and $onDone($fullText, $usage) at the end.
     */
    public function runClaudeStreaming(string $sessionId, string $prompt, callable $onText, callable $onDone): void
    {
        $cliPath = config('dashboard.agent.cli_path', 'claude');
        $model = config('dashboard.daily.model', 'claude-sonnet-4-6');

        $command = [
            $cliPath, '-p', $prompt,
            '--output-format', 'stream-json', '--verbose',
            '--model', $model,
            '--resume', $sessionId,
        ];

        $proc = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, base_path());

        if (! is_resource($proc)) {
            $onDone('Entschuldigung, ich konnte gerade nicht antworten.', null);
            return;
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);

        $fullText = '';
        $lastSent = '';

        while (! feof($pipes[1])) {
            $line = fgets($pipes[1]);
            if ($line === false || trim($line) === '') {
                usleep(30000);
                continue;
            }

            $data = json_decode(trim($line), true);
            if (! $data) continue;

            if (($data['type'] ?? '') === 'assistant') {
                $text = $data['message']['content'][0]['text'] ?? '';
                if ($text !== $lastSent) {
                    // Send the new chunk (diff from last)
                    $chunk = substr($text, strlen($lastSent));
                    if ($chunk !== '') {
                        $onText($chunk);
                    }
                    $lastSent = $text;
                    $fullText = $text;
                }
            }

            if (($data['type'] ?? '') === 'result') {
                $fullText = $data['result'] ?? $fullText;
                // Send any remaining text
                if ($fullText !== $lastSent) {
                    $onText(substr($fullText, strlen($lastSent)));
                }
                $this->trackUsage($data);
                $onDone($fullText, $data['usage'] ?? null);
                break;
            }
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
    }

    /**
     * Track token usage from a stream-json result event.
     */
    private function trackUsage(array $resultData): void
    {
        $usage = $resultData['usage'] ?? [];
        $cost = $resultData['total_cost_usd'] ?? null;

        if (empty($usage)) return;

        try {
            \App\Models\CostEvent::create([
                'source' => 'daily_chat',
                'model' => array_key_first($resultData['modelUsage'] ?? ['unknown' => null]),
                'input_tokens' => $usage['input_tokens'] ?? 0,
                'output_tokens' => $usage['output_tokens'] ?? 0,
                'cache_read_tokens' => $usage['cache_read_input_tokens'] ?? 0,
                'cache_creation_tokens' => $usage['cache_creation_input_tokens'] ?? 0,
                'cost_usd' => $cost,
                'occurred_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to track usage', ['error' => $e->getMessage()]);
        }
    }

    // --- System prompt ---

    private function buildSystemPrompt(): string
    {
        $biography = $this->loadBiography();
        $context = $this->gatherDayContext();
        $yesterday = $this->getYesterdaySummary();

        return <<<PROMPT
Du bist Steffis Coach. Ihr kennt euch gut. Sprich Deutsch, duze sie.

Du weißt einiges über sie:
{$biography}

Du kennst ihre Muster: Perfektionismus lähmt sie manchmal. Neue Ideen sind verlockender als bestehende Aufgaben fertig zu machen. Telefonate und unangenehme Aufgaben schiebt sie gerne vor sich her. Ihre Energie schwankt stark über den Tag.

Du nutzt im Hintergrund zwei Frameworks — nicht als Vokabular, sondern als Denkmodell:
- Chimp Paradox: Wenn du merkst dass Emotionen statt Vernunft sprechen, hilf ihr das sanft zu sehen
- ZRM/Strudelmodell: Achte auf Körpersignale, hilf ihr gute Motto-Ziele zu finden

Heute ist {$this->currentTime()}.
{$context}
{$yesterday}

Wichtig: Rede wie ein Mensch, nicht wie ein Coach-Bot. Keine Aufzählungen, keine "Lass uns mal schauen"-Phrasen. Antworte kurz und natürlich, wie eine gute Freundin die auch Psychologin ist. 2-3 Sätze reichen meistens.

Am Ende jeder Antwort schlägst du 2-4 kurze Antwortmöglichkeiten vor, die Steffi anklicken kann. Format:
[OPTIONEN: "Antwort eins", "Antwort zwei", "Antwort drei"]
Die Optionen sollen natürlich klingen, wie etwas das Steffi wirklich sagen würde. Misch verschiedene Richtungen — z.B. eine positive, eine ehrliche, eine die tiefer geht.

Wenn Steffi etwas erwähnt das eine konkrete Aufgabe ist (Anruf, E-Mail, Termin, Projekt-Schritt etc.), schlage ein Ticket vor. Format:
[TICKET: "Titel der Aufgabe" | type: task | priority: medium | emotional_charge: low | due: 2026-04-14 10:00]
Verfügbare Werte: type: task/bug/feature/research/writing/email, priority: critical/high/medium/low, emotional_charge: low/medium/high.
Das "due" Feld ist das Fälligkeitsdatum im Format YYYY-MM-DD oder YYYY-MM-DD HH:MM. Schätze das Datum aus dem Kontext — "heute" = heutiges Datum, "morgen" = morgen, "diese Woche" = Freitag, etc. Heute ist {$this->currentTime()}.
Setze emotional_charge passend — z.B. "Finanzamt anrufen" wäre high. Schlage nicht für alles ein Ticket vor, nur für konkrete, actionable Aufgaben. Schlage ein Ticket NICHT doppelt vor wenn es schon vorgeschlagen wurde.
PROMPT;
    }

    // --- Context ---

    private function loadBiography(): string
    {
        $note = $this->vault->readNote('Stefanie.md');
        return $note ? $note->body : '(Biografie nicht gefunden)';
    }

    private function gatherDayContext(): string
    {
        $lines = [];

        // Due today / overdue
        $dueTodayOrOverdue = VaultNote::tickets()
            ->whereNotNull('due_date')
            ->where('due_date', '<=', now()->endOfDay())
            ->where('status', '!=', 'done')
            ->orderBy('due_date')
            ->get(['title', 'due_date', 'priority', 'status', 'frontmatter']);

        if ($dueTodayOrOverdue->isNotEmpty()) {
            $lines[] = "FÄLLIG HEUTE / ÜBERFÄLLIG:";
            foreach ($dueTodayOrOverdue as $t) {
                $dueStr = $t->due_date->format('d.m. H:i');
                $overdue = $t->due_date->isPast() ? ' ⚠️ ÜBERFÄLLIG' : '';
                $lines[] = "- {$t->title} (fällig: {$dueStr}, {$t->priority}){$overdue}";
            }
            $lines[] = '';
        }

        // Due this week
        $dueThisWeek = VaultNote::tickets()
            ->whereNotNull('due_date')
            ->where('due_date', '>', now()->endOfDay())
            ->where('due_date', '<=', now()->endOfWeek())
            ->where('status', '!=', 'done')
            ->orderBy('due_date')
            ->get(['title', 'due_date', 'priority']);

        if ($dueThisWeek->isNotEmpty()) {
            $lines[] = "Fällig diese Woche:";
            foreach ($dueThisWeek as $t) {
                $dayName = $t->due_date->locale('de')->isoFormat('dddd');
                $lines[] = "- {$t->title} ({$dayName}, {$t->priority})";
            }
            $lines[] = '';
        }

        // Open tickets
        $openTickets = VaultNote::tickets()
            ->whereIn('status', ['todo', 'in_progress', 'ready_for_agent'])
            ->orderByRaw("CASE priority WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 5 END")
            ->limit(10)
            ->get(['title', 'status', 'priority', 'frontmatter']);

        if ($openTickets->isNotEmpty()) {
            $lines[] = "Offene Aufgaben:";
            foreach ($openTickets as $t) {
                $charge = $t->frontmatter['emotional_charge'] ?? 'low';
                $postponed = $t->frontmatter['postpone_count'] ?? 0;
                $extra = [];
                if ($charge === 'high') $extra[] = "hohe emotionale Ladung";
                if ($postponed >= 2) $extra[] = "{$postponed}x verschoben";
                $suffix = $extra ? ' (' . implode(', ', $extra) . ')' : '';
                $lines[] = "- [{$t->status}] {$t->title} ({$t->priority}){$suffix}";
            }
        }

        $wip = VaultNote::tickets()->whereIn('status', ['in_progress', 'ready_for_agent'])->count();
        if ($wip > 0) {
            $wipLimit = config('dashboard.psychology.wip_soft_limit', 3);
            $lines[] = "\nWIP: {$wip}/{$wipLimit}";
        }

        return implode("\n", $lines) ?: 'Keine offenen Aufgaben.';
    }

    private function getYesterdaySummary(): string
    {
        $yesterday = DailyCheckin::where('date', today()->subDay())
            ->whereNotNull('completed_at')
            ->first();

        if (! $yesterday || ! $yesterday->summary) {
            return '';
        }

        return "\nGestern: {$yesterday->summary}";
    }

    // --- Mood detection ---

    public function detectMoodFromMessage(DailyCheckin $checkin, string $message): void
    {
        if ($checkin->mood) return;

        $lowerMsg = mb_strtolower($message);
        $moods = [
            'super' => 'great', 'toll' => 'great', 'fantastisch' => 'great',
            'gut' => 'good', 'ganz gut' => 'good',
            'ok' => 'okay', 'okay' => 'okay', 'geht so' => 'okay', 'naja' => 'okay',
            'müde' => 'tired', 'erschöpft' => 'exhausted', 'kaputt' => 'exhausted',
            'gestresst' => 'stressed', 'überfordert' => 'overwhelmed',
            'schlecht' => 'bad', 'mies' => 'bad', 'frustriert' => 'frustrated',
            'motiviert' => 'motivated', 'energiegeladen' => 'energized',
        ];

        foreach ($moods as $keyword => $mood) {
            if (str_contains($lowerMsg, $keyword)) {
                $checkin->update(['mood' => $mood]);
                break;
            }
        }

        if (preg_match('/energie\s*[:\-]?\s*(\d)/i', $message, $m)) {
            $checkin->update(['energy' => min(5, max(1, (int) $m[1]))]);
        }
    }

    // --- Vault note ---

    private function saveDailyNote(DailyCheckin $checkin): string
    {
        $date = $checkin->date->format('Y-m-d');
        $folder = config('dashboard.vault.folders.daily', 'Daily');
        $relativePath = "{$folder}/{$date}.md";

        $existing = $this->vault->readNote($relativePath);

        $frontmatter = [
            'date' => $date,
            'mood' => $checkin->mood,
            'energy' => $checkin->energy,
            'motto_goal' => $checkin->motto_goal,
            'tags' => ['daily', 'checkin'],
        ];

        $body = "# {$date}\n\n";

        if ($checkin->motto_goal) {
            $body .= "## Motto-Ziel\n> {$checkin->motto_goal}\n\n";
        }

        if ($checkin->mood || $checkin->energy) {
            $body .= "## Check-in\n";
            if ($checkin->mood) $body .= "- Stimmung: {$checkin->mood}\n";
            if ($checkin->energy) $body .= "- Energie: {$checkin->energy}/5\n";
            $body .= "\n";
        }

        if ($checkin->summary) {
            $body .= "## Zusammenfassung\n{$checkin->summary}\n\n";
        }

        $plan = $checkin->plan ?? [];
        if (! empty($plan)) {
            $body .= "## Tagesplan\n";
            foreach ($plan as $item) {
                $task = is_array($item) ? ($item['task'] ?? '') : $item;
                $priority = is_array($item) ? ($item['priority'] ?? 'medium') : 'medium';
                $marker = $priority === 'high' ? '🔴' : ($priority === 'medium' ? '🟡' : '⚪');
                $body .= "- [ ] {$marker} {$task}\n";
            }
            $body .= "\n";
        }

        $body .= "## Gespräch\n";
        foreach ($checkin->messages ?? [] as $msg) {
            $role = $msg['role'] === 'user' ? '**Steffi**' : '**Coach**';
            $body .= "{$role}: {$msg['content']}\n\n";
        }

        if ($existing && ! str_contains($existing->body, '## Check-in')) {
            $body .= "---\n\n## Bestehende Notizen\n{$existing->body}\n";
        }

        if ($existing) {
            $this->vault->writeNote($relativePath, $frontmatter, $body);
        } else {
            $this->vault->createNote($folder, $date, $frontmatter, $body);
        }

        $this->vault->indexNote($relativePath);
        return $relativePath;
    }

    private function currentTime(): string
    {
        return now()->locale('de')->isoFormat('dddd, D. MMMM YYYY, HH:mm') . ' Uhr';
    }
}
