<?php

namespace App\Http\Controllers\Api;

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
            AppNotification::create([
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
        }

        return response()->json($comment, 201);
    }

    public function destroy(PostComment $comment)
    {
        if ($comment->user_id !== auth()->id() && !auth()->user()->isAdmin()) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        $post = $comment->post;
        $comment->delete();

        if ($post) {
            $post->decrement('comments_count');
        }

        return response()->json(['message' => 'Comentário excluído.']);
    }
}
