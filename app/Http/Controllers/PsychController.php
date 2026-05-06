<?php

namespace App\Http\Controllers;

use App\Models\PsychIntervention;
use App\Services\Psychology\PsychEngine;
use Illuminate\Http\Request;

class PsychController extends Controller
{
    public function insights(PsychEngine $psych)
    {
        return response()->json($psych->getDashboardInsights());
    }

    public function feedback(Request $request)
    {
        $request->validate([
            'id' => 'required|exists:psych_interventions,id',
            'was_helpful' => 'required|boolean',
        ]);

        PsychIntervention::where('id', $request->input('id'))
            ->update(['was_helpful' => $request->boolean('was_helpful')]);

        return back()->with('success', 'Thanks for the feedback.');
    }

    public function dismiss(Request $request)
    {
        $request->validate([
            'id' => 'required|exists:psych_interventions,id',
        ]);

        PsychIntervention::where('id', $request->input('id'))
            ->update(['dismissed' => true]);

        return back()->with('success', 'Dismissed.');
    }

    public function activeInterventions(PsychEngine $psych)
    {
        return response()->json($psych->getActiveInterventions());
    }
}
