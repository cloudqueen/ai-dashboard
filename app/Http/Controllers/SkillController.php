<?php

namespace App\Http\Controllers;

use App\Services\Agent\SkillLoader;
use App\Services\Vault\VaultManager;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SkillController extends Controller
{
    public function index(SkillLoader $loader)
    {
        return Inertia::render('Skills/Index', [
            'skills' => $loader->listAll(),
        ]);
    }

    public function show(string $name, SkillLoader $loader, VaultManager $vault)
    {
        $skill = $loader->load($name);

        if (! $skill) {
            abort(404);
        }

        return response()->json($skill);
    }

    public function store(Request $request, SkillLoader $loader)
    {
        $request->validate([
            'name' => 'required|string|max:100|regex:/^[a-z0-9-]+$/',
            'description' => 'nullable|string|max:500',
            'body' => 'nullable|string',
        ]);

        $path = $loader->create(
            $request->input('name'),
            $request->input('description', ''),
            $request->input('body', '')
        );

        return back()->with('success', "Skill '{$request->input('name')}' erstellt.");
    }

    /**
     * API: list all skills (for dropdowns).
     */
    public function list(SkillLoader $loader)
    {
        return response()->json($loader->listAll());
    }
}
