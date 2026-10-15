<?php

namespace App\Http\Controllers\Api;

use App\Events\PostCreated;
use App\Http\Controllers\Controller;
use App\Models\CapyEpisode;
use App\Models\CapyEpisodeComment;
use App\Models\CapyEpisodeCommentLike;
use App\Models\CapySeries;
use App\Models\CapyUserEpisode;
use App\Models\CapyUserSeries;
use App\Models\CapyUserWatchProgress;
use App\Models\Post;
use App\Models\User;
use App\Services\CapyShowTimeService;
use App\Services\NotificationDispatcherService;
use App\Services\TmdbService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CapyShowTimeController extends Controller
{
    public function __construct(
        protected CapyShowTimeService $service,
        protected TmdbService $tmdb
    ) {}

    /**
     * Busca de séries pelo TMDB
     */
    public function search(Request $request): JsonResponse
    {
        $query = $request->input('q', '');
        $page = (int) $request->input('page', 1);

        $results = $this->tmdb->search($query, $page);

        return response()->json($results);
    }

    /**
     * Séries em alta na semana
     */
    public function trending(): JsonResponse
    {
        $results = $this->tmdb->getTrending();

        return response()->json($results);
    }

    /**
     * Séries populares
     */
    public function popular(): JsonResponse
    {
        $results = $this->tmdb->getPopular();

        return response()->json($results);
    }

    /**
     * Lista de gêneros disponíveis no TMDB
     */
    public function genres(): JsonResponse
    {
        return response()->json($this->tmdb->getGenres());
    }

    /**
     * Descobrir séries com filtros (gênero, nota TMDB, plataforma, ano, idioma, ordenação)
     */
    public function discover(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->input('page', 1));
        $filters = $request->only([
            'sort_by',
            'with_genres',
            'min_rating',
            'min_votes',
            'watch_provider',
            'watch_region',
            'year_from',
            'year_to',
            'language',
            'status',
        ]);

        $results = $this->tmdb->discover($filters, $page);

        return response()->json($results);
    }

    /**
     * Detalhes de uma série específica, temporadas, status do usuário e médias
     */
    public function showSeries(int $tmdbId, Request $request): JsonResponse
    {
        $user = $request->user();
        $series = $this->service->syncSeriesFromTmdb($tmdbId);

        if (! $series) {
            return response()->json(['message' => 'Série não encontrada'], 404);
        }

        $seasons = $series->seasons()->orderBy('season_number')->get();
        $userStatus = CapyUserSeries::where('user_id', $user->id)
            ->where('series_id', $series->id)
            ->value('status');

        $userRatings = $this->service->getUserSeriesRatings($user->id, $series->id);
        $communityRatings = $this->service->getCommunitySeriesRatings($series->id);

        // Amigos da Capihouse que estão assistindo ou assistiram
        $housemates = CapyUserSeries::with('user:id,name,username,avatar_url')
            ->where('series_id', $series->id)
            ->get()
            ->map(fn ($us) => [
                'user' => $us->user,
                'status' => $us->status,
            ]);

        return response()->json([
            'series' => $series,
            'seasons' => $seasons,
            'user_status' => $userStatus,
            'user_ratings' => $userRatings,
            'community_ratings' => $communityRatings,
            'housemates' => $housemates,
        ]);
    }

    /**
     * Detalhes de uma temporada com episódios, progresso e notas do usuário
     */
    public function showSeason(int $tmdbId, int $seasonNumber, Request $request): JsonResponse
    {
        $user = $request->user();
        $series = $this->service->syncSeriesFromTmdb($tmdbId);

        if (! $series) {
            return response()->json(['message' => 'Série não encontrada'], 404);
        }

        $season = $this->service->syncSeasonEpisodes($series, $seasonNumber);

        if (! $season) {
            return response()->json(['message' => 'Temporada não encontrada'], 404);
        }

        $episodes = $season->episodes()
            ->withCount('comments')
            ->orderBy('episode_number')
            ->get();

        $episodeIds = $episodes->pluck('id');

        // Progresso do usuário atual
        $userProgress = CapyUserEpisode::where('user_id', $user->id)
            ->whereIn('episode_id', $episodeIds)
            ->get()
            ->keyBy('episode_id');

        // Médias comunitárias por episódio
        $communityEpRatings = CapyUserEpisode::whereIn('episode_id', $episodeIds)
            ->whereNotNull('rating')
            ->groupBy('episode_id')
            ->select('episode_id', DB::raw('ROUND(AVG(rating), 1) as avg_rating'), DB::raw('COUNT(id) as total_ratings'))
            ->get()
            ->keyBy('episode_id');

        $episodesData = $episodes->map(function ($ep) use ($userProgress, $communityEpRatings) {
            $up = $userProgress->get($ep->id);
            $comm = $communityEpRatings->get($ep->id);

            return [
                'id' => $ep->id,
                'series_id' => $ep->series_id,
                'season_id' => $ep->season_id,
                'episode_number' => $ep->episode_number,
                'season_number' => $ep->season_number,
                'name' => $ep->name,
                'overview' => $ep->overview,
                'still_url' => $ep->still_url,
                'air_date' => $ep->air_date?->format('Y-m-d'),
                'runtime' => $ep->runtime,
                'comments_count' => $ep->comments_count,
                'is_watched' => $up ? (bool) $up->is_watched : false,
                'watched_at' => $up?->watched_at,
                'user_rating' => $up?->rating ? (float) $up->rating : null,
                'community_rating' => $comm ? (float) $comm->avg_rating : null,
                'community_votes' => $comm ? (int) $comm->total_ratings : 0,
            ];
        });

        // Médias da temporada
        $userRatings = $this->service->getUserSeriesRatings($user->id, $series->id);
        $seasonAverage = $userRatings['seasons'][$seasonNumber]['average'] ?? null;

        return response()->json([
            'season' => $season,
            'series' => $series,
            'season_average' => $seasonAverage,
            'episodes' => $episodesData,
        ]);
    }

    /**
     * Define ou atualiza o status de uma série na lista do usuário
     */
    public function setStatus(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tmdb_id' => 'required|integer',
            'status' => 'required|string|in:plan_to_watch,watching,completed,on_hold,dropped,remove',
        ]);

        $user = $request->user();
        $series = $this->service->syncSeriesFromTmdb($validated['tmdb_id']);

        if (! $series) {
            return response()->json(['message' => 'Série não encontrada'], 404);
        }

        if ($validated['status'] === 'remove') {
            $this->service->removeSeriesStatus($user, $series);
            $this->removeSeriesCompletedPost($user, $series);
            $this->removeSeriesDroppedPost($user, $series);

            return response()->json(['message' => 'Série removida da lista', 'status' => null]);
        }

        $userSeries = $this->service->setSeriesStatus($user, $series, $validated['status']);

        // Se marcou como concluída ou abandonada, sincroniza no Feed de Entretenimento da Capihouse
        if ($validated['status'] === 'completed') {
            $this->syncSeriesCompletedPost($user, $series);
            $this->removeSeriesDroppedPost($user, $series);
        } elseif ($validated['status'] === 'dropped') {
            $this->syncSeriesDroppedPost($user, $series);
            $this->removeSeriesCompletedPost($user, $series);
        } else {
            $this->removeSeriesCompletedPost($user, $series);
            $this->removeSeriesDroppedPost($user, $series);
        }

        return response()->json([
            'message' => 'Status atualizado com sucesso',
            'status' => $userSeries->status,
            'marked_episodes_watched' => $validated['status'] === 'completed',
        ]);
    }

    /**
     * Marcar/desmarcar episódio como assistido ou dar nota (1 a 10)
     */
    public function rateEpisode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'episode_id' => 'required|exists:capy_episodes,id',
            'rating' => 'nullable|numeric|min:1|max:10',
            'is_watched' => 'nullable|boolean',
            'remove_rating' => 'nullable|boolean',
        ]);

        $user = $request->user();
        $episode = CapyEpisode::findOrFail($validated['episode_id']);

        $rating = isset($validated['rating']) ? (float) $validated['rating'] : null;
        $isWatched = $validated['is_watched'] ?? true;

        if (! empty($validated['remove_rating'])) {
            $progress = $this->service->rateOrWatchEpisode($user, $episode, null, $isWatched);
            $progress->rating = null;
            $progress->save();
        } else {
            $progress = $this->service->rateOrWatchEpisode(
                $user,
                $episode,
                $rating,
                $isWatched
            );
        }

        // Recalcular médias da temporada e série para o usuário
        $ratings = $this->service->getUserSeriesRatings($user->id, $episode->series_id);

        // Alimentar o Feed de Entretenimento da Capihouse apenas para avaliações com nota válida (> 0)
        if ($isWatched && $rating !== null && $rating > 0 && empty($validated['remove_rating'])) {
            try {
                $series = $episode->series;
                if ($series) {
                    $epCode = sprintf('S%02dE%02d', $episode->season_number, $episode->episode_number);
                    $content = "Assistiu e avaliou o episódio {$epCode} ({$episode->name}) de {$series->name} com nota {$rating}/10.";

                    $post = Post::updateOrCreate(
                        [
                            'user_id' => $user->id,
                            'external_source' => 'capyshowtime',
                            'external_id' => "capyshowtime:ep:{$episode->id}:user:{$user->id}",
                        ],
                        [
                            'category' => 'entertainment',
                            'entertainment_type' => 'series',
                            'watched_at' => now(),
                            'content' => $content,
                            'metadata' => [
                                'type' => 'rating',
                                'series_id' => $series->id,
                                'tmdb_id' => $series->tmdb_id,
                                'series_name' => $series->name,
                                'season_number' => $episode->season_number,
                                'episode_number' => $episode->episode_number,
                                'episode_name' => $episode->name,
                                'poster_url' => $series->poster_url,
                                'rating' => $rating,
                            ],
                        ]
                    );

                    $post->load('user:id,name,username,avatar_url');
                    broadcast(new PostCreated($post));
                }
            } catch (\Throwable $e) {
                Log::warning('Erro ao sincronizar avaliação com Capihouse: '.$e->getMessage());
            }
        } else {
            // Se a nota foi removida, ou o episódio apenas marcado como visto sem nota, remove eventual post para manter o feed limpo
            Post::where('external_source', 'capyshowtime')
                ->where('external_id', "capyshowtime:ep:{$episode->id}:user:{$user->id}")
                ->delete();
        }

        // Sincroniza se a série completou todas as temporadas com esse episódio
        $series = $episode->series;
        if ($series) {
            $userSeries = CapyUserSeries::where('user_id', $user->id)
                ->where('series_id', $series->id)
                ->first();

            if ($userSeries?->status === 'completed') {
                $this->syncSeriesCompletedPost($user, $series);
            } else {
                $this->removeSeriesCompletedPost($user, $series);
            }
        }

        return response()->json([
            'message' => 'Progresso atualizado',
            'progress' => [
                'episode_id' => $episode->id,
                'is_watched' => (bool) $progress?->is_watched,
                'rating' => $progress?->rating ? (float) $progress->rating : null,
            ],
            'season_average' => $ratings['seasons'][$episode->season_number]['average'] ?? null,
            'series_average' => $ratings['series_average'],
        ]);
    }

    /**
     * Marcar todos os episódios de uma temporada como assistidos
     */
    public function markSeasonWatched(int $tmdbId, int $seasonNumber, Request $request): JsonResponse
    {
        $user = $request->user();
        $series = $this->service->syncSeriesFromTmdb($tmdbId);

        if (! $series) {
            return response()->json(['message' => 'Série não encontrada'], 404);
        }

        $result = $this->service->markSeasonEpisodesWatched($user, $series, $seasonNumber);

        if (! $result) {
            return response()->json(['message' => 'Temporada não encontrada'], 404);
        }

        if (($result['user_status'] ?? null) === 'completed') {
            $this->syncSeriesCompletedPost($user, $series);
        } else {
            $this->removeSeriesCompletedPost($user, $series);
        }

        return response()->json(array_merge([
            'message' => 'Temporada marcada como assistida',
        ], $result));
    }

    /**
     * Marcar episódio e todos os episódios anteriores como assistidos
     */
    public function markPreviousEpisodes(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'episode_id' => 'required|exists:capy_episodes,id',
        ]);

        $user = $request->user();
        $episode = CapyEpisode::with('series')->findOrFail($validated['episode_id']);

        $result = $this->service->markPreviousEpisodesWatched($user, $episode);

        if (($result['user_status'] ?? null) === 'completed' && $episode->series) {
            $this->syncSeriesCompletedPost($user, $episode->series);
        } elseif ($episode->series) {
            $this->removeSeriesCompletedPost($user, $episode->series);
        }

        return response()->json(array_merge([
            'message' => 'Episódios anteriores marcados como assistidos',
        ], $result));
    }

    /**
     * Lista "Minhas Séries" (Watchlist/Assistindo/Completas)
     */
    public function myList(Request $request): JsonResponse
    {
        $user = $request->user();
        $status = $request->input('status'); // opcional

        $query = CapyUserSeries::with('series')
            ->where('user_id', $user->id);

        if ($status) {
            $query->where('status', $status);
        }

        $items = $query->orderByDesc('updated_at')->get();

        return response()->json($items);
    }

    /**
     * Próximos episódios a assistir (estilo Up Next do TV Show Time)
     */
    public function upNext(Request $request): JsonResponse
    {
        $user = $request->user();

        // Pega as séries que o usuário está assistindo (ou quero assistir)
        $watchingSeries = CapyUserSeries::where('user_id', $user->id)
            ->whereIn('status', ['watching', 'plan_to_watch'])
            ->pluck('series_id');

        $upNext = [];

        foreach ($watchingSeries as $seriesId) {
            $series = CapySeries::find($seriesId);
            if (! $series) {
                continue;
            }

            // Busca os IDs de episódios que o usuário já assistiu
            $watchedEpisodeIds = CapyUserEpisode::where('user_id', $user->id)
                ->where('series_id', $seriesId)
                ->where('is_watched', true)
                ->pluck('episode_id');

            // Primeiro episódio não assistido ordenado por temporada e episódio
            $nextEpisode = CapyEpisode::where('series_id', $seriesId)
                ->where('season_number', '>', 0)
                ->whereNotIn('id', $watchedEpisodeIds)
                ->orderBy('season_number')
                ->orderBy('episode_number')
                ->first();

            // Total de episódios e quantos foram assistidos
            $totalEpisodes = CapyEpisode::where('series_id', $seriesId)->where('season_number', '>', 0)->count();
            $watchedCount = $watchedEpisodeIds->count();

            if ($nextEpisode) {
                $upNext[] = [
                    'series' => $series,
                    'next_episode' => [
                        'id' => $nextEpisode->id,
                        'season_number' => $nextEpisode->season_number,
                        'episode_number' => $nextEpisode->episode_number,
                        'name' => $nextEpisode->name,
                        'still_url' => $nextEpisode->still_url,
                        'air_date' => $nextEpisode->air_date?->format('Y-m-d'),
                    ],
                    'progress' => [
                        'watched' => $watchedCount,
                        'total' => $totalEpisodes,
                        'percentage' => $totalEpisodes > 0 ? round(($watchedCount / $totalEpisodes) * 100) : 0,
                    ],
                ];
            }
        }

        return response()->json($upNext);
    }

    /**
     * Comentários de um episódio
     */
    public function getEpisodeComments(int $episodeId, Request $request): JsonResponse
    {
        $user = $request->user();

        $comments = CapyEpisodeComment::with([
            'user:id,name,username,avatar_url',
            'likes' => fn ($q) => $q->select('id', 'comment_id', 'user_id'),
        ])
            ->where('episode_id', $episodeId)
            ->latest()
            ->get()
            ->map(function ($comment) use ($user) {
                return [
                    'id' => $comment->id,
                    'user' => $comment->user,
                    'content' => $comment->content,
                    'is_spoiler' => (bool) $comment->is_spoiler,
                    'created_at' => $comment->created_at->diffForHumans(),
                    'likes_count' => $comment->likes->count(),
                    'liked_by_me' => $comment->likes->contains('user_id', $user->id),
                ];
            });

        return response()->json($comments);
    }

    /**
     * Adicionar comentário ao episódio
     */
    public function addEpisodeComment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'episode_id' => 'required|exists:capy_episodes,id',
            'content' => 'required|string|min:1|max:3000',
            'is_spoiler' => 'nullable|boolean',
        ]);

        $comment = CapyEpisodeComment::create([
            'episode_id' => $validated['episode_id'],
            'user_id' => $request->user()->id,
            'content' => $validated['content'],
            'is_spoiler' => $validated['is_spoiler'] ?? false,
        ]);

        $comment->load('user:id,name,username,avatar_url', 'episode.series');

        // Notificar amigos da Capihouse que também comentaram no mesmo episódio
        try {
            $episode = $comment->episode;
            $series = $episode?->series;
            if ($episode && $series) {
                $epCode = sprintf('S%02dE%02d', $episode->season_number, $episode->episode_number);
                $otherCommenters = CapyEpisodeComment::where('episode_id', $episode->id)
                    ->where('user_id', '!=', $request->user()->id)
                    ->pluck('user_id')
                    ->unique();

                foreach ($otherCommenters as $recipientId) {
                    NotificationDispatcherService::send(
                        recipient: (int) $recipientId,
                        type: 'capyshowtime_comment',
                        title: 'CapyShowTime 📺',
                        content: "{$request->user()->name} comentou no episódio {$epCode} de {$series->name}.",
                        data: [
                            'episode_id' => $episode->id,
                            'series_id' => $series->id,
                            'tmdb_id' => $series->tmdb_id,
                            'comment_id' => $comment->id,
                        ],
                        url: "/series/{$series->tmdb_id}"
                    );
                }

                // Alimentar o Feed de Entretenimento da Capihouse com o novo comentário
                $commentPreview = $comment->is_spoiler ? '[Contém spoiler]' : (mb_strlen($comment->content) > 120 ? mb_substr($comment->content, 0, 117).'...' : $comment->content);
                $post = Post::create([
                    'user_id' => $request->user()->id,
                    'external_source' => 'capyshowtime',
                    'external_id' => "capyshowtime:comment:{$comment->id}",
                    'category' => 'entertainment',
                    'entertainment_type' => 'series',
                    'watched_at' => now(),
                    'content' => $comment->is_spoiler ? '[Contém spoiler]' : $comment->content,
                    'metadata' => [
                        'type' => 'comment',
                        'series_id' => $series->id,
                        'tmdb_id' => $series->tmdb_id,
                        'series_name' => $series->name,
                        'season_number' => $episode->season_number,
                        'episode_number' => $episode->episode_number,
                        'episode_name' => $episode->name,
                        'poster_url' => $series->poster_url,
                        'comment_id' => $comment->id,
                        'is_spoiler' => (bool) $comment->is_spoiler,
                    ],
                ]);
                $post->load('user:id,name,username,avatar_url');
                broadcast(new PostCreated($post));
            }
        } catch (\Throwable $e) {
            Log::warning('Erro ao enviar notificação ou criar post de comentário do CapyShowTime: '.$e->getMessage());
        }

        return response()->json([
            'id' => $comment->id,
            'user' => $comment->user,
            'content' => $comment->content,
            'is_spoiler' => (bool) $comment->is_spoiler,
            'created_at' => 'agora',
            'likes_count' => 0,
            'liked_by_me' => false,
        ], 201);
    }

    /**
     * Sincronizar episódio como assistido via TMDB ID (usado pela CapyFlix)
     */
    public function syncWatchedByTmdb(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tmdb_id' => 'required|integer',
            'season_number' => 'required|integer',
            'episode_number' => 'required|integer',
            'is_watched' => 'nullable|boolean',
        ]);

        $user = $request->user();
        $series = $this->service->syncSeriesFromTmdb($validated['tmdb_id']);
        if (! $series) {
            return response()->json(['message' => 'Série não encontrada no TMDB'], 404);
        }

        $episode = CapyEpisode::where('series_id', $series->id)
            ->where('season_number', $validated['season_number'])
            ->where('episode_number', $validated['episode_number'])
            ->first();

        if (! $episode) {
            $this->service->syncSeasonFromTmdb($series, $validated['season_number']);
            $episode = CapyEpisode::where('series_id', $series->id)
                ->where('season_number', $validated['season_number'])
                ->where('episode_number', $validated['episode_number'])
                ->first();
        }

        if ($episode) {
            CapyUserEpisode::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'episode_id' => $episode->id,
                ],
                [
                    'series_id' => $series->id,
                    'is_watched' => $validated['is_watched'] ?? true,
                    'watched_at' => now(),
                ]
            );

            $userSeries = CapyUserSeries::firstOrCreate(
                ['user_id' => $user->id, 'series_id' => $series->id],
                ['status' => 'watching']
            );
            if ($userSeries->status === 'plan_to_watch') {
                $userSeries->update(['status' => 'watching']);
            }

            return response()->json([
                'success' => true,
                'episode_id' => $episode->id,
                'is_watched' => true,
                'series_name' => $series->name,
            ]);
        }

        return response()->json(['message' => 'Episódio não localizado'], 404);
    }

    /**
     * Retorna todo o histórico de progresso de visualização do usuário (para CapyFlix)
     */
    public function getWatchProgress(Request $request): JsonResponse
    {
        $user = $request->user();
        $progress = CapyUserWatchProgress::where('user_id', $user->id)
            ->orderBy('last_watched_at', 'desc')
            ->get();

        return response()->json($progress);
    }

    /**
     * Salva ou atualiza a posição e progresso de reprodução do vídeo/episódio no banco de dados
     */
    public function saveWatchProgress(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'media_id' => 'required|string',
            'season_index' => 'required|integer',
            'episode_index' => 'required|integer',
            'time' => 'required|integer',
            'duration' => 'required|integer',
            'completed' => 'nullable|boolean',
        ]);

        $user = $request->user();

        $entry = CapyUserWatchProgress::updateOrCreate(
            [
                'user_id' => $user->id,
                'media_id' => $validated['media_id'],
                'season_index' => $validated['season_index'],
                'episode_index' => $validated['episode_index'],
            ],
            [
                'time' => $validated['time'],
                'duration' => $validated['duration'],
                'completed' => $validated['completed'] ?? false,
                'last_watched_at' => now(),
            ]
        );

        return response()->json([
            'success' => true,
            'progress' => $entry,
        ]);
    }

    /**
     * Atualizar comentário de episódio
     */
    public function updateComment(int $commentId, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'content' => 'required|string|min:1|max:3000',
            'is_spoiler' => 'nullable|boolean',
        ]);

        $comment = CapyEpisodeComment::with('user:id,name,username,avatar_url')->findOrFail($commentId);

        if ($comment->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Não autorizado a editar este comentário'], 403);
        }

        $comment->update([
            'content' => $validated['content'],
            'is_spoiler' => $validated['is_spoiler'] ?? $comment->is_spoiler,
        ]);

        return response()->json([
            'id' => $comment->id,
            'user' => $comment->user,
            'content' => $comment->content,
            'is_spoiler' => (bool) $comment->is_spoiler,
            'updated_at' => 'agora',
        ]);
    }

    /**
     * Excluir comentário de episódio
     */
    public function deleteComment(int $commentId, Request $request): JsonResponse
    {
        $comment = CapyEpisodeComment::findOrFail($commentId);

        if ($comment->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Não autorizado a excluir este comentário'], 403);
        }

        $comment->likes()->delete();
        $comment->delete();

        return response()->json(['message' => 'Comentário removido com sucesso']);
    }

    /**
     * Curtir ou descurtir comentário
     */
    public function toggleCommentLike(int $commentId, Request $request): JsonResponse
    {
        $user = $request->user();
        $like = CapyEpisodeCommentLike::where('comment_id', $commentId)
            ->where('user_id', $user->id)
            ->first();

        if ($like) {
            $like->delete();
            $liked = false;
        } else {
            CapyEpisodeCommentLike::create([
                'comment_id' => $commentId,
                'user_id' => $user->id,
            ]);
            $liked = true;

            // Notificar o autor do comentário na Capihouse
            try {
                $comment = CapyEpisodeComment::with('user', 'episode.series')->find($commentId);
                if ($comment && $comment->user && $comment->user->id !== $user->id) {
                    $episode = $comment->episode;
                    $series = $episode?->series;
                    $seriesName = $series ? $series->name : 'uma série';
                    $epCode = $episode ? sprintf('S%02dE%02d', $episode->season_number, $episode->episode_number) : '';

                    NotificationDispatcherService::send(
                        recipient: $comment->user,
                        type: 'capyshowtime_like',
                        title: 'CapyShowTime 📺',
                        content: "{$user->name} curtiu seu comentário no episódio {$epCode} de {$seriesName}!",
                        data: [
                            'comment_id' => $comment->id,
                            'episode_id' => $episode?->id,
                            'series_id' => $series?->id,
                            'tmdb_id' => $series?->tmdb_id,
                        ],
                        url: $series ? "/series/{$series->tmdb_id}" : null
                    );
                }
            } catch (\Throwable $e) {
                Log::warning('Erro ao enviar notificação de curtida do CapyShowTime: '.$e->getMessage());
            }
        }

        $likesCount = CapyEpisodeCommentLike::where('comment_id', $commentId)->count();

        return response()->json([
            'liked' => $liked,
            'likes_count' => $likesCount,
        ]);
    }

    /**
     * Feed de atividade comunitária da Capihouse
     */
    public function communityActivity(): JsonResponse
    {
        $activities = $this->service->getCommunityActivity(30);

        return response()->json($activities);
    }

    /**
     * O que os amigos estão assistindo atualmente na CapiHouse
     */
    public function friendsWatching(Request $request): JsonResponse
    {
        $limit = min(20, max(1, (int) $request->input('limit', 12)));

        $recentEpisodes = CapyUserEpisode::with([
            'user:id,name,username,avatar_url',
            'series:id,tmdb_id,name,poster_path,backdrop_path,vote_average',
            'episode:id,season_number,episode_number,name',
        ])
            ->where('is_watched', true)
            ->whereHas('user')
            ->whereHas('series')
            ->orderByDesc('watched_at')
            ->limit(50)
            ->get();

        $grouped = [];
        foreach ($recentEpisodes as $ue) {
            $seriesId = $ue->series_id;
            if (! isset($grouped[$seriesId])) {
                $time = $ue->watched_at ?? $ue->updated_at;
                $grouped[$seriesId] = [
                    'series_id' => $ue->series->id,
                    'tmdb_id' => $ue->series->tmdb_id,
                    'title' => $ue->series->name,
                    'poster_url' => $ue->series->poster_url,
                    'backdrop_url' => $ue->series->backdrop_url,
                    'vote_average' => $ue->series->vote_average,
                    'latest_episode' => $ue->episode ? [
                        'season' => $ue->episode->season_number,
                        'episode' => $ue->episode->episode_number,
                        'name' => $ue->episode->name,
                    ] : null,
                    'last_watched_at' => $time ? $time->diffForHumans() : 'recentemente',
                    'friends' => [],
                ];
            }

            $friendExists = collect($grouped[$seriesId]['friends'])->contains('id', $ue->user->id);
            if (! $friendExists) {
                $grouped[$seriesId]['friends'][] = [
                    'id' => $ue->user->id,
                    'name' => $ue->user->name,
                    'username' => $ue->user->username,
                    'avatar_url' => $ue->user->avatar_url,
                ];
            }
        }

        return response()->json(array_slice(array_values($grouped), 0, $limit));
    }

    /**
     * Séries preferidas e melhores avaliadas pelos amigos da CapiHouse
     */
    public function friendsTopRated(Request $request): JsonResponse
    {
        $limit = min(20, max(1, (int) $request->input('limit', 12)));

        $series = CapySeries::whereHas('userEpisodes', function ($q) {
            $q->whereNotNull('rating')->where('rating', '>', 0);
        })
            ->withAvg(['userEpisodes' => function ($q) {
                $q->whereNotNull('rating')->where('rating', '>', 0);
            }], 'rating')
            ->withCount(['userEpisodes' => function ($q) {
                $q->whereNotNull('rating')->where('rating', '>', 0);
            }])
            ->orderByDesc('user_episodes_avg_rating')
            ->orderByDesc('user_episodes_count')
            ->limit($limit)
            ->get()
            ->map(function ($s) {
                $topReviewers = CapyUserEpisode::with('user:id,name,username,avatar_url')
                    ->where('series_id', $s->id)
                    ->whereNotNull('rating')
                    ->where('rating', '>=', 6)
                    ->orderByDesc('rating')
                    ->limit(3)
                    ->get()
                    ->pluck('user')
                    ->filter()
                    ->unique('id')
                    ->values();

                return [
                    'series_id' => $s->id,
                    'tmdb_id' => $s->tmdb_id,
                    'title' => $s->name,
                    'poster_url' => $s->poster_url,
                    'backdrop_url' => $s->backdrop_url,
                    'genres' => $s->genres,
                    'friends_rating' => round($s->user_episodes_avg_rating, 1),
                    'ratings_count' => $s->user_episodes_count,
                    'friends' => $topReviewers,
                ];
            });

        return response()->json($series);
    }

    /**
     * Perfil de um usuário no CapyShowTime (estatísticas, notas e séries)
     */
    public function userProfile(int $userId): JsonResponse
    {
        $user = User::select('id', 'name', 'username', 'avatar_url', 'banner_url')->findOrFail($userId);

        $totalEpisodesWatched = CapyUserEpisode::where('user_id', $userId)
            ->where('is_watched', true)
            ->count();

        $totalRatedEpisodes = CapyUserEpisode::where('user_id', $userId)
            ->whereNotNull('rating')
            ->count();

        // Estimativa de tempo (média de 45 min por episódio)
        $totalMinutes = CapyUserEpisode::where('user_id', $userId)
            ->where('is_watched', true)
            ->join('capy_episodes', 'capy_user_episodes.episode_id', '=', 'capy_episodes.id')
            ->sum(DB::raw('COALESCE(capy_episodes.runtime, 45)'));

        $days = floor($totalMinutes / (60 * 24));
        $hours = floor(($totalMinutes % (60 * 24)) / 60);

        $seriesCounts = CapyUserSeries::where('user_id', $userId)
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status');

        $seriesRatings = CapyUserEpisode::where('user_id', $userId)
            ->whereNotNull('rating')
            ->groupBy('series_id')
            ->select('series_id', DB::raw('ROUND(AVG(rating), 1) as avg_rating'))
            ->pluck('avg_rating', 'series_id');

        $watchedCounts = CapyUserEpisode::where('user_id', $userId)
            ->where('is_watched', true)
            ->groupBy('series_id')
            ->select('series_id', DB::raw('COUNT(*) as watched_count'))
            ->pluck('watched_count', 'series_id');

        $userSeries = CapyUserSeries::with('series')
            ->where('user_id', $userId)
            ->orderByDesc('updated_at')
            ->get()
            ->map(function ($us) use ($seriesRatings, $watchedCounts) {
                $avgRating = $seriesRatings->get($us->series_id);
                $watchedCount = $watchedCounts->get($us->series_id, 0);

                return [
                    'id' => $us->id,
                    'user_id' => $us->user_id,
                    'series_id' => $us->series_id,
                    'status' => $us->status,
                    'updated_at' => $us->updated_at,
                    'user_rating' => $avgRating !== null ? (float) $avgRating : null,
                    'watched_episodes_count' => (int) $watchedCount,
                    'series' => $us->series,
                ];
            });

        return response()->json([
            'user' => $user,
            'stats' => [
                'episodes_watched' => $totalEpisodesWatched,
                'episodes_rated' => $totalRatedEpisodes,
                'time_spent' => [
                    'days' => $days,
                    'hours' => $hours,
                    'total_minutes' => $totalMinutes,
                ],
                'series_counts' => $seriesCounts,
            ],
            'series_list' => $userSeries,
        ]);
    }

    /**
     * Lista de moradores/amigos da Capihouse para acesso rápido aos perfis
     */
    public function users(): JsonResponse
    {
        $users = User::select('id', 'name', 'username', 'avatar_url')
            ->where('status', 'approved')
            ->orderBy('name')
            ->get();

        return response()->json($users);
    }

    /**
     * Sincroniza post de série completada no feed da Capihouse
     */
    private function syncSeriesCompletedPost(User $user, CapySeries $series): void
    {
        try {
            $post = Post::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'external_source' => 'capyshowtime',
                    'external_id' => "capyshowtime:series_completed:{$series->id}:user:{$user->id}",
                ],
                [
                    'category' => 'entertainment',
                    'entertainment_type' => 'series',
                    'watched_at' => now(),
                    'content' => "Concluiu a série {$series->name}! 🏆",
                    'metadata' => [
                        'type' => 'completed',
                        'series_id' => $series->id,
                        'tmdb_id' => $series->tmdb_id,
                        'series_name' => $series->name,
                        'poster_url' => $series->poster_url,
                        'number_of_seasons' => $series->number_of_seasons,
                    ],
                ]
            );

            $post->load('user:id,name,username,avatar_url');
            broadcast(new PostCreated($post));
        } catch (\Throwable $e) {
            Log::warning('Erro ao criar post de conclusão de série no CapyShowTime: '.$e->getMessage());
        }
    }

    /**
     * Remove eventual post de série completada quando o status deixa de ser completed
     */
    private function removeSeriesCompletedPost(User $user, CapySeries $series): void
    {
        Post::where('external_source', 'capyshowtime')
            ->where('external_id', "capyshowtime:series_completed:{$series->id}:user:{$user->id}")
            ->delete();
    }

    /**
     * Sincroniza post de série abandonada no feed da Capihouse
     */
    private function syncSeriesDroppedPost(User $user, CapySeries $series): void
    {
        try {
            $post = Post::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'external_source' => 'capyshowtime',
                    'external_id' => "capyshowtime:series_dropped:{$series->id}:user:{$user->id}",
                ],
                [
                    'category' => 'entertainment',
                    'entertainment_type' => 'series',
                    'watched_at' => now(),
                    'content' => "Abandonou a série {$series->name}. 🛑",
                    'metadata' => [
                        'type' => 'dropped',
                        'series_id' => $series->id,
                        'tmdb_id' => $series->tmdb_id,
                        'series_name' => $series->name,
                        'poster_url' => $series->poster_url,
                        'number_of_seasons' => $series->number_of_seasons,
                    ],
                ]
            );

            $post->load('user:id,name,username,avatar_url');
            broadcast(new PostCreated($post));
        } catch (\Throwable $e) {
            Log::warning('Erro ao criar post de abandono de série no CapyShowTime: '.$e->getMessage());
        }
    }

    /**
     * Remove eventual post de série abandonada quando o status deixa de ser dropped
     */
    private function removeSeriesDroppedPost(User $user, CapySeries $series): void
    {
        Post::where('external_source', 'capyshowtime')
            ->where('external_id', "capyshowtime:series_dropped:{$series->id}:user:{$user->id}")
            ->delete();
    }
}
