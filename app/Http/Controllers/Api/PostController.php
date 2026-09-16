<?php

namespace App\Http\Controllers\Api;

use App\Enums\MediaType;
use App\Events\PostCreated;
use App\Events\PostDeleted;
use App\Events\PostUpdated;
use App\Http\Controllers\Controller;
use App\Models\Hashtag;
use App\Models\Post;
use App\Services\MentionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PostController extends Controller
{
    public function index(Request $request)
    {
        $userId = auth()->id();
        $isAdmin = auth()->user()->isAdmin();

        $query = Post::with([
            'user',
            'group:id,name',
            'media',
            'feeling',
            'hashtags',
            'mentions:id,name,username,avatar_url',
            'comments.user',
            'likes'
        ])
        ->withCount(['likes', 'comments']);

        if ($request->filled('group_id')) {
            $groupId = $request->input('group_id');
            // Check if user is accepted member of the group
            $isMember = auth()->user()->acceptedGroups()->where('groups.id', $groupId)->exists();
            if (!$isMember && !$isAdmin) {
                return response()->json(['message' => 'Você não tem permissão para visualizar posts deste grupo.'], 403);
            }
            $query->where('group_id', $groupId);
        } else {
            // General feed: public posts + posts of groups user is an accepted member of
            $query->where(function ($q) use ($userId, $isAdmin) {
                $q->whereNull('group_id');
                if ($isAdmin) {
                    $q->orWhereNotNull('group_id');
                } else {
                    $q->orWhereHas('group.acceptedMembers', function ($m) use ($userId) {
                        $m->where('users.id', $userId);
                    });
                }
            });
        }
        
        if ($request->filled('q') || $request->filled('search')) {
            $search = $request->input('q', $request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('content', 'like', "%{$search}%")
                  ->orWhereHas('user', function ($uq) use ($search) {
                      $uq->where('name', 'like', "%{$search}%")
                         ->orWhere('username', 'like', "%{$search}%");
                  })
                  ->orWhereHas('hashtags', function ($hq) use ($search) {
                      $cleanTag = ltrim($search, '#');
                      $hq->where('name', 'like', "%{$cleanTag}%");
                  });
            });
        }

        if ($request->filled('date')) {
            $query->whereDate('created_at', $request->input('date'));
        }

        if ($request->filled('user_id')) {
            $targetUserId = $request->input('user_id');
            $query->where(function ($q) use ($targetUserId) {
                $q->where('user_id', $targetUserId)
                  ->orWhereHas('mentions', function ($mq) use ($targetUserId) {
                      $mq->where('users.id', $targetUserId);
                  });
            });
        }

        $posts = $query->latest()->paginate(15);

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
            'group_id' => 'nullable|exists:groups,id',
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

        $groupId = $request->input('group_id');
        if ($groupId) {
            $isMember = auth()->user()->acceptedGroups()->where('groups.id', $groupId)->exists();
            if (!$isMember && !auth()->user()->isAdmin()) {
                return response()->json(['message' => 'Você precisa ser membro do grupo para publicar nele.'], 403);
            }
        }

        $post = Post::create([
            'user_id' => auth()->id(),
            'group_id' => $groupId,
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
            $disk = config('filesystems.default', 'public');
            foreach ($request->file('media') as $file) {
                $mime = $file->getMimeType();
                $type = str_starts_with($mime, 'video/') ? MediaType::VIDEO : MediaType::IMAGE;
                $path = $file->store("posts/{$post->id}", $disk);

                $post->media()->create([
                    'path' => $path,
                    'type' => $type,
                    'collection_name' => 'post_media',
                ]);
            }
        }

        // Mentions
        MentionService::syncPostMentions($post, auth()->user());

        $post->load(['user', 'group:id,name', 'media', 'feeling', 'hashtags', 'mentions:id,name,username,avatar_url', 'comments.user', 'likes']);
        $post->is_liked = false;

        // Broadcast event safely
        try {
            broadcast(new PostCreated($post));
        } catch (\Throwable $e) {
            report($e);
        }

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

        // Mentions
        MentionService::syncPostMentions($post, auth()->user());

        $post->load(['user', 'group:id,name', 'media', 'feeling', 'hashtags', 'mentions:id,name,username,avatar_url', 'comments.user', 'likes']);
        $post->is_liked = $post->likes()->where('user_id', auth()->id())->exists();

        // Broadcast event safely
        try {
            broadcast(new PostUpdated($post));
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json($post);
    }

    public function destroy(Post $post)
    {
        if ($post->user_id !== auth()->id() && !auth()->user()->isAdmin()) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        $postId = $post->id;
        $groupId = $post->group_id;

        // Delete associated media files
        foreach ($post->media as $media) {
            $media->delete();
        }

        $post->delete();

        // Broadcast event safely
        try {
            broadcast(new PostDeleted($postId, $groupId));
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json(['message' => 'Post excluído com sucesso.']);
    }
}
