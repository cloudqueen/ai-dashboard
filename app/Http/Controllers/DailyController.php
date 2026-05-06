<?php

namespace App\Http\Controllers;

use App\Models\DailyCheckin;
use App\Services\Kanban\KanbanService;
use App\Services\Psychology\DailyCheckinService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DailyController extends Controller
{
    public function index(DailyCheckinService $service)
    {
        $checkin = $service->getOrCreateToday();

        return Inertia::render('Daily/Index', [
            'checkin' => $checkin,
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
            return response()->json([
                'error' => 'Bereits abgeschlossen.',
                'vault_note_path' => $checkin->vault_note_path,
            ], 422);
        }

        $notePath = $service->finish($checkin);

        return response()->json([
            'vault_note_path' => $notePath,
            'checkin' => $checkin->fresh(),
        ]);
    }

    public function createTicket(Request $request, KanbanService $kanban)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'type' => 'nullable|string',
            'priority' => 'nullable|string',
            'emotional_charge' => 'nullable|string',
            'due' => 'nullable|string',
        ]);

        // Parse due date
        $dueDate = null;
        if ($request->input('due')) {
            try {
                $dueDate = \Carbon\Carbon::parse($request->input('due'))->format('Y-m-d H:i');
            } catch (\Throwable) {
                $dueDate = null;
            }
        }

        $path = $kanban->createTicket([
            'title' => $request->input('title'),
            'type' => $request->input('type', 'task'),
            'priority' => $request->input('priority', 'medium'),
            'status' => 'todo',
            'assigned_to' => 'human',
            'emotional_charge' => $request->input('emotional_charge', 'low'),
            'due_date' => $dueDate,
            'folder' => 'inbox',
        ]);

        return response()->json([
            'path' => $path,
            'title' => $request->input('title'),
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
