<?php

use Illuminate\Support\Facades\Schedule;

// Vault sync - pull remote changes, push local changes
Schedule::command('vault:sync')->everyFiveMinutes();

// Vault re-index - safety net for changed files
Schedule::command('vault:index --changed-only')->everyThirtyMinutes();
