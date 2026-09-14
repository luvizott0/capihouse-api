<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
