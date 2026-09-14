<?php

namespace App\Http\Controllers\Api;

use App\Enums\MediaType;
use App\Http\Controllers\Controller;
use App\Models\Hashtag;
use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PostController extends Controller
{
    public function index(Request $request)
    {
        $userId = auth()->id();

        $posts = Post::with([
            'user',
            'media',
            'feeling',
            'hashtags',
            'comments.user',
            'likes'
        ])
        ->withCount(['likes', 'comments'])
        ->latest()
        ->paginate(15);

        // Transform collection to append is_liked by current user
        $posts->getCollection()->transform(function ($post) use ($userId) {
            $post->is_liked = $post->likes->contains('user_id', $userId);
            return $post;
        });

        return response()->json($posts);
    }

    public function store(Request $request)
    {
        $request->validate([
            'content' => 'nullable|string|max:2000',
            'feeling_name' => 'nullable|string|max:10',
            'feeling_emoji' => 'nullable|string|max:32',
            'hashtags' => 'nullable|array',
            'hashtags.*' => 'string|max:50',
            'media' => 'nullable|array|max:5',
            'media.*' => 'file|mimes:jpg,jpeg,png,gif,webp,mp4,mov|max:20480',
        ]);

        if (!$request->filled('content') && !$request->hasFile('media')) {
            return response()->json(['message' => 'O post precisa ter texto ou mídia.'], 422);
        }

        $post = Post::create([
            'user_id' => auth()->id(),
            'content' => $request->input('content'),
        ]);

        // Feelings
        if ($request->filled('feeling_name')) {
            $post->feeling()->create([
                'name' => mb_substr(trim($request->input('feeling_name')), 0, 10),
                'emoji' => $request->input('feeling_emoji') ?? '😊',
            ]);
        }

        // Hashtags
        if ($request->has('hashtags')) {
            $hashtagIds = [];
            foreach ($request->input('hashtags') as $tagName) {
                $cleanName = ltrim(trim($tagName), '#');
                if ($cleanName) {
                    $hashtag = Hashtag::firstOrCreate(['name' => $cleanName]);
                    $hashtagIds[] = $hashtag->id;
                }
            }
            $post->hashtags()->sync($hashtagIds);
        }

        // Medias
        if ($request->hasFile('media')) {
            foreach ($request->file('media') as $file) {
                $mime = $file->getMimeType();
                $type = str_starts_with($mime, 'video/') ? MediaType::VIDEO : MediaType::IMAGE;
                $path = $file->store("posts/{$post->id}", 'public');

                $post->media()->create([
                    'path' => $path,
                    'type' => $type,
                    'collection_name' => 'post_media',
                ]);
            }
        }

        $post->load(['user', 'media', 'feeling', 'hashtags', 'comments.user', 'likes']);
        $post->is_liked = false;

        return response()->json($post, 201);
    }

    public function update(Request $request, Post $post)
    {
        if ($post->user_id !== auth()->id() && !auth()->user()->isAdmin()) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        $request->validate([
            'content' => 'nullable|string|max:2000',
            'feeling_name' => 'nullable|string|max:10',
            'feeling_emoji' => 'nullable|string|max:32',
            'hashtags' => 'nullable|array',
            'hashtags.*' => 'string|max:50',
        ]);

        if (!$request->filled('content') && !$post->media()->exists()) {
            return response()->json(['message' => 'O post precisa ter texto ou mídia.'], 422);
        }

        $post->update([
            'content' => $request->input('content'),
        ]);

        // Feelings
        if ($request->filled('feeling_name')) {
            $name = mb_substr(trim($request->input('feeling_name')), 0, 10);
            $emoji = $request->input('feeling_emoji') ?? '😊';
            if ($post->feeling) {
                $post->feeling->update([
                    'name' => $name,
                    'emoji' => $emoji,
                ]);
            } else {
                $post->feeling()->create([
                    'name' => $name,
                    'emoji' => $emoji,
                ]);
            }
        } else {
            if ($post->feeling) {
                $post->feeling->delete();
            }
        }

        // Hashtags
        if ($request->has('hashtags')) {
            $hashtagIds = [];
            foreach ($request->input('hashtags') as $tagName) {
                $cleanName = ltrim(trim($tagName), '#');
                if ($cleanName) {
                    $hashtag = Hashtag::firstOrCreate(['name' => $cleanName]);
                    $hashtagIds[] = $hashtag->id;
                }
            }
            $post->hashtags()->sync($hashtagIds);
        }

        $post->load(['user', 'media', 'feeling', 'hashtags', 'comments.user', 'likes']);
        $post->is_liked = $post->likes()->where('user_id', auth()->id())->exists();

        return response()->json($post);
    }

    public function destroy(Post $post)
    {
        if ($post->user_id !== auth()->id() && !auth()->user()->isAdmin()) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        // Delete associated media files
        foreach ($post->media as $media) {
            $relative = str_replace('/storage/', '', $media->path);
            Storage::disk('public')->delete($relative);
            $media->delete();
        }

        $post->delete();

        return response()->json(['message' => 'Post excluído com sucesso.']);
    }
}
