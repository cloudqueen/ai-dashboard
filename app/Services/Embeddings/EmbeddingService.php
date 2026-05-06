<?php

namespace App\Services\Embeddings;

interface EmbeddingService
{
    /**
     * Embed a single text. $type is one of 'document'|'query'.
     *
     * @return array<float>
     */
    public function embed(string $text, string $type = 'document'): array;
}
