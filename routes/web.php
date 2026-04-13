<?php

use App\Http\Controllers\KanbanController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\VaultController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route('dashboard');
    }
    return redirect()->route('login');
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/kanban', [KanbanController::class, 'index'])->name('kanban');
    Route::patch('/api/kanban/move', [KanbanController::class, 'move'])->name('kanban.move');
    Route::post('/api/kanban/tickets', [KanbanController::class, 'store'])->name('kanban.store');
    Route::post('/api/kanban/promote', [KanbanController::class, 'promote'])->name('kanban.promote');

    Route::get('/vault', [VaultController::class, 'index'])->name('vault');
    Route::get('/vault/note/{path}', [VaultController::class, 'show'])->name('vault.show')->where('path', '.*');
    Route::post('/vault/notes', [VaultController::class, 'store'])->name('vault.store');
    Route::patch('/vault/notes', [VaultController::class, 'update'])->name('vault.update');
    Route::get('/api/vault/search', [VaultController::class, 'search'])->name('vault.search');
    Route::post('/vault/sync', [VaultController::class, 'sync'])->name('vault.sync');

    Route::get('/agents', function () {
        return Inertia::render('Agents/Index');
    })->name('agents');

    Route::get('/settings', function () {
        return Inertia::render('Settings/Index');
    })->name('settings');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
