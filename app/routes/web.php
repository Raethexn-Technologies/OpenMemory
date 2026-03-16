<?php

use App\Http\Controllers\AgentController;
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
Route::post('/api/graph/simulate', [GraphController::class, 'simulate'])->name('api.graph.simulate');
Route::get('/api/graph/clusters', [GraphController::class, 'clusters'])->name('api.graph.clusters');
Route::get('/api/graph/topology', [GraphController::class, 'topology'])->name('api.graph.topology');
Route::post('/api/graph/decay', [GraphController::class, 'decay'])->name('api.graph.decay');
Route::post('/api/graph/snapshot', [GraphController::class, 'snapshot'])->name('api.graph.snapshot');
Route::get('/api/graph/snapshots', [GraphController::class, 'snapshotIndex'])->name('api.graph.snapshots');
Route::get('/api/graph/snapshots/{snapshotId}', [GraphController::class, 'snapshotShow'])->name('api.graph.snapshots.show');

// Three.js mission control surface
Route::get('/3d', [GraphController::class, 'threeD'])->name('threed');

// Multi-agent simulation
Route::get('/agents', [AgentController::class, 'index'])->name('agents');
Route::post('/api/agents', [AgentController::class, 'store'])->name('agents.store');
Route::patch('/api/agents/{agentId}/trust', [AgentController::class, 'updateTrust'])->name('agents.updateTrust');
Route::post('/api/agents/{agentId}/seed', [AgentController::class, 'seed'])->name('agents.seed');
Route::post('/api/agents/{agentId}/simulate', [AgentController::class, 'simulate'])->name('agents.simulate');
Route::post('/api/agents/simulate-all', [AgentController::class, 'simulateAll'])->name('agents.simulateAll');
Route::get('/api/agents/alignment', [AgentController::class, 'alignment'])->name('agents.alignment');
Route::get('/api/agents/shared-edges', [AgentController::class, 'sharedEdges'])->name('agents.sharedEdges');
Route::get('/api/agents/{agentId}/graph', [AgentController::class, 'graph'])->name('agents.graph');
Route::delete('/api/agents/{agentId}', [AgentController::class, 'destroy'])->name('agents.destroy');
Route::post('/api/demo/simulate-day', [AgentController::class, 'simulateDay'])->name('demo.simulateDay');
