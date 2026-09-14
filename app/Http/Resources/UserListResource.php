<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

class UserListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isOnline = DB::table('sessions')
            ->where('user_id', $this->id)
            ->where('last_activity', '>=', now()->subMinutes(5)->getTimestamp())
            ->exists();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'avatar_url' => $this->avatar_url,
            'status' => $this->status,
            'role' => $this->role,
            'initials' => $this->initials(),
            'is_online' => $isOnline,
            'created_at' => $this->created_at,
        ];
    }
}
