<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isOnline = DB::table('sessions')
            ->where('user_id', $this->id)
            ->where('last_activity', '>=', now()->subMinutes(5)->getTimestamp())
            ->exists();

        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'username'   => $this->username,
            'email'      => $this->email,
            'avatar_url' => $this->avatar_url,
            'banner_url' => $this->banner_url,
            'status'     => $this->status instanceof \BackedEnum ? $this->status->value : (string) $this->status,
            'role'       => $this->role instanceof \BackedEnum ? $this->role->value : (string) $this->role,
            'bio'        => $this->bio,
            'birth'      => $this->birth,
            'instagram'  => $this->instagram,
            'spotify'    => $this->spotify,
            'initials'   => $this->initials(),
            'is_admin'   => $this->isAdmin(),
            'is_online'  => $isOnline,
            'theme'      => $this->theme,
            'avatar'     => MediaResource::make($this->whenLoaded('avatar')),
            'banner'     => MediaResource::make($this->whenLoaded('banner')),
            'interests'  => InterestResource::collection($this->whenLoaded('interests')),
            'created_at' => $this->created_at,
        ];
    }
}
