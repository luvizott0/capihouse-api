<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\V1\Post\IndexPostRequest;
use App\Http\Requests\Api\V1\Post\StorePostRequest;
use App\Models\Hashtag;
use App\Models\Post;
use App\Transformers\PostTransformer;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Spatie\Fractal\Facades\Fractal;

class PostController extends ApiController
{
    /**
     * List posts with cursor pagination and category filters.
     */
    public function index(IndexPostRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $category = $validated['category'] ?? null;
        $perPage = (int) ($validated['per_page'] ?? 15);

        $query = Post::query()
            ->whereNull('event_id')
            ->with(['user', 'feeling', 'media', 'hashtags'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($category === 'tweet') {
            $query->whereDoesntHave('media');
        }

        if ($category === 'photo') {
            $query->whereHas('media', function ($mediaQuery) {
                $mediaQuery->where('type', 'image');
            });
        }

        if ($category === 'video') {
            $query->whereHas('media', function ($mediaQuery) {
                $mediaQuery->where('type', 'video');
            });
        }

        $posts = $query->cursorPaginate($perPage)->withQueryString();

        $data = Fractal::create()
            ->collection($posts->items(), new PostTransformer())
            ->toArray();

        return $this->success([
            'items' => $data['data'],
            'pagination' => [
                'per_page' => $posts->perPage(),
                'next_cursor' => $posts->nextCursor()?->encode(),
                'previous_cursor' => $posts->previousCursor()?->encode(),
                'has_more' => $posts->hasMorePages(),
            ],
        ], 'Posts carregados com sucesso.');
    }

    /**
     * Store a new post with optional media and hashtags.
     */
    public function store(StorePostRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $post = DB::transaction(function () use ($request, $validated) {
            $post = Post::create([
                'user_id' => $request->user()->id,
                'content' => $validated['content'] ?? null,
                'feeling_id' => $validated['feeling_id'] ?? null,
                'event_id' => null,
            ]);

            $mediaFiles = $request->file('media', []);

            if (! is_array($mediaFiles)) {
                $mediaFiles = $mediaFiles ? [$mediaFiles] : [];
            }

            foreach ($mediaFiles as $file) {
                if (! $file) {
                    continue;
                }

                $mimeType = $file->getMimeType() ?? '';
                $type = str_starts_with($mimeType, 'image/') ? 'image' : 'video';
                $path = $file->store('posts/'.$post->id, 'public');

                $post->media()->create([
                    'type' => $type,
                    'path' => $path,
                    'mime_type' => $mimeType,
                    'size_bytes' => $file->getSize() ?? 0,
                ]);
            }

            $hashtags = $validated['hashtags'] ?? [];
            $hashtagIds = collect($hashtags)
                ->map(fn (string $name): int => Hashtag::firstOrCreate(['name' => $name])->id)
                ->values()
                ->all();

            if ($hashtagIds !== []) {
                $post->hashtags()->sync($hashtagIds);
            }

            $post->load(['user', 'feeling', 'media', 'hashtags']);

            return $post;
        });

        $data = Fractal::create()
            ->item($post, new PostTransformer())
            ->toArray();

        return $this->created($data['data'], 'Post criado com sucesso.');
    }
}
