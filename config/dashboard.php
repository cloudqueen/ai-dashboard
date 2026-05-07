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
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard Storage (own folders, outside the vault)
    |--------------------------------------------------------------------------
    | Paths are resolved via Storage::disk('local'), i.e. relative to
    | storage/app/.
    */

    'storage' => [
        'agent_outputs' => 'dashboard/agent-outputs',
        'skills' => 'dashboard/skills',
        'profile' => 'dashboard/profile.md',
    ],

    /*
    |--------------------------------------------------------------------------
    | Projects (autonomous nightly runs)
    |--------------------------------------------------------------------------
    */

    'projects' => [
        'allowed_roots' => [
            $_SERVER['HOME'] . '/projekte',
            $_SERVER['HOME'] . '/projekte/Herd',
        ],
        'default_model' => env('PROJECT_DEFAULT_MODEL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Embeddings (Voyage AI)
    |--------------------------------------------------------------------------
    */

    'embeddings' => [
        'voyage_api_key' => env('VOYAGE_API_KEY'),
        'voyage_model' => env('VOYAGE_EMBEDDING_MODEL', 'voyage-3-lite'),
        'dims' => (int) env('VOYAGE_EMBEDDING_DIMS', 512),
    ],

    'ai_log' => [
        'enabled' => env('AI_LOG_ENABLED', false),
        'days' => (int) env('AI_LOG_DAYS', 7),
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
