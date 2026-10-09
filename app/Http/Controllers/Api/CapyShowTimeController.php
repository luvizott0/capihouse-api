<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CapyEpisode;
use App\Models\CapyEpisodeComment;
use App\Models\CapyEpisodeCommentLike;
use App\Models\CapySeason;
use App\Models\CapySeries;
use App\Models\CapyUserEpisode;
use App\Models\CapyUserSeries;
use App\Models\User;
use App\Services\CapyShowTimeService;
use App\Services\TmdbService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CapyShowTimeController extends Controller
{
    public function __construct(
        protected CapyShowTimeService $service,
        protected TmdbService $tmdb
    ) {
    }

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
     * Detalhes de uma série específica, temporadas, status do usuário e médias
     */
    public function showSeries(int $tmdbId, Request $request): JsonResponse
    {
        $user = $request->user();
        $series = $this->service->syncSeriesFromTmdb($tmdbId);

        if (!$series) {
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

        if (!$series) {
            return response()->json(['message' => 'Série não encontrada'], 404);
        }

        $season = $this->service->syncSeasonEpisodes($series, $seasonNumber);

        if (!$season) {
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

        if (!$series) {
            return response()->json(['message' => 'Série não encontrada'], 404);
        }

        if ($validated['status'] === 'remove') {
            $this->service->removeSeriesStatus($user, $series);
            return response()->json(['message' => 'Série removida da lista', 'status' => null]);
        }

        $userSeries = $this->service->setSeriesStatus($user, $series, $validated['status']);

        return response()->json([
            'message' => 'Status atualizado com sucesso',
            'status' => $userSeries->status,
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
        ]);

        $user = $request->user();
        $episode = CapyEpisode::findOrFail($validated['episode_id']);

        if (array_key_exists('rating', $validated) && $validated['rating'] === null) {
            $progress = $this->service->removeEpisodeRating($user, $episode);
        } else {
            $progress = $this->service->rateOrWatchEpisode(
                $user,
                $episode,
                isset($validated['rating']) ? (float) $validated['rating'] : null,
                $validated['is_watched'] ?? true
            );
        }

        // Recalcular médias da temporada e série para o usuário
        $ratings = $this->service->getUserSeriesRatings($user->id, $episode->series_id);

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
            if (!$series) continue;

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

        $comment->load('user:id,name,username,avatar_url');

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

        $data = $activities->map(function ($act) {
            return [
                'id' => $act->id,
                'user' => [
                    'id' => $act->user->id,
                    'name' => $act->user->name,
                    'username' => $act->user->username,
                    'avatar_url' => $act->user->avatar_url,
                ],
                'series' => [
                    'id' => $act->series->id,
                    'tmdb_id' => $act->series->tmdb_id,
                    'name' => $act->series->name,
                    'poster_url' => $act->series->poster_url,
                ],
                'episode' => [
                    'id' => $act->episode->id,
                    'season_number' => $act->episode->season_number,
                    'episode_number' => $act->episode->episode_number,
                    'name' => $act->episode->name,
                ],
                'rating' => $act->rating ? (float) $act->rating : null,
                'watched_at' => $act->watched_at?->diffForHumans(),
            ];
        });

        return response()->json($data);
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

        $userSeries = CapyUserSeries::with('series')
            ->where('user_id', $userId)
            ->orderByDesc('updated_at')
            ->get();

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
}
