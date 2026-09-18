<?php

use App\Http\Controllers\Api\Admin\UserManagementController;
use App\Http\Controllers\Api\Auth\ImpersonateController;
use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Auth\LogoutController;
use App\Http\Controllers\Api\Auth\RegisterController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\GroupController;
use App\Http\Controllers\Api\GroupMessageController;
use App\Http\Controllers\Api\InterestController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PostCommentController;
use App\Http\Controllers\Api\PostCommentLikeController;
use App\Http\Controllers\Api\PostController;
use App\Http\Controllers\Api\PostLikeController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

// Broadcasting authentication for private channels with Sanctum Bearer token
Broadcast::routes(['middleware' => ['auth:sanctum']]);

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
        Route::post('/profile/theme-background', [ProfileController::class, 'uploadThemeBackground']);
        Route::delete('/profile/theme', [ProfileController::class, 'resetTheme']);

        // Users
        Route::get('/users', [UserController::class, 'index']);
        Route::get('/users/online', [UserController::class, 'online']);
        Route::get('/users/{user:username}', [UserController::class, 'show']);

        // Interests
        Route::get('/interests', [InterestController::class, 'index']);
        Route::post('/profile/interests', [InterestController::class, 'sync']);

        // Posts
        Route::get('/posts', [PostController::class, 'index']);
        Route::get('/posts/{post}', [PostController::class, 'show']);
        Route::post('/posts', [PostController::class, 'store']);
        Route::put('/posts/{post}', [PostController::class, 'update']);
        Route::delete('/posts/{post}', [PostController::class, 'destroy']);
        Route::post('/posts/{post}/like', [PostLikeController::class, 'toggle']);
        Route::post('/posts/{post}/comments', [PostCommentController::class, 'store']);
        Route::put('/comments/{comment}', [PostCommentController::class, 'update']);
        Route::delete('/comments/{comment}', [PostCommentController::class, 'destroy']);
        Route::post('/comments/{comment}/like', [PostCommentLikeController::class, 'toggle']);

        // Events
        Route::get('/events', [EventController::class, 'index']);
        Route::get('/events/upcoming', [EventController::class, 'upcoming']);
        Route::post('/events', [EventController::class, 'store']);
        Route::get('/events/{event}', [EventController::class, 'show']);
        Route::match(['put', 'patch', 'post'], '/events/{event}', [EventController::class, 'update']);
        Route::post('/events/{event}/rsvp', [EventController::class, 'rsvp']);
        Route::post('/events/{event}/invite', [EventController::class, 'inviteGuests']);
        Route::delete('/events/{event}', [EventController::class, 'destroy']);

        // Groups
        Route::get('/groups', [GroupController::class, 'index']);
        Route::post('/groups', [GroupController::class, 'store']);
        Route::get('/groups/{group}', [GroupController::class, 'show']);
        Route::match(['put', 'patch', 'post'], '/groups/{group}', [GroupController::class, 'update']);
        Route::delete('/groups/{group}', [GroupController::class, 'destroy']);
        Route::post('/groups/{group}/invite', [GroupController::class, 'invite']);
        Route::post('/groups/{group}/accept-invite', [GroupController::class, 'acceptInvite']);
        Route::post('/groups/{group}/decline-invite', [GroupController::class, 'declineInvite']);
        Route::post('/groups/{group}/leave', [GroupController::class, 'leave']);
        Route::post('/groups/{group}/read', [GroupController::class, 'markAsRead']);
        Route::get('/groups/{group}/members', [GroupController::class, 'members']);

        // Group Messages (Chat)
        Route::get('/groups/{group}/messages', [GroupMessageController::class, 'index']);
        Route::post('/groups/{group}/messages', [GroupMessageController::class, 'store']);
        Route::put('/groups/{group}/messages/{message}', [GroupMessageController::class, 'update']);
        Route::delete('/groups/{group}/messages/{message}', [GroupMessageController::class, 'destroy']);

        // Notifications
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::get('/notifications/category-counts', [NotificationController::class, 'categoryCounts']);
        Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markAsRead']);
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);

        // Admin
        Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
            Route::get('/users', [UserManagementController::class, 'index']);
            Route::match(['patch', 'post'], '/users/{user}/approve', [UserManagementController::class, 'approve']);
            Route::match(['patch', 'post'], '/users/{user}/reject', [UserManagementController::class, 'reject']);
            Route::match(['patch', 'post'], '/users/{user}/ban', [UserManagementController::class, 'ban']);
            Route::match(['patch', 'post'], '/users/{user}/unban', [UserManagementController::class, 'unban']);
            Route::match(['patch', 'post'], '/users/{user}/promote', [UserManagementController::class, 'promote']);
            Route::match(['patch', 'post'], '/users/{user}/demote', [UserManagementController::class, 'demote']);
            Route::delete('/users/{user}', [UserManagementController::class, 'destroy']);
            Route::post('/users/{user}/impersonate', [ImpersonateController::class, 'impersonateUser']);
        });
    });
});
