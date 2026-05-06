<?php

namespace App\Http\Controllers;

use App\Services\Psychology\DailyCheckinService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DailyStreamController extends Controller
{
    public function stream(Request $request, DailyCheckinService $service): StreamedResponse
    {
        $request->validate([
            'message' => 'required|string|max:5000',
        ]);

        $checkin = $service->getOrCreateToday();

        if ($checkin->isCompleted()) {
            return new StreamedResponse(function () {
                echo "data: " . json_encode(['type' => 'error', 'content' => 'Check-in bereits abgeschlossen.']) . "\n\n";
            }, 200, $this->sseHeaders());
        }

        $userMessage = $request->input('message');
        $checkin->addMessage('user', $userMessage);
        $service->detectMoodFromMessage($checkin, $userMessage);

        return new StreamedResponse(function () use ($service, $checkin, $userMessage) {
            $fullText = '';

            $service->runClaudeStreaming(
                $checkin->claude_session_id,
                $userMessage,
                onText: function (string $chunk) use (&$fullText) {
                    $fullText .= $chunk;
                    echo "data: " . json_encode(['type' => 'text', 'content' => $chunk]) . "\n\n";
                    if (ob_get_level()) ob_flush();
                    flush();
                },
                onDone: function (string $text, ?array $usage) use ($service, $checkin, &$fullText) {
                    $fullText = $text;

                    // Parse options and tickets from the full response
                    [$cleanText, $options, $tickets] = $service->parseResponse($fullText);
                    $checkin->addMessage('assistant', $cleanText, $options, $tickets);

                    echo "data: " . json_encode([
                        'type' => 'done',
                        'content' => $cleanText,
                        'options' => $options,
                        'tickets' => $tickets,
                        'checkin' => $checkin->fresh(),
                    ]) . "\n\n";
                    if (ob_get_level()) ob_flush();
                    flush();
                }
            );
        }, 200, $this->sseHeaders());
    }

    private function sseHeaders(): array
    {
        return [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ];
    }
}
