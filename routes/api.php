<?php

use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Http\Request;
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

// API V1 Routes
Route::prefix('v1')->group(function () {
    // Health check - public
    Route::get('/health', HealthController::class)->middleware('throttle:public');

    // Auth routes - public with rate limiting
    Route::prefix('auth')->middleware('throttle:public')->group(function () {
        // Auth routes will be added here
    });

    // Protected routes
    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
        Route::get('/user', function (Request $request) {
            return response()->json([
                'status' => 'success',
                'message' => 'User retrieved successfully',
                'data' => $request->user(),
            ]);
        });
    });
});
