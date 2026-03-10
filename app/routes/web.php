<?php

use App\Http\Controllers\ChatController;
use App\Http\Controllers\MemoryController;
use Illuminate\Support\Facades\Route;

// Redirect root to chat
Route::get('/', fn() => redirect()->route('chat'));

// Chat
Route::get('/chat', [ChatController::class, 'index'])->name('chat');
Route::post('/chat/send', [ChatController::class, 'send'])->name('chat.send');
Route::post('/chat/reset', [ChatController::class, 'reset'])->name('chat.reset');

// Memory inspector
Route::get('/memory', [MemoryController::class, 'index'])->name('memory.index');
Route::get('/memory/refresh', [MemoryController::class, 'refresh'])->name('memory.refresh');
