<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Obsidian Vault Configuration
    |--------------------------------------------------------------------------
    */

    'vault' => [
        'path' => env('VAULT_PATH'),
        'git_remote' => env('VAULT_GIT_REMOTE'),
        'sync_enabled' => env('VAULT_SYNC_ENABLED', true),
        'sync_branch' => env('VAULT_SYNC_BRANCH', 'main'),
        'folders' => [
            'agent_outputs' => 'agent/outputs',
            'agent_logs' => 'agent/logs',
            'inbox' => 'inbox',
            'email' => 'email',
            'psychology' => 'psychology',
            'templates' => 'templates',
            'daily' => 'daily',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Agent Configuration
    |--------------------------------------------------------------------------
    */

    'agent' => [
        'cli_path' => env('CLAUDE_CLI_PATH', 'claude'),
        'max_concurrent' => (int) env('AGENT_MAX_CONCURRENT', 2),
        'heartbeat_minutes' => (int) env('AGENT_HEARTBEAT_MINUTES', 3),
        'timeout_minutes' => (int) env('AGENT_TIMEOUT_MINUTES', 30),
        'output_format' => 'json',
        'monthly_budget_usd' => env('AGENT_MONTHLY_BUDGET_USD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Psychology Module Configuration
    |--------------------------------------------------------------------------
    */

    'psychology' => [
        'enabled' => env('PSYCHOLOGY_ENABLED', false),
        'frameworks' => [
            'kahneman' => true,
            'chimp' => true,
            'strudel' => true,
        ],
        'wip_soft_limit' => 3,
        'wip_hard_limit' => 5,
        'postpone_threshold' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Daily Check-in Configuration
    |--------------------------------------------------------------------------
    */

    'daily' => [
        'model' => env('DAILY_CHECKIN_MODEL', 'claude-sonnet-4-6'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Kanban Configuration
    |--------------------------------------------------------------------------
    */

    'kanban' => [
        'statuses' => [
            'backlog' => 'Backlog',
            'todo' => 'Todo',
            'ready_for_agent' => 'Ready for Agent',
            'in_progress' => 'In Progress',
            'review' => 'Review',
            'done' => 'Done',
        ],
        'priorities' => [
            'critical' => 'Critical',
            'high' => 'High',
            'medium' => 'Medium',
            'low' => 'Low',
        ],
        'ticket_types' => [
            'task',
            'bug',
            'feature',
            'research',
            'writing',
            'email',
        ],
    ],

];
