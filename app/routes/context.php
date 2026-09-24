<?php

use App\Http\Controllers\ContextController;
use App\Http\Middleware\AuthenticateContextApplication;
use App\Http\Middleware\ContextJson;
use Illuminate\Support\Facades\Route;

// Stateless bearer-only access is separate from browser sessions and MCP.
Route::post('/app/context/resolve', [ContextController::class, 'application'])
    ->middleware([ContextJson::class, 'throttle:60,1', AuthenticateContextApplication::class]);
