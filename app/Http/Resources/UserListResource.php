<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isOnline = $this->last_seen_at !== null
            && $this->last_seen_at->gte(now()->subMinutes(5));

        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'avatar_url' => $this->avatar_url,
            'status' => $this->status instanceof \BackedEnum ? $this->status->value : (string) $this->status,
            'role' => $this->role instanceof \BackedEnum ? $this->role->value : (string) $this->role,
            'initials' => $this->initials(),
            'is_online' => $isOnline,
            'created_at' => $this->created_at,
        ];
    }
}
