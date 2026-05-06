<?php

namespace App\Http\Controllers;

use App\Services\DashboardEventBus;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EventStreamController extends Controller
{
    public function stream(Request $request, DashboardEventBus $bus): StreamedResponse
    {
        $lastId = (int) $request->get('last_id', 0);

        return new StreamedResponse(function () use ($bus, $lastId) {
            $currentId = $lastId;
            $emptyLoops = 0;

            while (true) {
                $events = $bus->since($currentId);

                if (! empty($events)) {
                    foreach ($events as $event) {
                        echo "id: {$event['id']}\n";
                        echo "event: {$event['type']}\n";
                        echo "data: " . json_encode($event) . "\n\n";
                        $currentId = $event['id'];
                    }
                    $emptyLoops = 0;
                } else {
                    // Send keepalive comment every ~30s (15 loops * 2s)
                    $emptyLoops++;
                    if ($emptyLoops % 15 === 0) {
                        echo ": keepalive\n\n";
                    }
                }

                if (ob_get_level()) ob_flush();
                flush();

                if (connection_aborted()) break;

                sleep(2);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
