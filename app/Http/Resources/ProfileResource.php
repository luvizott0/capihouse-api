<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class ProfileResource extends UserResource
{
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);

        $data['posts_count'] = $this->posts()->count();
        $data['post_likes_count'] = $this->postLikes()->count();
        $data['events_count'] = $this->events()->count();
        $data['interests'] = InterestResource::collection($this->interests);

        return $data;
    }
}
