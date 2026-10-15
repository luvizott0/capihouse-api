<?php

use App\Http\Controllers\Api\Admin\UserManagementController;
use App\Http\Controllers\Api\Auth\ImpersonateController;
use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Auth\LogoutController;
use App\Http\Controllers\Api\Auth\RegisterController;
use App\Http\Controllers\Api\CapsuleController;
use App\Http\Controllers\Api\CapyFlixController;
use App\Http\Controllers\Api\CapyGamesController;
use App\Http\Controllers\Api\CapyShowTimeController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\GameReviewController;
use App\Http\Controllers\Api\GroupController;
use App\Http\Controllers\Api\GroupMessageController;
use App\Http\Controllers\Api\InterestController;
use App\Http\Controllers\Api\LastFmController;
use App\Http\Controllers\Api\LetterboxdController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\NotificationPreferenceController;
use App\Http\Controllers\Api\PollController;
use App\Http\Controllers\Api\PostCommentController;
use App\Http\Controllers\Api\PostCommentLikeController;
use App\Http\Controllers\Api\PostController;
use App\Http\Controllers\Api\PostLikeController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\PushSubscriptionController;
use App\Http\Controllers\Api\SpotifyController;
use App\Http\Controllers\Api\SystemStatusController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\XboxController;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

// Broadcasting authentication for private channels with Sanctum Bearer token
Broadcast::routes(['middleware' => ['auth:sanctum']]);

// Public auth routes
Route::post('/auth/login', [LoginController::class, 'login']);
Route::post('/auth/register', [RegisterController::class, 'register']);
Route::post('/auth/impersonate', [ImpersonateController::class, 'impersonate']);
Route::get('/auth/dev-users', [ImpersonateController::class, 'devUsers']);

// Spotify public callback (redirect from Spotify OAuth)
Route::match(['get', 'post'], '/spotify/callback', [SpotifyController::class, 'callback']);

// CapyShowTime & CapyFlix public feed endpoints
Route::prefix('capyshowtime/public')->group(function () {
    Route::get('/trending', [CapyShowTimeController::class, 'trending']);
    Route::get('/friends-watching', [CapyShowTimeController::class, 'friendsWatching']);
    Route::get('/friends-top-rated', [CapyShowTimeController::class, 'friendsTopRated']);
});

