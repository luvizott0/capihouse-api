<?php

namespace App\Transformers;

use App\Models\Post;
use Illuminate\Support\Facades\URL;
use League\Fractal\TransformerAbstract;

class PostTransformer extends TransformerAbstract
{
    /**
     * Transform a Post model into an array.
     *
     * @return array<string, mixed>
     */
    public function transform(Post $post): array
    {
        $media = $post->media->map(function ($item): array {
            return [
                'id' => $item->id,
                'type' => $item->type,
                'url' => URL::to('/storage/'.ltrim($item->path, '/')),
                'path' => $item->path,
                'mime_type' => $item->mime_type,
                'size_bytes' => $item->size_bytes,
            ];
        })->values()->all();

        $hasImage = collect($media)->contains(fn (array $item): bool => $item['type'] === 'image');
        $hasVideo = collect($media)->contains(fn (array $item): bool => $item['type'] === 'video');

        return [
            'id' => $post->id,
            'content' => $post->content,
            'author' => [
                'id' => $post->user?->id,
                'name' => $post->user?->name,
                'username' => $post->user?->username,
            ],
            'feeling' => $post->feeling ? [
                'id' => $post->feeling->id,
                'name' => $post->feeling->name,
                'color' => $post->feeling->color,
                'emoji' => $post->feeling->emoji,
            ] : null,
            'hashtags' => $post->hashtags->pluck('name')->values()->all(),
            'media' => $media,
            'has_image' => $hasImage,
            'has_video' => $hasVideo,
            'created_at' => $post->created_at?->toIso8601String(),
            'updated_at' => $post->updated_at?->toIso8601String(),
        ];
    }
}
