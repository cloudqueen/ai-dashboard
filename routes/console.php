<?php

use Illuminate\Support\Facades\Schedule;

// Vault sync - pull remote changes, push local changes
Schedule::command('vault:sync')->everyFiveMinutes();

// Vault re-index - safety net for changed files
Schedule::command('vault:index --changed-only')->everyThirtyMinutes();

// Agent heartbeat - check for ready tickets and process completions
Schedule::command('agent:heartbeat')->everyThreeMinutes();

// Routine check - dispatch due recurring tasks
Schedule::command('routine:check')->everyMinute();

// Nightly project runs (sequential, claude code per active project)
Schedule::command('projects:nightly')->dailyAt('02:00')->withoutOverlapping();
