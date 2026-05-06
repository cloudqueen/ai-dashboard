<?php

namespace App\Services\Embeddings;

use App\Models\CostEvent;
use Illuminate\Support\Facades\Http;

class VoyageEmbeddingService implements EmbeddingService
{
    private const PRICE_PER_MILLION_TOKENS = [
        'voyage-3-lite' => 0.02,
        'voyage-3' => 0.06,
        'voyage-3-large' => 0.18,
    ];

    public function embed(string $text, string $type = 'document'): array
    {
        $apiKey = config('dashboard.embeddings.voyage_api_key');
        $model = config('dashboard.embeddings.voyage_model', 'voyage-3-lite');

        if (! $apiKey) {
            throw new \RuntimeException('VOYAGE_API_KEY is not configured.');
        }

        $start = microtime(true);

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->timeout(30)
            ->post('https://api.voyageai.com/v1/embeddings', [
                'model' => $model,
                'input' => $text,
                'input_type' => $type,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException(
                "Voyage API error ({$response->status()}): " . $response->body()
            );
        }

        $data = $response->json();
        $embedding = $data['data'][0]['embedding'] ?? null;

        if (! is_array($embedding)) {
            throw new \RuntimeException('Voyage API returned no embedding.');
        }

        if (config('dashboard.ai_log.enabled', false)) {
            $this->logCost($model, $data['usage']['total_tokens'] ?? 0, microtime(true) - $start);
        }

        return $embedding;
    }

    private function logCost(string $model, int $tokens, float $durationSec): void
    {
        $pricePerMillion = self::PRICE_PER_MILLION_TOKENS[$model] ?? 0;
        $cost = ($tokens / 1_000_000) * $pricePerMillion;

        CostEvent::create([
            'source' => 'embedding',
            'model' => $model,
            'input_tokens' => $tokens,
            'output_tokens' => 0,
            'cost_usd' => round($cost, 6),
            'occurred_at' => now(),
        ]);
    }
}
