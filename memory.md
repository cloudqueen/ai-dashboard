# Steffi AI Dashboard — Session Memory

## Project Overview
Personal AI command center built with **Laravel 13 + React + Inertia.js**. Uses an Obsidian vault as its memory system, provides a Kanban board for task management, and runs autonomous AI agents via Claude CLI (`claude -p`). Will include psychological frameworks to combat procrastination.

## Repository
- **Repo**: `cloudqueen/ai-dashboard`
- **Branch**: `claude/plan-ai-dashboard-yyImB`
- **Session**: `session_01NKBxDvD6dLYSGUvNvXCgv1`

## Key Decisions (from user)
- **Stack**: Laravel 13.4.0 + React + Inertia.js, SQLite, Laravel Queue (database driver)
- **Vault path**: Configurable via `.env` (user's Mac: `/Users/steffi/Obsidian/Steffi-secondbrain`)
- **Vault repo**: `git@github.com:cloudqueen/Steffi-secondbrain.git` (synced between 2 machines via git)
- **Auth**: Full Laravel login (Breeze with React/TypeScript/dark mode)
- **UI**: Dark minimal theme (gray-900/950 backgrounds, indigo accents)
- **Tickets**: Tag-based — any vault note becomes a ticket via frontmatter (no dedicated folder)
- **Email**: Generic IMAP, Google Calendar (Phase 2)
- **Agents**: `claude -p` executed via Laravel queue jobs on dedicated `agent` queue
- **Telegram**: Future phase

## User's Procrastination Patterns
- **Primary**: Perfectionism paralysis, shiny object syndrome
- **All patterns present**: avoidance of ambiguous tasks, emotional overwhelm, energy mismatch
- **Specific trigger**: User's "chimp" hates phone calls (high emotional_charge tasks)
- **Frameworks**: Kahneman System 1/2, Chimp Paradox (Steve Peters), Strudelmodell/ZRM (Maja Storch)

## Environment
- PHP 8.4.19, Node 22.22.2, Composer 2.8, npm 10.9.7, pnpm 10.33.0
- Claude CLI 2.1.104
- Linux dev environment (cloud), user runs macOS locally

## What's Been Built (Phases 0–1D)

### Phase 0: Scaffold
- Laravel 13 project at repo root
- Breeze auth with React/TypeScript/dark mode
- Custom sidebar layout: Dashboard, Kanban, Vault, Agents, Settings
- `config/dashboard.php` with vault/agent/psychology/kanban config sections

### Phase 1A: Vault Integration (Memory System)
**Services** (`app/Services/Vault/`):
- `VaultManager.php` — high-level API: readNote, writeNote, updateFrontmatter, indexNote, indexAll, search, resolveWikilink, createNote. Uses atomic file writes (flock + temp file + rename)
- `MarkdownParser.php` — parses YAML frontmatter (via symfony/yaml), extracts body, extracts `[[wikilinks]]` with `|display` syntax
- `MarkdownWriter.php` — serializes frontmatter + body back to .md
- `GitSync.php` — pull/push with `Cache::lock('vault-git-sync', 120)` mutex, stash handling, conflict detection
- `ParsedNote.php` / `SyncResult.php` — value objects

**Database**: `vault_notes` table (disposable index, rebuildable):
- relative_path (unique), title, vault_folder, frontmatter (json), body_preview, status, priority, type, assigned_to, tags (json), due_date, content_hash (sha256), vault_modified_at

**Commands**: `vault:index` (full or `--changed-only`), `vault:sync`
**Scheduler**: vault:sync every 5min, vault:index --changed-only every 30min

**Frontend**: Vault/Index.tsx (browse/search with folder sidebar, pagination), Vault/Show.tsx (view/edit with frontmatter sidebar, wikilinks panel)

**Safety**: Never touches `.obsidian/`, file writes use flock, git ops use Cache::lock

### Phase 1B: Kanban Board
**Service** (`app/Services/Kanban/KanbanService.php`):
- getBoard(filters), moveTicket(path, newStatus), createTicket(data), promoteNote(path, ticketData), getFilterOptions()

**Columns**: Backlog → Todo → Ready for Agent → In Progress → Review → Done

**Ticket frontmatter schema**:
```yaml
type: task|bug|feature|research|writing|email
status: backlog|todo|ready_for_agent|in_progress|review|done
priority: critical|high|medium|low
assigned_to: human|agent
tags: [array]
due_date: YYYY-MM-DD
agent_skill: null|research|writing|coding|analysis|email
postpone_count: 0
emotional_charge: low|medium|high
system_level: 1|2
```

**Frontend**: Drag-and-drop via `@hello-pangea/dnd` with zustand-style optimistic updates. Cards show priority (color-coded border-left), type, assignee, due date, tags. New ticket form inline.

**Events**: Every status change creates a TaskEvent (for psychology pattern detection)

### Phase 1C: Agent Loop
**Services** (`app/Services/Agent/`):
- `AgentOrchestrator.php` — heartbeat(), dispatchReadyTickets(), canAcceptNewRun() (WIP limit), checkForTimedOutRuns(), inferSkill()
- `ClaudeRunner.php` — executes `claude -p "prompt" --output-format json` via Laravel Process
- `PromptBuilder.php` — builds prompt from ticket content + skill template + wikilinked context notes
- `OutputProcessor.php` — writes agent output as vault note in agent/outputs/, updates ticket to review status
- `AgentOutput.php` — value object

**Job**: `ExecuteAgentRun` — queue: `agent`, timeout: 1800s, tries: 1. Builds prompt → runs Claude → processes output or logs failure.

**Command**: `agent:heartbeat` scheduled every 3 minutes

**Database**:
- `agent_runs`: vault_note_id, ticket_path, skill, status (queued|running|completed|failed|timed_out), prompt, raw_output, summary, output_note_path, duration_seconds, error_message, started_at, completed_at
- `agent_skills`: name, display_name, description, system_prompt, prompt_template, required_context, enabled

**Default skills** (seeded): research, writing, coding, analysis, email

**Queue setup**: Two workers needed — `--queue=default` and `--queue=agent --timeout=1800`

### Phase 1D: Dashboard & Settings
**DashboardController**: Aggregates kanban summary (counts per status), due soon (next 7 days), agent status (active count + recent runs), recent activity (task events), vault connection status.

**Dashboard page**: 6 widget cards with real data, quick action links.

### Phase 1E: Infra — Activity Log + Event Bus + SSE
**Services** (`app/Services/`):
- `ActivityLogger.php` — append-only audit trail (actor, action, subject_type/id, details json)
- `DashboardEventBus.php` — emit() writes to `dashboard_events` table; consumed by SSE

**Controllers**:
- `EventStreamController.php` — SSE endpoint `/api/events/stream` (long-poll, ID-based cursor)

**Frontend**:
- `resources/js/hooks/useDashboardEvents.ts` — EventSource hook for live updates

**Tables**: `activity_log`, `dashboard_events`. CSRF-token meta added to `app.blade.php` for non-Inertia POSTs.

### Phase 1F: Agent Loop v2
**SkillLoader** (`app/Services/Agent/SkillLoader.php`, 271 LOC) — loads skill definitions from `skills/` folder in vault (markdown files with frontmatter), falls back to DB-seeded `agent_skills`. PromptBuilder uses these for system prompt + template.

**Cost tracking**:
- `CostEvent` model + `cost_events` table (agent_run_id, source, model, input/output/cache tokens, cost_usd, occurred_at)
- `OutputProcessor` extracts cost from `claude -p --output-format json` response and emits CostEvent
- `AGENT_MONTHLY_BUDGET_USD` config knob (config('dashboard.agent.monthly_budget_usd'))

**Prompt-builder enhancements**: richer context — wikilinked notes, skill template, ticket frontmatter, related tickets.

### Phase 1G: Kanban v2
New `KanbanController` endpoints:
- `GET /api/kanban/ticket/{path}` — show
- `POST/DELETE /api/kanban/link` — add/remove wikilinks in ticket frontmatter
- `PATCH /api/kanban/meta` — update priority/due_date/tags/etc.
- `DELETE /api/kanban/ticket` — delete (vault note + index entry)

`Kanban/Index.tsx` adds a side panel with inline ticket detail editing, link picker, deletion.

### Phase 1H: Daily Check-in + Psychology Engine
**DailyCheckinService** (`app/Services/Psychology/DailyCheckinService.php`, 528 LOC):
- Persistent Claude session per day via `--resume <session_id>`
- Mood detection from user messages (regex + heuristic)
- Parses Claude responses for `<options>` and `<ticket>` blocks → action buttons / ticket creation
- Streaming via `runClaudeStreaming()` (SSE infra reused for future Ollama)
- Writes daily summary as vault note when finished

**Controllers**: `DailyController` (REST), `DailyStreamController` (SSE)

**Tables**: `daily_checkins` (date unique, claude_session_id, messages json, mood, energy, plan, motto_goal, summary, vault_note_path)

**PsychEngine** (`app/Services/Psychology/PsychEngine.php`, 411 LOC):
- `evaluate(trigger, ticketPath)` — triggers: status_change, task_start, task_complete, dashboard_load, wip_exceeded
- `getDashboardInsights()` — completion stats (30d), postponement count, avoided high-charge tasks, current WIP, streak
- Generates `PsychIntervention` records (framework: kahneman/chimp/strudel, intervention_type, content, was_helpful)

**Controller**: `PsychController` — insights, active interventions, feedback (was_helpful), dismiss

**Page**: `Daily/Index.tsx` (588 LOC) — chat UI with mood picker, option buttons, inline ticket creation

**Config**: `daily.model` (default `claude-sonnet-4-6`), `psychology.enabled` env-driven

### Phase 1I: Routines (Cron-Driven Agent Runs)
**RoutineScheduler** (`app/Services/RoutineScheduler.php`):
- `checkAndDispatch()` — finds enabled routines where `isDue()` and no active run, dispatches them
- `dispatch(routine)` — creates kanban ticket (status=ready_for_agent, agent_skill from routine), creates RoutineRun record, updates next_run_at via cron expression
- `syncRunStatuses()` — links routine_runs to agent_runs by ticket_path, propagates status, emits `routine_completed` event

**Tables**: `routines` (cron_expression, skill, prompt_template, context_links json, model override, output_folder, last_run_at, next_run_at), `routine_runs` (routine_id, agent_run_id, ticket_path, status, summary, output_note_path)

**Command**: `routine:check` — scheduled `everyMinute()` in `routes/console.php`

**Controller**: `RoutineController` — index/store/update/destroy + manual trigger + runs list

**Page**: `Routines/Index.tsx` (331 LOC) — list, create/edit form, recent runs

**Dependency**: `dragonmantank/cron-expression` (via composer)

### Phase 1J: Skills UI + MCP Server + Misc
**Skills** (originally vault-based, moved in Phase 3 — see below):
- `SkillController` + `Skills/Index.tsx` (277 LOC) — browser/editor

**MCP Server** (`app/Console/Commands/McpServer.php`, ~440 LOC):
- `mcp:serve` command — STDIO MCP transport for Claude Desktop integration
- Tools exposed: vault read/search, kanban create/list, daily checkin update, biography (Stefanie.md)

**DashboardServe** (`app/Console/Commands/DashboardServe.php`):
- Convenience command to start Laravel server + queue workers + vite dev

### Phase 1K: Settings UI v1
**SettingsService** (`app/Services/SettingsService.php`) — typed wrappers around the `settings` key/value table with config-fallbacks.

**Settings page** (`/settings`): Vault status (path, sync time, notes count, "Sync now" button), Agent (CLI path, limits, monthly cost bar vs budget, **Pause toggle** — `AgentOrchestrator::dispatchReadyTickets()` skips when paused), Psychology (enabled toggle DB-overrides env, WIP soft/hard limits as inputs), Queue (pending/failed counts + "Retry failed").

**Endpoints**: `PATCH /api/settings/{key}` (whitelist: `agent.paused`, `psychology.enabled`, `psychology.wip_soft_limit`, `psychology.wip_hard_limit`), `POST /api/settings/sync-now`, `POST /api/settings/retry-failed`. VaultSync now records `vault.last_sync_at|status|error` via SettingsService.

### Phase 2: Tickets-in-DB refactor (architectural)
**Decision**: Tickets are first-class DB rows. The vault is a pure knowledge store; vault notes are referenced by tickets via `context_links` (paths) but are never themselves tickets.

**Storage migration**: dashboard outputs no longer pollute the vault. Two new locations under `storage/app/private/`:
- `dashboard/agent-outputs/` — written by `OutputProcessor` after each successful agent run
- `dashboard/skills/` — `SKILL.md` folders for file-based skills (DB-backed AgentSkills are still supported)

**Schema**: new `tickets` table (title, description, type, status, priority, assigned_to, agent_skill, tags json, due_date, postpone_count, emotional_charge, system_level, context_links json, depends_on json — array of ticket IDs, model, routine_id FK, source_vault_note_id FK, completed_at). All ticket-touching tables gained `ticket_id` FKs (agent_runs, routine_runs, task_events, psych_interventions). Phase 1 backfill: 8 tickets created from historical run paths.

**TicketService** (`app/Services/TicketService.php`) — sole writer for tickets. create/update/move/delete + `promoteFromVaultNote(VaultNote $note)` (creates Ticket with the source note as a context link, source note untouched) + context-link helpers. `move()` detects backwards transitions and increments `postpone_count`; emits TaskEvent + ActivityLog + DashboardEvent + triggers PsychEngine.

**KanbanService** is now read-only (board + filter options + context-link resolution). All write paths go through `TicketService`. Frontend identifies tickets by integer ID, no more vault paths.

**PromptBuilder.gatherContext** resolves `context_links` paths to vault note bodies, and `depends_on` (ticket IDs) to last completed agent runs' summaries.

**OutputProcessor** writes agent output as a markdown file in `storage/app/private/dashboard/agent-outputs/{date}-{run_id}-{slug}.md`, records the path on `agent_runs.output_note_path`, and moves the source ticket to `review` via TicketService.

### Phase 3: Cleanup (drop legacy)
**Schema drops** (migration `drop_legacy_ticket_columns`):
- `vault_notes`: status, priority, type, assigned_to, tags, due_date (and their indexes) — all derived from frontmatter, all unused now
- `agent_runs`: ticket_path, vault_note_id (replaced by ticket_id FK)
- `routine_runs`, `task_events`, `psych_interventions`: ticket_path
- `daily_checkins`: vault_note_path

**Code**: VaultManager indexer no longer extracts ticket-only frontmatter; VaultNote model has no ticket scopes; AgentRun.vaultNote() relation removed; DailyCheckinService.saveDailyNote dead method removed; McpServer.toolSaveCheckin no longer writes a vault note. SkillLoader rewritten to use a filesystem path (no VaultManager dependency); skill source label changed `vault` → `file`. Dashboard's vault-folder config (`dashboard.vault.folders.*`) removed entirely; replaced by `dashboard.storage.{agent_outputs,skills}`.

## Additional Database Tables
- `tickets`: see Phase 2 above (the canonical kanban data)
- `task_events`: ticket_id FK, event_type (created|status_changed|postponed|completed|abandoned), from_status, to_status, metadata (json)
- `settings`: key (unique), value, type (string|integer|boolean|json), group
- `psych_interventions`: framework, intervention_type, ticket_id, content, was_helpful, dismissed
- `daily_checkins`: see Phase 1H above (vault_note_path column dropped in Phase 3)
- `cost_events`: see Phase 1F above
- `dashboard_events`: id, type, payload (json), occurred_at — SSE event queue
- `activity_log`: actor, action, subject_type, subject_id, details (json)
- `routines`, `routine_runs`: see Phase 1I above

## Models
- `Ticket` (canonical kanban model), `VaultNote` (slim — pure note index), `TaskEvent`, `Setting`, `AgentRun`, `AgentSkill`, `User`
- `ActivityLog`, `CostEvent`, `DailyCheckin`, `PsychIntervention`, `Routine`, `RoutineRun`

## Enums
- `TicketStatus` (6 values), `TicketPriority` (4 values)

## Route Structure
Run `php artisan route:list` for the current set. Top-level pages: `/dashboard`, `/daily`, `/kanban`, `/vault`, `/agents`, `/routines`, `/skills`, `/settings`. API namespaces: `/api/kanban/*`, `/api/vault/*`, `/api/agents/*`, `/api/daily/*`, `/api/psych/*`, `/api/routines/*`, `/api/skills/*`, `/api/events/stream`.

## What's NOT Built Yet

### Tests
Only Breeze auth scaffolding tests exist. Needs at minimum: TicketService (create/move/postpone/delete), AgentOrchestrator::dispatchReadyTickets (with paused / WIP / dependencies), VaultManager atomic write, RoutineScheduler::dispatch, PsychEngine triggers.

### Phase 2: Email Integration
- `webklex/php-imap` for IMAP
- `spatie/laravel-google-calendar` for Google Calendar
- AI categorizes emails: actionable/informational/unimportant
- Action items → kanban tickets
- Daily digest email at 7am
- `emails` table: message_id, from, subject, category, ai_summary, extracted_actions

### Phase 3: Psychology Module
- **Kahneman**: Tasks tagged system_level 1|2, flag System 2 tasks for focused blocks
- **Chimp Paradox**: emotional_charge field, reframing interventions for high-charge tasks (phone calls), avoidance detection via postpone_count
- **Strudelmodell/ZRM**: Somatic marker check-ins, resource activation, motto-goals
- **Anti-procrastination**: Time-boxing for perfectionism, WIP limits (soft:3, hard:5) for shiny object, "park the idea" flow, progress celebrations
- **Pattern detection**: Query task_events for postponement frequency, completion ratio, abandonment rate
- **Trigger points**: Starting work on ticket, creating ticket when WIP high, completing task, dashboard load
- Tables: `psych_interventions` (framework, intervention_type, content, was_helpful)

### Phase 4: Telegram Interface
- Bot for quick capture, status checks, daily digest
- Commands: /task, /status, /digest, /run

## npm Dependencies Added
- `@hello-pangea/dnd` — drag-and-drop (maintained fork of react-beautiful-dnd)
- `zustand` — lightweight state management
- Note: `--legacy-peer-deps` needed due to Vite 8 / @vitejs/plugin-react peer conflict

## Plan File
Full detailed plan at: `/root/.claude/plans/sleepy-whistling-swing.md`

## Git Log (most recent)
```
f5f0f07 Phase 3: drop legacy columns and dashboard-folder vault writes
c66145d Phase 2b: switch frontend to ticket IDs
4945be1 Phase 2a: switch backend services to Ticket model
493bdff Phase 1: introduce tickets table, model, service (additive)
2bff62c Build settings page v1: vault, agent, psychology, queue
aba1bf7 Update memory.md with phases 1E–1J
997bc86 Add skills UI, dashboard widgets, vault folder counts, MCP and serve commands
71c526a Add routines: cron-driven recurring agent runs
bdc6544 Add daily check-in chat and psychology engine
ea078dd Add ticket detail, linking, meta editing, and deletion to kanban
88831e5 Add skill loader, cost tracking, and prompt-builder context
67c5ea4 Add activity log, dashboard event bus, and SSE event stream
... (Phase 0–1D earlier)
```
