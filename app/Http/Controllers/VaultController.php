<?php

namespace App\Http\Controllers;

use App\Models\VaultNote;
use App\Services\Vault\VaultManager;
use Illuminate\Http\Request;
use Inertia\Inertia;

class VaultController extends Controller
{
    public function index(Request $request, VaultManager $vault)
    {
        $query = VaultNote::query()->orderBy('vault_modified_at', 'desc');

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'LIKE', "%{$search}%")
                    ->orWhere('body_preview', 'LIKE', "%{$search}%");
            });
        }

        if ($folder = $request->get('folder')) {
            $query->where('vault_folder', $folder);
        }

        $notes = $query->paginate(50)->withQueryString();

        // Get unique folders for the sidebar
        $folders = VaultNote::select('vault_folder')
            ->distinct()
            ->orderBy('vault_folder')
            ->pluck('vault_folder')
            ->values();

        return Inertia::render('Vault/Index', [
            'notes' => $notes,
            'folders' => $folders,
            'filters' => [
                'search' => $search,
                'folder' => $folder,
            ],
            'vaultConfigured' => $vault->isConfigured(),
        ]);
    }

    public function show(string $path, VaultManager $vault)
    {
        $path = urldecode($path);
        $note = VaultNote::where('relative_path', $path)->firstOrFail();
        $parsed = $vault->readNote($path);

        return Inertia::render('Vault/Show', [
            'note' => $note,
            'content' => $parsed?->body ?? '',
            'frontmatter' => $parsed?->frontmatter ?? [],
            'wikilinks' => $parsed?->wikilinks ?? [],
        ]);
    }

    public function update(Request $request, VaultManager $vault)
    {
        $request->validate([
            'path' => 'required|string',
            'frontmatter' => 'nullable|array',
            'body' => 'nullable|string',
        ]);

        $path = $request->input('path');
        $note = VaultNote::where('relative_path', $path)->firstOrFail();

        if ($request->has('body')) {
            $vault->writeNote(
                $path,
                $request->input('frontmatter', []),
                $request->input('body', '')
            );
        } elseif ($request->has('frontmatter')) {
            $vault->updateFrontmatter($path, $request->input('frontmatter'));
        }

        return back()->with('success', 'Note updated.');
    }

    public function store(Request $request, VaultManager $vault)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'folder' => 'nullable|string',
            'body' => 'nullable|string',
            'frontmatter' => 'nullable|array',
        ]);

        $folder = $request->input('folder', 'inbox');
        $relativePath = $vault->createNote(
            $folder,
            $request->input('title'),
            $request->input('frontmatter', []),
            $request->input('body', '')
        );

        return redirect()->route('vault.show', ['path' => $relativePath])
            ->with('success', 'Note created.');
    }

    public function search(Request $request, VaultManager $vault)
    {
        $query = $request->get('q', '');

        if (strlen($query) < 2) {
            return response()->json([]);
        }

        $results = $vault->search($query);

        return response()->json($results);
    }

    public function sync(VaultManager $vault)
    {
        if (! $vault->isConfigured()) {
            return back()->with('error', 'Vault not configured.');
        }

        \Artisan::call('vault:sync');

        return back()->with('success', 'Vault sync triggered.');
    }
}
