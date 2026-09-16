<?php

namespace App\Http\Controllers\Api;

use App\Events\CommentCreated;
use App\Events\CommentDeleted;
use App\Events\CommentUpdated;
use App\Events\NotificationSent;
use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\Post;
use App\Models\PostComment;
use Illuminate\Http\Request;

class PostCommentController extends Controller
{
    public function store(Request $request, Post $post)
    {
        $request->validate([
            'content' => 'required|string|max:500',
        ]);

        $comment = $post->comments()->create([
            'user_id' => auth()->id(),
            'content' => $request->input('content'),
        ]);

        $post->increment('comments_count');

        $comment->load('user');

        if ($post->user_id !== auth()->id()) {
            $commenter = auth()->user();
            $snippet = mb_strimwidth($comment->content, 0, 80, '...');
            $notification = AppNotification::create([
                'user_id' => $post->user_id,
                'type' => 'post_comment',
                'title' => 'Novo comentário',
                'content' => "{$commenter->name} comentou na sua publicação: \"{$snippet}\"",
                'data' => [
                    'post_id' => $post->id,
                    'comment_id' => $comment->id,
                    'commenter_id' => $commenter->id,
                    'commenter_name' => $commenter->name,
                    'commenter_username' => $commenter->username,
                    'commenter_avatar' => $commenter->avatar_url,
                ],
            ]);

            $unreadCount = AppNotification::where('user_id', $post->user_id)
                ->whereNull('read_at')
                ->count();

            try {
                broadcast(new NotificationSent($notification, $unreadCount));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        // Broadcast CommentCreated to all users in the channel safely
        $freshCommentsCount = $post->fresh()->comments_count;
        try {
            broadcast(new CommentCreated($comment, $freshCommentsCount, $post->group_id))->toOthers();
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json($comment, 201);
    }

    public function update(Request $request, PostComment $comment)
    {
        if ($comment->user_id !== auth()->id() && !auth()->user()->isAdmin()) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        $request->validate([
            'content' => 'required|string|max:500',
        ]);

        $comment->update([
            'content' => $request->input('content'),
        ]);

        $comment->load('user');

        $post = $comment->post;
        $groupId = $post?->group_id;

        // Broadcast CommentUpdated safely
        try {
            broadcast(new CommentUpdated($comment, $groupId))->toOthers();
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json($comment);
    }

    public function destroy(PostComment $comment)
    {
        $isPostAuthor = $comment->post && $comment->post->user_id === auth()->id();
        if ($comment->user_id !== auth()->id() && !$isPostAuthor && !auth()->user()->isAdmin()) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        $post = $comment->post;
        $commentId = $comment->id;
        $postId = $comment->post_id;
        $groupId = $post?->group_id;

        $comment->delete();

        $freshCommentsCount = 0;
        if ($post) {
            $post->decrement('comments_count');
            $freshCommentsCount = max(0, $post->fresh()->comments_count);
        }

        // Broadcast CommentDeleted to all users in the channel safely
        try {
            broadcast(new CommentDeleted($commentId, $postId, $freshCommentsCount, $groupId))->toOthers();
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json(['message' => 'Comentário excluído.']);
    }
}
