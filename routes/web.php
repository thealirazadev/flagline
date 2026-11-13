<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\EnvironmentController;
use App\Http\Controllers\FlagController;
use App\Http\Controllers\FlagEnvironmentController;
use App\Http\Controllers\RuleController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/flags');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/flags', [FlagController::class, 'index']);
    Route::get('/flags/create', [FlagController::class, 'create']);
    Route::post('/flags', [FlagController::class, 'store']);
    Route::get('/flags/{flag}/edit', [FlagController::class, 'edit']);
    Route::put('/flags/{flag}', [FlagController::class, 'update']);
    Route::post('/flags/{flag}/archive', [FlagController::class, 'archive']);

    Route::put('/flags/{flag}/environments/{environment}', [FlagEnvironmentController::class, 'update']);

    Route::post('/flags/{flag}/environments/{environment}/kill', [FlagEnvironmentController::class, 'kill']);
    Route::post('/flags/{flag}/environments/{environment}/restore', [FlagEnvironmentController::class, 'restore']);
    Route::post('/flags/{flag}/environments/{environment}/rules', [RuleController::class, 'store']);
    Route::put('/rules/{rule}', [RuleController::class, 'update'])->whereNumber('rule');
    Route::post('/rules/{rule}/move', [RuleController::class, 'move'])->whereNumber('rule');
    Route::delete('/rules/{rule}', [RuleController::class, 'destroy'])->whereNumber('rule');

    Route::get('/environments', [EnvironmentController::class, 'index']);
    Route::get('/audit', [AuditLogController::class, 'index']);
});
