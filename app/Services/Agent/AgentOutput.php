<?php

namespace App\Services\Agent;

class AgentOutput
{
    public function __construct(
        public readonly bool $success,
        public readonly string $rawOutput,
        public readonly ?string $summary = null,
        public readonly ?string $errorMessage = null,
        public readonly int $durationSeconds = 0,
        public readonly ?int $tokensUsed = null,
    ) {}
}