// CapyFlix (Streaming Híbrido, Gerenciador NAS 300GB & Debrid)
Route::prefix('capyflix')->group(function () {
    Route::get('/storage', [CapyFlixController::class, 'storage']);
    Route::delete('/file', [CapyFlixController::class, 'deleteFile']);
    Route::get('/downloads', [CapyFlixController::class, 'downloads']);
    Route::post('/download', [CapyFlixController::class, 'startDownload']);
    Route::delete('/download/{id}', [CapyFlixController::class, 'cancelDownload']);
    Route::get('/resolve-streams', [CapyFlixController::class, 'resolveStreams']);
    Route::get('/debrid-config', [CapyFlixController::class, 'getDebridConfig']);
    Route::post('/debrid-config', [CapyFlixController::class, 'saveDebridConfig']);
});

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
        Route::put('/profile/favorite-music', [SpotifyController::class, 'updateFavoriteMusic']);
        Route::delete('/profile/favorite-music', [SpotifyController::class, 'removeFavoriteMusic']);
        Route::get('/spotify/auth-url', [SpotifyController::class, 'authUrl']);
        Route::post('/spotify/disconnect', [SpotifyController::class, 'disconnect']);
        Route::get('/spotify/search', [SpotifyController::class, 'search']);
        Route::post('/spotify/repost', [SpotifyController::class, 'repostMusic']);
        Route::post('/profile/letterboxd/connect', [LetterboxdController::class, 'connect']);
        Route::post('/profile/letterboxd/disconnect', [LetterboxdController::class, 'disconnect']);
        Route::post('/profile/letterboxd/sync', [LetterboxdController::class, 'sync']);
        Route::post('/profile/lastfm/connect', [LastFmController::class, 'connect']);
        Route::post('/profile/lastfm/disconnect', [LastFmController::class, 'disconnect']);
        Route::post('/profile/xbox/connect', [XboxController::class, 'connect']);
        Route::post('/profile/xbox/disconnect', [XboxController::class, 'disconnect']);
        Route::post('/profile/xbox/sync', [XboxController::class, 'sync']);
        Route::get('/entertainment/games/search', [GameReviewController::class, 'search']);
        Route::post('/entertainment/games', [GameReviewController::class, 'store']);

        // Users
        Route::get('/users', [UserController::class, 'index']);
        Route::get('/users/online', [UserController::class, 'online']);
        Route::get('/users/{user:username}', [UserController::class, 'show']);
        Route::get('/users/{user:username}/spotify-status', [SpotifyController::class, 'currentlyPlaying']);
        Route::get('/users/{user:username}/music-status', [SpotifyController::class, 'currentlyPlaying']);

        // Interests
        Route::get('/interests', [InterestController::class, 'index']);
        Route::post('/profile/interests', [InterestController::class, 'sync']);

        // System Status
        Route::get('/system/storage-status', [SystemStatusController::class, 'storageStatus']);

        // Posts
        Route::get('/posts', [PostController::class, 'index']);
        Route::get('/posts/{post}', [PostController::class, 'show']);
        Route::post('/posts', [PostController::class, 'store']);
        Route::match(['put', 'patch', 'post'], '/posts/{post}', [PostController::class, 'update']);
        Route::delete('/posts/{post}', [PostController::class, 'destroy']);
        Route::post('/posts/{post}/pin', [PostController::class, 'pin']);
        Route::post('/posts/{post}/unpin', [PostController::class, 'unpin']);
        Route::post('/posts/{post}/like', [PostLikeController::class, 'toggle']);
        Route::post('/posts/{post}/poll/vote', [PollController::class, 'vote']);
        Route::get('/posts/{post}/poll/voters', [PollController::class, 'voters']);
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

        // Notification Preferences
        Route::get('/user/notification-preferences', [NotificationPreferenceController::class, 'show']);
        Route::put('/user/notification-preferences', [NotificationPreferenceController::class, 'update']);

        // Web Push Subscriptions
        Route::get('/push/key', [PushSubscriptionController::class, 'key']);
        Route::post('/push/subscribe', [PushSubscriptionController::class, 'subscribe']);
        Route::post('/push/unsubscribe', [PushSubscriptionController::class, 'unsubscribe']);
        Route::post('/push/test', [PushSubscriptionController::class, 'test']);

        // CapyShowTime (TV Show Time clone)
        Route::prefix('capyshowtime')->group(function () {
            Route::get('/search', [CapyShowTimeController::class, 'search']);
            Route::get('/trending', [CapyShowTimeController::class, 'trending']);
            Route::get('/popular', [CapyShowTimeController::class, 'popular']);
            Route::get('/genres', [CapyShowTimeController::class, 'genres']);
            Route::get('/discover', [CapyShowTimeController::class, 'discover']);
            Route::get('/series/{tmdbId}', [CapyShowTimeController::class, 'showSeries']);
            Route::get('/series/{tmdbId}/season/{seasonNumber}', [CapyShowTimeController::class, 'showSeason']);
            Route::post('/series/{tmdbId}/season/{seasonNumber}/watch-all', [CapyShowTimeController::class, 'markSeasonWatched']);
            Route::post('/series/status', [CapyShowTimeController::class, 'setStatus']);
            Route::post('/episodes/rate', [CapyShowTimeController::class, 'rateEpisode']);
            Route::post('/episodes/watch-previous', [CapyShowTimeController::class, 'markPreviousEpisodes']);
            Route::get('/my-list', [CapyShowTimeController::class, 'myList']);
            Route::post('/episodes/sync-watch', [CapyShowTimeController::class, 'syncWatchedByTmdb']);
            Route::get('/watch-progress', [CapyShowTimeController::class, 'getWatchProgress']);
            Route::post('/watch-progress', [CapyShowTimeController::class, 'saveWatchProgress']);
            Route::get('/episodes/{episodeId}/comments', [CapyShowTimeController::class, 'getEpisodeComments']);
            Route::post('/episodes/comments', [CapyShowTimeController::class, 'addEpisodeComment']);
            Route::put('/comments/{commentId}', [CapyShowTimeController::class, 'updateComment']);
            Route::delete('/comments/{commentId}', [CapyShowTimeController::class, 'deleteComment']);
            Route::post('/comments/{commentId}/like', [CapyShowTimeController::class, 'toggleCommentLike']);
            Route::get('/community-activity', [CapyShowTimeController::class, 'communityActivity']);
            Route::get('/friends-watching', [CapyShowTimeController::class, 'friendsWatching']);
            Route::get('/friends-top-rated', [CapyShowTimeController::class, 'friendsTopRated']);
            Route::get('/users', [CapyShowTimeController::class, 'users']);
            Route::get('/users/{userId}', [CapyShowTimeController::class, 'userProfile']);
        });

        // CapyCaps (Time Capsule & Memories Journal)
        Route::prefix('capsule')->group(function () {
            Route::get('/timeline', [CapsuleController::class, 'timelineStats']);
            Route::get('/on-this-day', [CapsuleController::class, 'onThisDay']);
            Route::get('/tags', [CapsuleController::class, 'tags']);
            Route::get('/memories', [CapsuleController::class, 'index']);
            Route::post('/memories', [CapsuleController::class, 'store']);
            Route::get('/memories/{id}', [CapsuleController::class, 'show']);
            Route::match(['put', 'patch', 'post'], '/memories/{id}', [CapsuleController::class, 'update']);
            Route::delete('/memories/{id}', [CapsuleController::class, 'destroy']);
            Route::post('/memories/{id}/toggle-favorite', [CapsuleController::class, 'toggleFavorite']);
        });

        // CapyGames (Gaming Tracker, Backlog & Community)
        Route::prefix('capygames')->group(function () {
            Route::get('/search', [CapyGamesController::class, 'search']);
            Route::get('/popular', [CapyGamesController::class, 'popular']);
            Route::get('/games/{id}', [CapyGamesController::class, 'showGame']);
            Route::get('/my-library', [CapyGamesController::class, 'myLibrary']);
            Route::post('/library/status', [CapyGamesController::class, 'setStatus']);
            Route::delete('/library/{id}', [CapyGamesController::class, 'removeGame']);
            Route::get('/feed', [CapyGamesController::class, 'communityFeed']);
            Route::get('/users', [CapyGamesController::class, 'users']);
            Route::get('/users/{id}', [CapyGamesController::class, 'userProfile']);
            Route::post('/connect/steam', [CapyGamesController::class, 'connectSteam']);
            Route::post('/disconnect/steam', [CapyGamesController::class, 'disconnectSteam']);
            Route::post('/sync/steam', [CapyGamesController::class, 'syncSteam']);
            Route::post('/sync/xbox', [CapyGamesController::class, 'syncXbox']);
        });

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
