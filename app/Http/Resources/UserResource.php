<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Cache;
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
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'email' => $this->email,
            'avatar_url' => $this->avatar_url,
            'banner_url' => $this->banner_url,
            'status' => $this->status instanceof \BackedEnum ? $this->status->value : (string) $this->status,
            'role' => $this->role instanceof \BackedEnum ? $this->role->value : (string) $this->role,
            'bio' => $this->bio,
            'birth' => $this->birth?->format('Y-m-d'),
            'instagram' => $this->instagram,
            'spotify' => $this->spotify,
            'has_spotify_connected' => ! empty($this->spotify_refresh_token),
            'spotify_display_name' => $this->spotify_display_name,
            'spotify_avatar_url' => $this->spotify_avatar_url,
            'spotify_profile_url' => $this->spotify_profile_url,
            'lastfm_username' => $this->lastfm_username,
            'has_lastfm_connected' => ! empty($this->lastfm_username),
            'favorite_music' => $this->favorite_music,
            'spotify_current_track' => Cache::get("user_spotify_track_{$this->id}"),
            'letterboxd_username' => $this->letterboxd_username,
            'letterboxd_last_synced_at' => $this->letterboxd_last_synced_at,
            'letterboxd_is_syncing' => Cache::has("letterboxd_syncing_{$this->id}"),
            'xbox_gamertag' => $this->xbox_gamertag,
            'xbox_xuid' => $this->xbox_xuid,
            'xbox_last_synced_at' => $this->xbox_last_synced_at,
            'xbox_is_syncing' => Cache::has("xbox_syncing_{$this->id}"),
            'initials' => $this->initials(),
            'is_admin' => $this->isAdmin(),
            'is_online' => $isOnline,
            'theme' => $this->theme,
            'pinned_post_id' => $this->pinned_post_id,
            'pinned_post' => $this->whenLoaded('pinnedPost'),
            'avatar' => MediaResource::make($this->whenLoaded('avatar')),
            'banner' => MediaResource::make($this->whenLoaded('banner')),
            'interests' => InterestResource::collection($this->whenLoaded('interests')),
            'created_at' => $this->created_at,
        ];
    }
}
