<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\UserRoles;
use App\Enums\UserStatuses;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserListResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;

class UserManagementController extends Controller
{
    public function index(Request $request)
    {
        $query = User::query();

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%");
            });
        }

        $users = $query->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->latest('id')
            ->paginate(15);

        return UserListResource::collection($users);
    }

    public function approve(User $user)
    {
        $user->update(['status' => UserStatuses::APPROVED]);

        return new UserResource($user);
    }

    public function reject(User $user)
    {
        $user->update(['status' => UserStatuses::REJECTED]);

        return new UserResource($user);
    }

    public function ban(User $user)
    {
        $user->update(['status' => UserStatuses::BANNED]);
        $user->tokens()->delete();

        return new UserResource($user);
    }

    public function unban(User $user)
    {
        $user->update(['status' => UserStatuses::APPROVED]);

        return new UserResource($user);
    }

    public function promote(User $user)
    {
        $user->update(['role' => UserRoles::Admin]);

        return new UserResource($user);
    }

    public function demote(User $user)
    {
        $user->update(['role' => UserRoles::User]);

        return new UserResource($user);
    }

    public function destroy(User $user)
    {
        $user->delete();

        return response()->json(null, 204);
    }
}
