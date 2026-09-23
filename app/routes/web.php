<?php

use App\Http\Controllers\AgentController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ConversationHistoryController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\GraphController;
use App\Http\Controllers\IngestController;
use App\Http\Controllers\McpController;
use App\Http\Controllers\MemoryController;
use Illuminate\Support\Facades\Route;

// Redirect root to chat
Route::get('/', fn () => redirect()->route('chat'));

Route::get('/login', [\App\Http\Controllers\AuthController::class, 'create'])->name('login');
Route::post('/login', [\App\Http\Controllers\AuthController::class, 'store'])->middleware('throttle:10,1')->name('login.store');
Route::post('/logout', [\App\Http\Controllers\AuthController::class, 'destroy'])->middleware('auth:web')->name('logout');

// These public and application routes do not establish browser ownership.
Route::get('/api/status', [MemoryController::class, 'status'])->name('api.status');
Route::get('/api/graph/ambient', [GraphController::class, 'ambient'])
    ->middleware('throttle:60,1')
    ->name('api.graph.ambient');
Route::post('/mcp/store', [McpController::class, 'store'])->name('mcp.store');
Route::post('/mcp/prepare', [McpController::class, 'prepare'])->name('mcp.prepare');
Route::post('/mcp/sync', [McpController::class, 'sync'])->name('mcp.sync');
Route::post('/mcp/search', [McpController::class, 'search'])->name('mcp.search');

Route::middleware(['auth:web', \App\Http\Middleware\SetAuthenticatedOwner::class])->group(function () {
    // Chat
    Route::get('/chat', [ChatController::class, 'index'])->name('chat');
    Route::post('/chat/send', [ChatController::class, 'send'])->name('chat.send');
    Route::post('/chat/reset', [ChatController::class, 'reset'])->name('chat.reset');
    // Legacy external sign-out acknowledgement does not change OpenMemory login.
    Route::post('/chat/identity-logout', [ChatController::class, 'identityLogout'])->name('chat.identityLogout');
    // Browser calls this after user approves a Private/Sensitive memory in mock mode.
    // In live ICP mode the browser writes directly to the canister - this endpoint is not used.
    Route::post('/chat/store-memory', [ChatController::class, 'storeMemory'])->name('chat.storeMemory');
    Route::post('/chat/sync-graph-memory', [ChatController::class, 'syncGraphMemory'])->name('chat.syncGraphMemory');

    Route::get('/native-memory', [\App\Http\Controllers\NativeMemoryController::class, 'page'])->name('native-memory');
    Route::prefix('/api/native-memories')->middleware(\App\Http\Middleware\NativeMemoryRequest::class)->group(function () {
        $controller = \App\Http\Controllers\NativeMemoryController::class;
        Route::get('/', [$controller, 'index']);
        Route::post('/', [$controller, 'store']);
        Route::post('/search', [$controller, 'search']);
        Route::get('/export', [$controller, 'export']);
        Route::post('/import', [$controller, 'import']);
        Route::get('/{memoryId}', [$controller, 'show'])->whereUuid('memoryId');
        Route::patch('/{memoryId}', [$controller, 'update'])->whereUuid('memoryId');
        Route::delete('/{memoryId}', [$controller, 'destroy'])->whereUuid('memoryId');
        Route::post('/{memoryId}/supersede', [$controller, 'supersede'])->whereUuid('memoryId');
    });

    // Memory inspector
    Route::get('/memory', [MemoryController::class, 'index'])->name('memory.index');
    Route::get('/memory/refresh', [MemoryController::class, 'refresh'])->name('memory.refresh');

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
    Route::post('/api/graph/consolidate', [GraphController::class, 'consolidate'])->name('api.graph.consolidate');
    Route::post('/api/graph/prune', [GraphController::class, 'prune'])->name('api.graph.prune');

    // Auto-ingest from connected sources. POST triggers a manual sweep of the
    // configured (or passed-in) repos and writes new public memories straight to
    // ICP; private/sensitive items come back as pending_approval for the UI to
    // route through the same approval flow used by chat-derived memories.
    Route::post('/api/ingest/github', [IngestController::class, 'github'])
        ->middleware('throttle:6,1')
        ->name('ingest.github');

    // Document ingestion - second brain feature.
    // POST accepts a file upload (txt, md) or a raw text paste plus a title and sensitivity level.
    // All chunk nodes produced by ingestion are wired into the same Physarum graph as chat memories,
    // so cross-document and chat-to-document connections emerge automatically via shared tags.
    Route::get('/api/documents', [DocumentController::class, 'index'])->name('documents.index');
    Route::post('/api/documents/ingest', [DocumentController::class, 'store'])->name('documents.store');

    // Imported AI history. Every route is scoped to the corpus owner resolved by
    // ConversationHistoryController::ownerId(). Imported conversations never enter
    // the public memory graph, chat recall, or MCP responses; this surface is the
    // only place they are read, and the raw route is the only place the preserved
    // unredacted source is served.
    Route::get('/history', [ConversationHistoryController::class, 'index'])->name('history');
    Route::get('/history/conversations/{conversationId}', [ConversationHistoryController::class, 'show'])->name('history.show');
    Route::get('/api/history/conversations', [ConversationHistoryController::class, 'list'])->name('history.list');
    Route::post('/api/history/search', [ConversationHistoryController::class, 'search'])->name('history.search');
    Route::post('/api/history/ask', [ConversationHistoryController::class, 'askQuestion'])
        ->middleware('throttle:20,1')
        ->name('history.ask');
    Route::post('/api/history/timeline', [ConversationHistoryController::class, 'timeline'])->name('history.timeline');
    Route::get('/api/history/conversations/{conversationId}/raw', [ConversationHistoryController::class, 'raw'])->name('history.raw');
    Route::delete('/api/history/conversations/{conversationId}', [ConversationHistoryController::class, 'destroy'])->name('history.destroy');

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

});
