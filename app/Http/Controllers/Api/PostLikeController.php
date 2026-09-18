<?php

namespace App\Http\Controllers\Api;

use App\Events\NotificationSent;
use App\Events\PostLiked;
use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\Post;
use App\Models\PostLike;

class PostLikeController extends Controller
{
    public function toggle(Post $post)
    {
        $userId = auth()->id();
        $like = PostLike::where('post_id', $post->id)->where('user_id', $userId)->first();

        if ($like) {
            $like->delete();
            $post->decrement('likes_count');
            $isLiked = false;
        } else {
            PostLike::create([
                'post_id' => $post->id,
                'user_id' => $userId,
            ]);
            $post->increment('likes_count');
            $isLiked = true;

            if ($post->user_id !== $userId) {
                $liker = auth()->user();
                \App\Services\NotificationDispatcherService::send(
                    recipient: $post->user_id,
                    type: 'post_like',
                    title: 'Nova curtida',
                    content: "{$liker->name} curtiu sua publicação.",
                    data: [
                        'post_id' => $post->id,
                        'liker_id' => $liker->id,
                        'liker_name' => $liker->name,
                        'liker_username' => $liker->username,
                        'liker_avatar' => $liker->avatar_url,
                    ],
                    url: '/feed?post=' . $post->id
                );
            }
        }

        $freshPost = $post->fresh();
        $freshLikesCount = max(0, $freshPost->likes_count);

        // Broadcast PostLiked to all users in the channel safely
        try {
            broadcast(new PostLiked($post->id, $isLiked, $freshLikesCount, $userId, $post->group_id, $post->event_id));
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json([
            'is_liked' => $isLiked,
            'likes_count' => $freshLikesCount,
        ]);
    }
}
