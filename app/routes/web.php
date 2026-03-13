<?php

use App\Http\Controllers\ChatController;
use App\Http\Controllers\GraphController;
use App\Http\Controllers\MemoryController;
use Illuminate\Support\Facades\Route;

// Redirect root to chat
Route::get('/', fn () => redirect()->route('chat'));

// Chat
Route::get('/chat', [ChatController::class, 'index'])->name('chat');
Route::post('/chat/send', [ChatController::class, 'send'])->name('chat.send');
Route::post('/chat/reset', [ChatController::class, 'reset'])->name('chat.reset');
// Browser calls this after user approves a Private/Sensitive memory in mock mode.
// In live ICP mode the browser writes directly to the canister — this endpoint is not used.
Route::post('/chat/store-memory', [ChatController::class, 'storeMemory'])->name('chat.storeMemory');
Route::post('/chat/sync-graph-memory', [ChatController::class, 'syncGraphMemory'])->name('chat.syncGraphMemory');

// Memory inspector
Route::get('/memory', [MemoryController::class, 'index'])->name('memory.index');
Route::get('/memory/refresh', [MemoryController::class, 'refresh'])->name('memory.refresh');

// Status API — returns real adapter/canister health for the UI
Route::get('/api/status', [MemoryController::class, 'status'])->name('api.status');

// Memory graph explorer
Route::get('/graph', [GraphController::class, 'index'])->name('graph');
Route::get('/api/graph', [GraphController::class, 'data'])->name('api.graph');
Route::get('/api/graph/neighborhood/{nodeId}', [GraphController::class, 'neighborhood'])->name('api.graph.neighborhood');
