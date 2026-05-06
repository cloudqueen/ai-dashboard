<?php

namespace App\Http\Controllers;

use App\Models\DailyCheckin;
use App\Services\Psychology\DailyCheckinService;
use App\Services\SettingsService;
use App\Services\TicketService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DailyController extends Controller
{
    public function index(DailyCheckinService $service, SettingsService $settings)
    {
        $checkin = $service->getOrCreateToday();
        $projectId = $settings->dailyCoachProjectId();

        $history = DailyCheckin::query()
            ->whereNotNull('completed_at')
            ->where('id', '!=', $checkin->id)
            ->orderByDesc('date')
            ->limit(14)
            ->get(['id', 'date', 'mood', 'energy', 'motto_goal', 'summary', 'completed_at']);

        return Inertia::render('Daily/Index', [
            'checkin' => $checkin,
            'history' => $history,
            'dailyCoach' => [
                'project_id' => $projectId,
                'deep_link' => $projectId ? 'claude://claude.ai/project/' . $projectId : null,
            ],
        ]);
    }

    public function current(DailyCheckinService $service)
    {
        $checkin = $service->getOrCreateToday();

        return response()->json($checkin);
    }

    public function message(Request $request, DailyCheckinService $service)
    {
        $request->validate([
            'message' => 'required|string|max:5000',
        ]);

        $checkin = $service->getOrCreateToday();

        if ($checkin->isCompleted()) {
            return response()->json([
                'error' => 'Das heutige Check-in ist bereits abgeschlossen.',
            ], 422);
        }

        $response = $service->sendMessage($checkin, $request->input('message'));

        return response()->json([
            'response' => $response,
            'checkin' => $checkin->fresh(),
        ]);
    }

    public function setMood(Request $request, DailyCheckinService $service)
    {
        $request->validate([
            'mood' => 'required|string',
            'energy' => 'required|integer|min:1|max:5',
        ]);

        $checkin = $service->getOrCreateToday();
        $checkin->update([
            'mood' => $request->input('mood'),
            'energy' => $request->input('energy'),
        ]);

        // Translate mood to natural German for the conversation
        $moodLabels = [
            'great' => 'super', 'good' => 'gut', 'okay' => 'geht so',
            'tired' => 'ziemlich müde', 'stressed' => 'gestresst',
            'anxious' => 'etwas unruhig', 'bad' => 'nicht so gut',
            'motivated' => 'richtig motiviert', 'exhausted' => 'total erschöpft',
            'overwhelmed' => 'etwas überfordert', 'frustrated' => 'frustriert',
        ];
        $mood = $request->input('mood');
        $energy = $request->input('energy');
        $moodText = $moodLabels[$mood] ?? $mood;

        $energyText = match(true) {
            $energy <= 1 => 'fast keine Energie',
            $energy === 2 => 'wenig Energie',
            $energy === 3 => 'so mittel mit der Energie',
            $energy === 4 => 'ganz gute Energie',
            default => 'viel Energie',
        };

        $response = $service->sendMessage(
            $checkin,
            "Mir geht es {$moodText}, und ich hab {$energyText} heute."
        );

        return response()->json([
            'response' => $response,
            'checkin' => $checkin->fresh(),
        ]);
    }

    public function finish(DailyCheckinService $service)
    {
        $checkin = $service->getOrCreateToday();

        if ($checkin->isCompleted()) {
            return response()->json(['error' => 'Bereits abgeschlossen.'], 422);
        }

        $service->finish($checkin);

        return response()->json(['checkin' => $checkin->fresh()]);
    }

    public function createTicket(Request $request, TicketService $tickets)
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'type' => 'nullable|string',
            'priority' => 'nullable|string',
            'emotional_charge' => 'nullable|string',
            'due' => 'nullable|string',
        ]);

        $dueDate = null;
        if (! empty($data['due'])) {
            try {
                $dueDate = \Carbon\Carbon::parse($data['due'])->format('Y-m-d');
            } catch (\Throwable) {
                $dueDate = null;
            }
        }

        $ticket = $tickets->create([
            'title' => $data['title'],
            'type' => $data['type'] ?? 'task',
            'priority' => $data['priority'] ?? 'medium',
            'status' => 'todo',
            'assigned_to' => 'human',
            'emotional_charge' => $data['emotional_charge'] ?? null,
            'due_date' => $dueDate,
        ]);

        return response()->json([
            'id' => $ticket->id,
            'title' => $ticket->title,
        ]);
    }

    public function history()
    {
        $checkins = DailyCheckin::whereNotNull('completed_at')
            ->orderBy('date', 'desc')
            ->limit(30)
            ->get(['id', 'date', 'mood', 'energy', 'motto_goal', 'summary', 'completed_at']);

        return response()->json($checkins);
    }
}
