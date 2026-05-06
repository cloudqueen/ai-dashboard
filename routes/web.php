<?php

use App\Http\Controllers\AgentController;
use App\Http\Controllers\DailyController;
use App\Http\Controllers\DailyStreamController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EventStreamController;
use App\Http\Controllers\KanbanController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PsychController;
use App\Http\Controllers\RoutineController;
use App\Http\Controllers\VaultController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route('dashboard');
    }
    return redirect()->route('login');
});

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/kanban', [KanbanController::class, 'index'])->name('kanban');
    Route::patch('/api/kanban/move', [KanbanController::class, 'move'])->name('kanban.move');
    Route::post('/api/kanban/tickets', [KanbanController::class, 'store'])->name('kanban.store');
    Route::post('/api/kanban/promote', [KanbanController::class, 'promote'])->name('kanban.promote');
    Route::get('/api/kanban/ticket/{path}', [KanbanController::class, 'show'])->name('kanban.show')->where('path', '.*');
    Route::post('/api/kanban/link', [KanbanController::class, 'addLink'])->name('kanban.link');
    Route::delete('/api/kanban/link', [KanbanController::class, 'removeLink'])->name('kanban.unlink');
    Route::patch('/api/kanban/meta', [KanbanController::class, 'updateMeta'])->name('kanban.meta');
    Route::delete('/api/kanban/ticket', [KanbanController::class, 'destroy'])->name('kanban.destroy');

    Route::get('/vault', [VaultController::class, 'index'])->name('vault');
    Route::get('/vault/note/{path}', [VaultController::class, 'show'])->name('vault.show')->where('path', '.*');
    Route::post('/vault/notes', [VaultController::class, 'store'])->name('vault.store');
    Route::patch('/vault/notes', [VaultController::class, 'update'])->name('vault.update');
    Route::get('/api/vault/search', [VaultController::class, 'search'])->name('vault.search');
    Route::post('/vault/sync', [VaultController::class, 'sync'])->name('vault.sync');

    Route::get('/agents', [AgentController::class, 'index'])->name('agents');
    Route::get('/agents/{agentRun}', [AgentController::class, 'show'])->name('agents.show');
    Route::post('/api/agents/run', [AgentController::class, 'triggerRun'])->name('agents.run');
    Route::get('/api/agents/status', [AgentController::class, 'status'])->name('agents.status');

    Route::get('/daily', [DailyController::class, 'index'])->name('daily');
    Route::get('/api/daily/current', [DailyController::class, 'current'])->name('daily.current');
    Route::post('/api/daily/message', [DailyController::class, 'message'])->name('daily.message');
    Route::post('/api/daily/stream', [DailyStreamController::class, 'stream'])->name('daily.stream');
    Route::post('/api/daily/mood', [DailyController::class, 'setMood'])->name('daily.mood');
    Route::post('/api/daily/ticket', [DailyController::class, 'createTicket'])->name('daily.ticket');
    Route::post('/api/daily/finish', [DailyController::class, 'finish'])->name('daily.finish');
    Route::get('/api/daily/history', [DailyController::class, 'history'])->name('daily.history');

    Route::get('/api/psych/insights', [PsychController::class, 'insights'])->name('psych.insights');
    Route::get('/api/psych/interventions', [PsychController::class, 'activeInterventions'])->name('psych.interventions');
    Route::post('/api/psych/feedback', [PsychController::class, 'feedback'])->name('psych.feedback');
    Route::post('/api/psych/dismiss', [PsychController::class, 'dismiss'])->name('psych.dismiss');

    Route::get('/api/events/stream', [EventStreamController::class, 'stream'])->name('events.stream');

    Route::get('/routines', [RoutineController::class, 'index'])->name('routines');
    Route::post('/routines', [RoutineController::class, 'store'])->name('routines.store');
    Route::patch('/routines/{routine}', [RoutineController::class, 'update'])->name('routines.update');
    Route::delete('/routines/{routine}', [RoutineController::class, 'destroy'])->name('routines.destroy');
    Route::post('/routines/{routine}/trigger', [RoutineController::class, 'trigger'])->name('routines.trigger');
    Route::get('/api/routines/{routine}/runs', [RoutineController::class, 'runs'])->name('routines.runs');

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
