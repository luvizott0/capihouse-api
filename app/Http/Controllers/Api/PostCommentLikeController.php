<?php

namespace App\Http\Controllers\Api;

use App\Events\CommentLiked;
use App\Http\Controllers\Controller;
use App\Models\PostComment;
use App\Models\PostCommentLike;
use App\Services\NotificationDispatcherService;

class PostCommentLikeController extends Controller
{
    public function toggle(PostComment $comment)
    {
        $userId = auth()->id();
        $like = PostCommentLike::where('comment_id', $comment->id)->where('user_id', $userId)->first();

        if ($like) {
            $like->delete();
            $comment->decrement('likes_count');
            $isLiked = false;
        } else {
            PostCommentLike::create([
                'comment_id' => $comment->id,
                'user_id' => $userId,
            ]);
            $comment->increment('likes_count');
            $isLiked = true;

            if ($comment->user_id !== $userId) {
                $liker = auth()->user();
                $snippet = mb_strimwidth($comment->content, 0, 80, '...');
                NotificationDispatcherService::send(
                    recipient: $comment->user_id,
                    type: 'comment_like',
                    title: 'Nova curtida no comentário',
                    content: "{$liker->name} curtiu seu comentário: \"{$snippet}\"",
                    data: [
                        'post_id' => $comment->post_id,
                        'comment_id' => $comment->id,
                        'liker_id' => $liker->id,
                        'liker_name' => $liker->name,
                        'liker_username' => $liker->username,
                        'liker_avatar' => $liker->avatar_url,
                    ],
                    url: '/posts/'.$comment->post_id
                );
            }
        }

        $freshComment = $comment->fresh();
        $freshLikesCount = max(0, $freshComment ? $freshComment->likes_count : 0);
        $post = $comment->post;
        $groupId = $post?->group_id;

        // Broadcast CommentLiked safely
        try {
            broadcast(new CommentLiked($comment->post_id, $comment->id, $isLiked, $freshLikesCount, $userId, $groupId));
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json([
            'comment_id' => $comment->id,
            'is_liked' => $isLiked,
            'likes_count' => $freshLikesCount,
        ]);
    }
}
