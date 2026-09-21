<?php

namespace App\Http\Controllers\Api;

use App\Enums\MediaType;
use App\Events\PostCreated;
use App\Events\PostDeleted;
use App\Events\PostUpdated;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Hashtag;
use App\Models\Post;
use App\Services\ImageOptimizerService;
use App\Services\MentionService;
use Illuminate\Http\Request;

class PostController extends Controller
{
    public function index(Request $request)
    {
        $userId = auth()->id();
        $isAdmin = auth()->user()->isAdmin();

        $query = Post::with([
            'user',
            'group:id,name',
            'event:id,name',
            'media',
            'feeling',
            'hashtags',
            'mentions:id,name,username,avatar_url',
            'comments.user',
            'comments.mentions:id,name,username,avatar_url',
            'comments.parent.user:id,name,username',
            'comments.likes' => fn ($q) => $q->where('user_id', $userId),
            'likes' => fn ($q) => $q->where('user_id', $userId),
            'poll.options',
            'poll.votes' => fn ($q) => $q->where('user_id', $userId),
            'repostedPost.user:id,name,username,avatar_url',
            'repostedPost.media',
        ])
            ->withCount(['likes', 'comments']);

        if ($request->filled('event_id')) {
            $eventId = $request->input('event_id');
            $event = Event::find($eventId);
            if (! $event) {
                return response()->json(['message' => 'Evento não encontrado.'], 404);
            }
            $isInvitedOrOwner = $event->user_id === $userId || $event->guests()->where('users.id', $userId)->exists();
            if (! $isInvitedOrOwner && ! $isAdmin) {
                return response()->json(['message' => 'Você não tem permissão para visualizar posts deste evento.'], 403);
            }
            $query->where('event_id', $eventId);
        } elseif ($request->filled('group_id')) {
            $groupId = $request->input('group_id');
            // Check if user is accepted member of the group
            $isMember = auth()->user()->acceptedGroups()->where('groups.id', $groupId)->exists();
            if (! $isMember && ! $isAdmin) {
                return response()->json(['message' => 'Você não tem permissão para visualizar posts deste grupo.'], 403);
            }
            $query->where('group_id', $groupId);
            $query->whereNull('event_id');
        } elseif ($request->input('category') === 'entertainment') {
            // Aba de entretenimento: apenas posts da categoria entertainment (sempre públicos, fora de grupos/eventos)
            $query->whereNull('event_id')->whereNull('group_id');
            $query->where('category', 'entertainment');
            if ($request->filled('entertainment_type')) {
                $query->where('entertainment_type', $request->input('entertainment_type'));
            }
        } else {
            // Feed geral: posts normais/reposts (categoria feed ou null) + posts de grupos que o usuário participa
            $query->whereNull('event_id');
            $query->where(function ($q) {
                $q->whereNull('category')->orWhere('category', 'feed');
            });
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
                    })
                    ->orWhere('metadata->film_title', 'like', "%{$search}%");
            });
        }

        if ($request->filled('start_date') || $request->filled('end_date')) {
            $startDate = $request->input('start_date');
            $endDate = $request->input('end_date');
            $query->where(function ($q) use ($startDate, $endDate) {
                if ($startDate && $endDate) {
                    $q->where(function ($sub) use ($startDate, $endDate) {
                        $sub->whereBetween('created_at', [$startDate.' 00:00:00', $endDate.' 23:59:59'])
                            ->orWhereBetween('watched_at', [$startDate.' 00:00:00', $endDate.' 23:59:59']);
                    });
                } elseif ($startDate) {
                    $q->where(function ($sub) use ($startDate) {
                        $sub->where('created_at', '>=', $startDate.' 00:00:00')
                            ->orWhere('watched_at', '>=', $startDate.' 00:00:00');
                    });
                } elseif ($endDate) {
                    $q->where(function ($sub) use ($endDate) {
                        $sub->where('created_at', '<=', $endDate.' 23:59:59')
                            ->orWhere('watched_at', '<=', $endDate.' 23:59:59');
                    });
                }
            });
        } elseif ($request->filled('date')) {
            $filterDate = $request->input('date');
            $query->where(function ($q) use ($filterDate) {
                $q->whereDate('created_at', $filterDate)
                    ->orWhereDate('watched_at', $filterDate);
            });
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

        if ($request->input('category') === 'entertainment') {
            $posts = $query->orderByRaw('COALESCE(watched_at, created_at) DESC')->orderByDesc('id')->paginate(15);
        } else {
            $posts = $query->latest()->paginate(15);
        }

        // Transform collection to append is_liked by current user
        $posts->getCollection()->transform(function ($post) use ($userId) {
            $post->is_liked = $post->likes->isNotEmpty();
            $this->formatPostPoll($post, $userId);
            if ($post->comments) {
                $post->comments->transform(function ($comment) {
                    $comment->is_liked = $comment->likes ? $comment->likes->isNotEmpty() : false;
                    unset($comment->likes);

                    return $comment;
                });
            }

            return $post;
        });

        return response()->json($posts);
    }

    public function show(Post $post)
    {
        $userId = auth()->id();
        $isAdmin = auth()->user()->isAdmin();

        if ($post->group_id) {
            $isMember = auth()->user()->acceptedGroups()->where('groups.id', $post->group_id)->exists();
            if (! $isMember && ! $isAdmin) {
                return response()->json(['message' => 'Você não tem permissão para visualizar este post.'], 403);
            }
        }

        if ($post->event_id) {
            $event = $post->event;
            $isInvitedOrOwner = $event && ($event->user_id === $userId || $event->guests()->where('users.id', $userId)->exists());
            if (! $isInvitedOrOwner && ! $isAdmin) {
                return response()->json(['message' => 'Você não tem permissão para visualizar este post.'], 403);
            }
        }

        $post->load([
            'user',
            'group:id,name',
            'event:id,name',
            'media',
            'feeling',
            'hashtags',
            'mentions:id,name,username,avatar_url',
            'comments.user',
            'comments.mentions:id,name,username,avatar_url',
            'comments.parent.user:id,name,username',
            'comments.likes' => fn ($q) => $q->where('user_id', $userId),
            'likes' => fn ($q) => $q->where('user_id', $userId),
            'poll.options',
            'poll.votes' => fn ($q) => $q->where('user_id', $userId),
            'repostedPost.user:id,name,username,avatar_url',
            'repostedPost.media',
        ])->loadCount(['likes', 'comments']);

        $post->is_liked = $post->likes->isNotEmpty();
        $this->formatPostPoll($post, $userId);
        if ($post->comments) {
            $post->comments->transform(function ($comment) {
                $comment->is_liked = $comment->likes ? $comment->likes->isNotEmpty() : false;
                unset($comment->likes);

                return $comment;
            });
        }

        return response()->json($post);
    }

    public function store(Request $request)
    {
        // Verificação defensiva: se a requisição tinha payload mas o PHP descartou $_POST e $_FILES (estouro de post_max_size)
        $contentLength = (int) ($request->server('CONTENT_LENGTH') ?? 0);
        if ($contentLength > 0 && empty($request->all()) && empty($request->allFiles())) {
            return response()->json([
                'message' => 'O tamanho total dos arquivos enviados ultrapassou o limite máximo aceito pelo servidor. Reduza o tamanho ou a quantidade das imagens.',
            ], 413);
        }

        $pollInput = $request->input('poll');
        if (is_string($pollInput)) {
            $decoded = json_decode($pollInput, true);
            if (is_array($decoded)) {
                $pollInput = $decoded;
                $request->merge(['poll' => $pollInput]);
            }
        }

        $request->validate([
            'content' => 'nullable|string|max:2000',
            'group_id' => 'nullable|exists:groups,id',
            'event_id' => 'nullable|exists:events,id',
            'repost_of_id' => 'nullable|exists:posts,id',
            'feeling_name' => 'nullable|string|max:15',
            'feeling_emoji' => 'nullable|string|max:32',
            'hashtags' => 'nullable|array',
            'hashtags.*' => 'string|max:50',
            'media' => 'nullable|array|max:5',
            'media.*' => 'file|mimes:jpg,jpeg,png,gif,webp,mp4,mov|max:20480',
            'poll' => 'nullable|array',
            'poll.question' => 'nullable|string|max:255',
            'poll.options' => 'required_with:poll|array|min:2|max:5',
            'poll.options.*' => 'required|string|max:100',
        ], [
            'content.max' => 'O texto da publicação pode ter no máximo 2000 caracteres.',
            'feeling_name.max' => 'O sentimento pode ter no máximo 15 caracteres.',
            'media.max' => 'Você pode anexar no máximo 5 arquivos de mídia.',
            'media.*.file' => 'O arquivo enviado é inválido.',
            'media.*.mimes' => 'Formato de mídia não suportado. Utilize imagens (JPG, PNG, GIF, WEBP) ou vídeos (MP4, MOV).',
            'media.*.max' => 'Cada arquivo de mídia pode ter no máximo 20MB.',
            'poll.options.min' => 'A votação precisa ter pelo menos 2 opções.',
            'poll.options.max' => 'A votação pode ter no máximo 5 opções.',
            'poll.options.*.required' => 'As opções da votação não podem estar vazias.',
            'poll.options.*.max' => 'Cada opção da votação pode ter no máximo 100 caracteres.',
        ]);

        $repostId = $request->input('repost_of_id');
        if ($repostId) {
            $originalPost = Post::find($repostId);
            if (! $originalPost) {
                return response()->json(['message' => 'A publicação original a ser repostada não foi encontrada.'], 404);
            }
            // Decisão: apenas o próprio autor pode repostar por enquanto
            if ($originalPost->user_id !== auth()->id() && ! auth()->user()->isAdmin()) {
                return response()->json(['message' => 'Você só pode repostar suas próprias atividades no momento.'], 403);
            }
        }

        $hasPoll = $request->filled('poll.options') && count((array) $request->input('poll.options')) >= 2;
        if (! $request->filled('content') && ! $request->hasFile('media') && ! $hasPoll && ! $repostId) {
            return response()->json(['message' => 'O post precisa ter texto, mídia, votação ou um repost.'], 422);
        }

        $groupId = $request->input('group_id');
        if ($groupId) {
            $isMember = auth()->user()->acceptedGroups()->where('groups.id', $groupId)->exists();
            if (! $isMember && ! auth()->user()->isAdmin()) {
                return response()->json(['message' => 'Você precisa ser membro do grupo para publicar nele.'], 403);
            }
        }

        $eventId = $request->input('event_id');
        if ($eventId) {
            $event = Event::find($eventId);
            $isInvitedOrOwner = $event && ($event->user_id === auth()->id() || $event->guests()->where('users.id', auth()->id())->exists());
            if (! $isInvitedOrOwner && ! auth()->user()->isAdmin()) {
                return response()->json(['message' => 'Você precisa ser convidado ou organizador do evento para publicar nele.'], 403);
            }
        }

        $post = Post::create([
            'user_id' => auth()->id(),
            'group_id' => $groupId,
            'event_id' => $eventId,
            'category' => 'feed',
            'repost_of_id' => $repostId,
            'content' => $request->input('content'),
        ]);

        // Poll
        if ($hasPoll) {
            $poll = $post->poll()->create([
                'question' => $request->filled('poll.question') ? trim($request->input('poll.question')) : null,
            ]);

            foreach ($request->input('poll.options') as $index => $optText) {
                $cleanText = trim((string) $optText);
                if ($cleanText !== '') {
                    $poll->options()->create([
                        'text' => mb_substr($cleanText, 0, 100),
                        'order' => $index,
                    ]);
                }
            }
        }

        // Feelings
        if ($request->filled('feeling_name')) {
            $post->feeling()->create([
                'name' => mb_substr(trim($request->input('feeling_name')), 0, 15),
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
                $path = ImageOptimizerService::storeOptimized($file, "posts/{$post->id}", $disk);

                $post->media()->create([
                    'path' => $path,
                    'type' => $type,
                    'collection_name' => 'post_media',
                ]);
            }
        }

        // Mentions
        MentionService::syncPostMentions($post, auth()->user());

        $post->load([
            'user',
            'group:id,name',
            'event:id,name',
            'media',
            'feeling',
            'hashtags',
            'mentions:id,name,username,avatar_url',
            'comments.user',
            'likes',
            'poll.options',
            'poll.votes' => fn ($q) => $q->where('user_id', auth()->id()),
            'repostedPost.user:id,name,username,avatar_url',
            'repostedPost.media',
        ]);
        $post->is_liked = false;
        $this->formatPostPoll($post, auth()->id());

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
        if ($post->user_id !== auth()->id() && ! auth()->user()->isAdmin()) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        $request->validate([
            'content' => 'nullable|string|max:2000',
            'feeling_name' => 'nullable|string|max:15',
            'feeling_emoji' => 'nullable|string|max:32',
            'hashtags' => 'nullable|array',
            'hashtags.*' => 'string|max:50',
        ]);

        if (! $request->filled('content') && ! $post->media()->exists() && ! $post->poll()->exists() && ! $post->repost_of_id) {
            return response()->json(['message' => 'O post precisa ter texto, mídia ou votação.'], 422);
        }

        $post->update([
            'content' => $request->input('content'),
        ]);

        // Feelings
        if ($request->filled('feeling_name')) {
            $name = mb_substr(trim($request->input('feeling_name')), 0, 15);
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

        $post->load([
            'user',
            'group:id,name',
            'event:id,name',
            'media',
            'feeling',
            'hashtags',
            'mentions:id,name,username,avatar_url',
            'comments.user',
            'likes',
            'poll.options',
            'poll.votes' => fn ($q) => $q->where('user_id', auth()->id()),
            'repostedPost.user:id,name,username,avatar_url',
            'repostedPost.media',
        ]);
        $post->is_liked = $post->likes()->where('user_id', auth()->id())->exists();
        $this->formatPostPoll($post, auth()->id());

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
        $canDelete = $post->user_id === auth()->id()
            || auth()->user()->isAdmin()
            || ($post->group_id && $post->group?->creator_id === auth()->id())
            || ($post->event_id && $post->event?->user_id === auth()->id());

        if (! $canDelete) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        $postId = $post->id;
        $groupId = $post->group_id;
        $eventId = $post->event_id;

        // Delete associated media files
        foreach ($post->media as $media) {
            $media->delete();
        }

        $post->delete();

        // Broadcast event safely
        try {
            broadcast(new PostDeleted($postId, $groupId, $eventId));
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json(['message' => 'Post excluído com sucesso.']);
    }

    protected function formatPostPoll(Post $post, int $userId): void
    {
        if (! $post->relationLoaded('poll') || ! $post->poll) {
            $post->unsetRelation('poll');
            $post->setAttribute('poll', null);

            return;
        }

        $poll = $post->poll;
        $userVote = $poll->relationLoaded('votes') ? $poll->votes->first() : null;
        $hasVoted = $userVote !== null;
        $userVotedOptionId = $userVote?->poll_option_id;
        $totalVotes = $poll->relationLoaded('options') ? (int) $poll->options->sum('votes_count') : 0;

        $isAuthor = (int) $post->user_id === (int) $userId;
        $isAdmin = auth()->user()?->isAdmin() ?? false;
        $canSeeResults = $hasVoted || $isAuthor || $isAdmin;

        $formattedOptions = $poll->options->map(function ($opt) use ($canSeeResults, $totalVotes) {
            $item = [
                'id' => $opt->id,
                'poll_id' => $opt->poll_id,
                'text' => $opt->text,
                'order' => $opt->order,
            ];

            if ($canSeeResults) {
                $votes = (int) $opt->votes_count;
                $item['votes_count'] = $votes;
                $item['percentage'] = $totalVotes > 0 ? round(($votes / $totalVotes) * 100, 1) : 0;
            } else {
                $item['votes_count'] = null;
                $item['percentage'] = null;
            }

            return $item;
        });

        $formattedPoll = [
            'id' => $poll->id,
            'post_id' => $poll->post_id,
            'question' => $poll->question,
            'has_voted' => $hasVoted,
            'can_see_results' => $canSeeResults,
            'user_voted_option_id' => $userVotedOptionId,
            'total_votes' => $canSeeResults ? $totalVotes : null,
            'options' => $formattedOptions,
        ];

        $post->unsetRelation('poll');
        $post->setAttribute('poll', $formattedPoll);
    }
}
