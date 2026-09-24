<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserStatuses;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserListResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $query = User::where('status', UserStatuses::APPROVED);

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%");
            });
        }

        $users = $query->orderBy('name')->take(100)->get();

        return UserListResource::collection($users);
    }

    public function me()
    {
        $userId = auth()->id();
        $user = auth()->user()->load([
            'avatar',
            'banner',
            'interests',
            'pinnedPost' => fn ($q) => $q->with(PostController::postRelations($userId))->withCount(['likes', 'comments']),
        ]);

        if ($user->pinnedPost) {
            PostController::formatPost($user->pinnedPost, $userId);
        }

        return new UserResource($user);
    }

    public function show(User $user)
    {
        $userId = auth()->id();
        $user->load([
            'avatar',
            'banner',
            'interests',
            'pinnedPost' => fn ($q) => $q->with(PostController::postRelations($userId))->withCount(['likes', 'comments']),
        ]);

        if ($user->pinnedPost) {
            PostController::formatPost($user->pinnedPost, $userId);
        }

        return new UserResource($user);
    }

    public function online()
    {
        $users = User::whereNotNull('last_seen_at')
            ->where('last_seen_at', '>=', now()->subMinutes(5))
            ->get();

        return UserListResource::collection($users);
    }
}
