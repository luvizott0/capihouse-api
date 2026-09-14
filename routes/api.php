<?php

use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Auth\RegisterController;
use App\Http\Controllers\Api\Auth\LogoutController;
use App\Http\Controllers\Api\Auth\ImpersonateController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\InterestController;
use App\Http\Controllers\Api\PostController;
use App\Http\Controllers\Api\PostLikeController;
use App\Http\Controllers\Api\PostCommentController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\Admin\UserManagementController;
use Illuminate\Support\Facades\Route;

// Public auth routes
Route::post('/auth/login', [LoginController::class, 'login']);
Route::post('/auth/register', [RegisterController::class, 'register']);
Route::post('/auth/impersonate', [ImpersonateController::class, 'impersonate']);
Route::get('/auth/dev-users', [ImpersonateController::class, 'devUsers']);

// Authenticated routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [LogoutController::class, 'logout']);
    Route::get('/auth/me', [UserController::class, 'me']);

    // Approved routes
    Route::middleware('approved')->group(function () {
        // Profile
        Route::get('/profile', [ProfileController::class, 'show']);
        Route::put('/profile', [ProfileController::class, 'update']);
        Route::post('/profile/avatar', [ProfileController::class, 'uploadAvatar']);
        Route::post('/profile/banner', [ProfileController::class, 'uploadBanner']);
        Route::put('/profile/password', [ProfileController::class, 'updatePassword']);

        // Users
        Route::get('/users', [UserController::class, 'index']);
        Route::get('/users/online', [UserController::class, 'online']);
        Route::get('/users/{user:username}', [UserController::class, 'show']);

        // Interests
        Route::get('/interests', [InterestController::class, 'index']);
        Route::post('/profile/interests', [InterestController::class, 'sync']);

        // Posts
        Route::get('/posts', [PostController::class, 'index']);
        Route::post('/posts', [PostController::class, 'store']);
        Route::put('/posts/{post}', [PostController::class, 'update']);
        Route::delete('/posts/{post}', [PostController::class, 'destroy']);
        Route::post('/posts/{post}/like', [PostLikeController::class, 'toggle']);
        Route::post('/posts/{post}/comments', [PostCommentController::class, 'store']);
        Route::delete('/comments/{comment}', [PostCommentController::class, 'destroy']);

        // Events
        Route::get('/events', [EventController::class, 'index']);
        Route::get('/events/upcoming', [EventController::class, 'upcoming']);
        Route::post('/events', [EventController::class, 'store']);
        Route::match(['put', 'patch', 'post'], '/events/{event}', [EventController::class, 'update']);
        Route::post('/events/{event}/rsvp', [EventController::class, 'rsvp']);
        Route::delete('/events/{event}', [EventController::class, 'destroy']);

        // Admin
        Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
            Route::get('/users', [UserManagementController::class, 'index']);
            Route::patch('/users/{user}/approve', [UserManagementController::class, 'approve']);
            Route::patch('/users/{user}/reject', [UserManagementController::class, 'reject']);
            Route::patch('/users/{user}/ban', [UserManagementController::class, 'ban']);
            Route::patch('/users/{user}/unban', [UserManagementController::class, 'unban']);
            Route::patch('/users/{user}/promote', [UserManagementController::class, 'promote']);
            Route::patch('/users/{user}/demote', [UserManagementController::class, 'demote']);
            Route::delete('/users/{user}', [UserManagementController::class, 'destroy']);
            Route::post('/users/{user}/impersonate', [ImpersonateController::class, 'impersonateUser']);
        });
    });
});
