<?php

namespace App\Services;

use App\Models\CoachMemory;
use App\Services\Embeddings\EmbeddingService;
use Illuminate\Support\Collection;
use Pgvector\Laravel\Vector;

class CoachMemoryService
{
    public function __construct(
        private EmbeddingService $embeddings,
    ) {}

    public function store(string $content, string $type = 'session', ?array $metadata = null, ?string $sessionDate = null): CoachMemory
    {
        $vector = $this->embeddings->embed($content, 'document');

        return CoachMemory::create([
            'type' => $type,
            'session_date' => $sessionDate ?? now()->toDateString(),
            'content' => $content,
            'metadata' => $metadata,
            'embedding' => new Vector($vector),
        ]);
    }

    /**
     * Semantic recall: returns top-K memories by cosine similarity.
     */
    public function recall(string $query, int $limit = 5): Collection
    {
        $queryVector = new Vector($this->embeddings->embed($query, 'query'));

        return CoachMemory::query()
            ->orderByRaw('embedding <=> ?::vector', [(string) $queryVector])
            ->limit($limit)
            ->get();
    }

    public function listRecent(int $limit = 20): Collection
    {
        return CoachMemory::query()
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    public function delete(CoachMemory $memory): void
    {
        $memory->delete();
    }
}
