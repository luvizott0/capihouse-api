<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserListResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Enums\UserStatuses;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        $user = auth()->user()->load(['avatar', 'banner', 'interests']);
        return new UserResource($user);
    }

    public function show(User $user)
    {
        $user->load(['avatar', 'banner', 'interests']);
        return new UserResource($user);
    }

    public function online()
    {
        $onlineUsers = User::whereHas('sessions', function ($query) {
            $query->where('last_activity', '>=', now()->subMinutes(5)->getTimestamp());
        })->get();
        
        // As a fallback since we might not have 'sessions' relation on User explicitly,
        // we can query the DB table directly.
        $onlineUserIds = DB::table('sessions')
            ->whereNotNull('user_id')
            ->where('last_activity', '>=', now()->subMinutes(5)->getTimestamp())
            ->pluck('user_id');
            
        $users = User::whereIn('id', $onlineUserIds)->get();

        return UserListResource::collection($users);
    }
}
