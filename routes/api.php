<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\Admin\FeelingController as AdminFeelingController;
use App\Http\Controllers\Api\V1\FeelingController;
use App\Http\Controllers\Api\V1\PostController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group.
|
*/

// API Routes
// Health check - public
Route::get('/health', HealthController::class)->middleware('throttle:public');

// Auth routes - guest only with rate limiting
Route::prefix('auth')->middleware('throttle:auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->middleware('guest');
    Route::post('/login', [AuthController::class, 'login'])->middleware('guest');
});

// Auth routes - authenticated
Route::prefix('auth')->middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
});

// Protected routes
Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::get('/posts', [PostController::class, 'index']);
    Route::post('/posts', [PostController::class, 'store']);

    Route::get('/feelings', [FeelingController::class, 'index']);

    Route::prefix('admin')->group(function () {
        Route::get('/feelings', [AdminFeelingController::class, 'index']);
        Route::post('/feelings', [AdminFeelingController::class, 'store']);
        Route::put('/feelings/{id}', [AdminFeelingController::class, 'update']);
        Route::delete('/feelings/{id}', [AdminFeelingController::class, 'destroy']);
    });
});
