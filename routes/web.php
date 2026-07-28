<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\FlagController;
use App\Http\Controllers\FlagEnvironmentController;
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

    Route::get('/audit', [AuditLogController::class, 'index']);
});
