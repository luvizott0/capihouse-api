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
use App\Services\MentionService;
use Illuminate\Http\Request;

class PostCommentController extends Controller
{
    public function store(Request $request, Post $post)
    {
        $request->validate([
            'content' => 'required|string|max:500',
            'parent_id' => 'nullable|integer|exists:post_comments,id',
        ]);

        $parentId = $request->input('parent_id');
        $parentComment = null;
        if ($parentId) {
            $parentComment = PostComment::where('id', $parentId)->where('post_id', $post->id)->first();
            if (! $parentComment) {
                return response()->json(['message' => 'O comentário pai não pertence a esta publicação.'], 422);
            }
        }

        $comment = $post->comments()->create([
            'user_id' => auth()->id(),
            'parent_id' => $parentComment ? $parentComment->id : null,
            'content' => $request->input('content'),
        ]);

        $post->increment('comments_count');

        $comment->load([
            'user',
            'mentions:id,name,username,avatar_url',
            'parent.user:id,name,username',
        ]);

        $mentionedIds = MentionService::syncCommentMentions($comment, auth()->user(), $post);
        $commenter = auth()->user();
        $snippet = mb_strimwidth($comment->content, 0, 80, '...');

        // If this is a reply to another comment, notify the parent comment's author
        if ($parentComment && $parentComment->user_id !== auth()->id() && ! in_array($parentComment->user_id, $mentionedIds)) {
            $replyNotification = AppNotification::create([
                'user_id' => $parentComment->user_id,
                'type' => 'comment_reply',
                'title' => 'Nova resposta',
                'content' => "{$commenter->name} respondeu ao seu comentário: \"{$snippet}\"",
                'data' => [
                    'post_id' => $post->id,
                    'comment_id' => $comment->id,
                    'parent_id' => $parentComment->id,
                    'replier_id' => $commenter->id,
                    'replier_name' => $commenter->name,
                    'replier_username' => $commenter->username,
                    'replier_avatar' => $commenter->avatar_url,
                ],
            ]);

            $unreadCount = AppNotification::where('user_id', $parentComment->user_id)
                ->whereNull('read_at')
                ->count();

            try {
                broadcast(new NotificationSent($replyNotification, $unreadCount));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        // Notify post author (avoid duplicate if post author is also the parent comment author)
        if (
            $post->user_id !== auth()->id() &&
            ! in_array($post->user_id, $mentionedIds) &&
            (! $parentComment || $post->user_id !== $parentComment->user_id)
        ) {
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

        $comment->is_liked = false;
        $comment->likes_count = 0;

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
        if ($comment->user_id !== auth()->id() && ! auth()->user()->isAdmin()) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        $request->validate([
            'content' => 'required|string|max:500',
        ]);

        $comment->update([
            'content' => $request->input('content'),
        ]);

        $post = $comment->post;
        if ($post) {
            MentionService::syncCommentMentions($comment, auth()->user(), $post);
        }

        $comment->load([
            'user',
            'mentions:id,name,username,avatar_url',
            'parent.user:id,name,username',
        ]);
        $comment->is_liked = $comment->likes()->where('user_id', auth()->id())->exists();

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
        if ($comment->user_id !== auth()->id() && ! $isPostAuthor && ! auth()->user()->isAdmin()) {
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
