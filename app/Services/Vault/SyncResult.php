<?php

namespace App\Services\Vault;

class SyncResult
{
    public function __construct(
        public readonly bool $success,
        public readonly array $filesChanged = [],
        public readonly array $conflicts = [],
        public readonly ?string $error = null,
    ) {}
}
