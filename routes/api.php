<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChatController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::middleware('throttle:auth')->group(function () {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login', [AuthController::class, 'login']);
        Route::post('forgot-password', [AuthController::class, 'forgot']);
        Route::post('reset-password', [AuthController::class, 'reset']);
        Route::post('email/resend', [AuthController::class, 'resend']);
    });
    Route::middleware(['auth:sanctum', 'active', 'throttle:api'])->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('logout-all', [AccountController::class, 'logoutAll']);
        Route::get('me', [AccountController::class, 'show']);
        Route::patch('me', [AccountController::class, 'update']);
        Route::get('tokens', [AccountController::class, 'tokens']);
        Route::delete('tokens/{id}', [AccountController::class, 'revoke']);
        Route::middleware('throttle:sensitive')->group(function () {
            Route::put('me/password', [AccountController::class, 'password']);
            Route::delete('me', [AccountController::class, 'delete']);
            Route::post('2fa/setup', [AccountController::class, 'setup']);
            Route::post('2fa/confirm', [AccountController::class, 'confirm']);
            Route::delete('2fa', [AccountController::class, 'disable']);
            Route::post('2fa/recovery-codes', [AccountController::class, 'recovery']);
        });
        Route::get('models', [ChatController::class, 'models']);
        Route::post('chat', [ChatController::class, 'send'])->middleware('throttle:chat');
        Route::get('conversations', [ChatController::class, 'index']);
        Route::get('conversations/{id}', [ChatController::class, 'show']);
        Route::delete('conversations/{id}',[ChatController::class, 'delete']);
    });
});
