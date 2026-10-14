<?php

namespace App\Services;

use App\Models\CapyEpisode;
use App\Models\CapyEpisodeComment;
use App\Models\CapySeason;
use App\Models\CapySeries;
use App\Models\CapyUserEpisode;
use App\Models\CapyUserSeries;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CapyShowTimeService
{
    public function __construct(protected TmdbService $tmdb) {}

    /**
     * Sincroniza e garante que a série e suas temporadas existam no banco local
     */
    public function syncSeriesFromTmdb(int $tmdbId): ?CapySeries
    {
        $series = CapySeries::where('tmdb_id', $tmdbId)->first();

        // Se já existe e foi sincronizada nas últimas 24 horas, retorna
        if ($series && $series->synced_at && $series->synced_at->gt(now()->subDay())) {
            return $series;
        }

        $data = $this->tmdb->getSeriesDetails($tmdbId);
        if (! $data) {
            return $series; // retorna cache se api falhar
        }

        $series = CapySeries::updateOrCreate(
            ['tmdb_id' => $tmdbId],
            [
                'name' => $data['name'] ?? 'Sem Título',
                'original_name' => $data['original_name'] ?? null,
                'overview' => $data['overview'] ?? null,
                'poster_path' => $data['poster_path'] ?? null,
                'backdrop_path' => $data['backdrop_path'] ?? null,
                'first_air_date' => ! empty($data['first_air_date']) ? $data['first_air_date'] : null,
                'status' => $data['status'] ?? null,
                'vote_average' => $data['vote_average'] ?? 0,
                'number_of_seasons' => $data['number_of_seasons'] ?? 0,
                'number_of_episodes' => $data['number_of_episodes'] ?? 0,
                'genres' => $data['genres'] ?? [],
                'synced_at' => now(),
            ]
        );

        // Salvar temporadas
        if (! empty($data['seasons'])) {
            foreach ($data['seasons'] as $sData) {
                // temporada 0 geralmente são especiais/extras; podemos manter ou sincronizar
                CapySeason::updateOrCreate(
                    [
                        'series_id' => $series->id,
                        'season_number' => $sData['season_number'],
                    ],
                    [
                        'tmdb_id' => $sData['id'] ?? null,
                        'name' => $sData['name'] ?? null,
                        'overview' => $sData['overview'] ?? null,
                        'poster_path' => $sData['poster_path'] ?? null,
                        'air_date' => ! empty($sData['air_date']) ? $sData['air_date'] : null,
                        'episode_count' => $sData['episode_count'] ?? 0,
                    ]
                );
            }
        }

        return $series;
    }

    /**
     * Sincroniza e garante episódios de uma temporada específica
     */
    public function syncSeasonEpisodes(CapySeries $series, int $seasonNumber): ?CapySeason
    {
        $season = CapySeason::where('series_id', $series->id)
            ->where('season_number', $seasonNumber)
            ->first();

        // Se já tem episódios cadastrados, retorna direto
        if ($season && $season->episodes()->exists()) {
            return $season;
        }

        $data = $this->tmdb->getSeasonDetails($series->tmdb_id, $seasonNumber);
        if (! $data) {
            return $season;
        }

        if (! $season) {
            $season = CapySeason::create([
                'series_id' => $series->id,
                'season_number' => $seasonNumber,
                'tmdb_id' => $data['id'] ?? null,
                'name' => $data['name'] ?? "Temporada {$seasonNumber}",
                'overview' => $data['overview'] ?? null,
                'poster_path' => $data['poster_path'] ?? null,
                'air_date' => ! empty($data['air_date']) ? $data['air_date'] : null,
                'episode_count' => count($data['episodes'] ?? []),
            ]);
        }

        if (! empty($data['episodes'])) {
            foreach ($data['episodes'] as $epData) {
                CapyEpisode::updateOrCreate(
                    [
                        'series_id' => $series->id,
                        'season_number' => $seasonNumber,
                        'episode_number' => $epData['episode_number'],
                    ],
                    [
                        'season_id' => $season->id,
                        'tmdb_id' => $epData['id'] ?? null,
                        'name' => $epData['name'] ?? "Episódio {$epData['episode_number']}",
                        'overview' => $epData['overview'] ?? null,
                        'still_path' => $epData['still_path'] ?? null,
                        'air_date' => ! empty($epData['air_date']) ? $epData['air_date'] : null,
                        'runtime' => $epData['runtime'] ?? null,
                    ]
                );
            }
        }

        return $season;
    }

    /**
     * Atualiza ou adiciona o status da série na lista do usuário
     */
    public function setSeriesStatus(User $user, CapySeries $series, string $status): CapyUserSeries
    {
        $userSeries = CapyUserSeries::updateOrCreate(
            ['user_id' => $user->id, 'series_id' => $series->id],
            ['status' => $status]
        );

        if ($status === 'completed') {
            $this->markAllSeriesEpisodesWatched($user, $series);
        }

        return $userSeries;
    }

    /**
     * Sincroniza todas as temporadas regulares e marca todos os episódios da série como assistidos
     */
    public function markAllSeriesEpisodesWatched(User $user, CapySeries $series): void
    {
        $seasons = $series->seasons()->where('season_number', '>', 0)->get();

        foreach ($seasons as $season) {
            $this->syncSeasonEpisodes($series, $season->season_number);
        }

        $episodes = $series->episodes()->where('season_number', '>', 0)->get();
        if ($episodes->isEmpty()) {
            return;
        }

        $now = now();

        // Atualiza episódios já registrados para o usuário que estavam como não assistidos
        CapyUserEpisode::where('user_id', $user->id)
            ->where('series_id', $series->id)
            ->where('is_watched', false)
            ->update([
                'is_watched' => true,
                'watched_at' => $now,
                'updated_at' => $now,
            ]);

        // Busca quais episódios já têm registro para este usuário
        $existingEpisodeIds = CapyUserEpisode::where('user_id', $user->id)
            ->where('series_id', $series->id)
            ->pluck('episode_id')
            ->flip();

        $toInsert = [];
        foreach ($episodes as $ep) {
            if (! $existingEpisodeIds->has($ep->id)) {
                $toInsert[] = [
                    'user_id' => $user->id,
                    'series_id' => $series->id,
                    'season_id' => $ep->season_id,
                    'episode_id' => $ep->id,
                    'is_watched' => true,
                    'watched_at' => $now,
                    'rating' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if (! empty($toInsert)) {
            foreach (array_chunk($toInsert, 200) as $chunk) {
                CapyUserEpisode::insert($chunk);
            }
        }
    }

    /**
     * Verifica se o usuário assistiu de fato a todos os episódios/temporadas da série
     */
    public function isSeriesFullyWatched(int $userId, CapySeries $series): bool
    {
        // 1. Temporadas regulares (season_number > 0)
        $seasons = CapySeason::where('series_id', $series->id)
            ->where('season_number', '>', 0)
            ->get();

        $totalExpected = 0;
        if ($seasons->isNotEmpty()) {
            $totalExpected = (int) $seasons->sum('episode_count');
        }

        if ($totalExpected <= 0 && $series->number_of_episodes > 0) {
            $totalExpected = (int) $series->number_of_episodes;
        }

        if ($totalExpected <= 0) {
            $totalExpected = CapyEpisode::where('series_id', $series->id)
                ->where('season_number', '>', 0)
                ->count();
        }

        if ($totalExpected <= 0) {
            return false;
        }

        // 2. Contagem de episódios regulares assistidos
        $watchedCount = CapyUserEpisode::where('user_id', $userId)
            ->where('series_id', $series->id)
            ->where('is_watched', true)
            ->whereHas('episode', fn ($q) => $q->where('season_number', '>', 0))
            ->count();

        if ($watchedCount < $totalExpected) {
            return false;
        }

        // 3. Se a série tem mais de uma temporada, certificar que todas as temporadas têm episódios assistidos
        if ($seasons->count() > 1) {
            $watchedSeasonIds = CapyUserEpisode::where('user_id', $userId)
                ->where('series_id', $series->id)
                ->where('is_watched', true)
                ->whereNotNull('season_id')
                ->whereHas('season', fn ($q) => $q->where('season_number', '>', 0))
                ->distinct()
                ->pluck('season_id');

            if ($watchedSeasonIds->count() < $seasons->count()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Marca todos os episódios de uma temporada específica como assistidos
     */
    public function markSeasonEpisodesWatched(User $user, CapySeries $series, int $seasonNumber): ?array
    {
        $season = $this->syncSeasonEpisodes($series, $seasonNumber);
        if (! $season) {
            return null;
        }

        $episodes = $season->episodes()->orderBy('episode_number')->get();
        if ($episodes->isEmpty()) {
            return [
                'season_id' => $season->id,
                'season_number' => $seasonNumber,
                'watched_episode_ids' => [],
                'user_status' => null,
                'season_average' => null,
                'series_average' => null,
            ];
        }

        $now = now();
        $episodeIds = $episodes->pluck('id');

        // Atualiza os que já existem mas não estavam assistidos
        CapyUserEpisode::where('user_id', $user->id)
            ->whereIn('episode_id', $episodeIds)
            ->where('is_watched', false)
            ->update([
                'is_watched' => true,
                'watched_at' => $now,
                'updated_at' => $now,
            ]);

        $existingEpisodeIds = CapyUserEpisode::where('user_id', $user->id)
            ->whereIn('episode_id', $episodeIds)
            ->pluck('episode_id')
            ->flip();

        $toInsert = [];
        foreach ($episodes as $ep) {
            if (! $existingEpisodeIds->has($ep->id)) {
                $toInsert[] = [
                    'user_id' => $user->id,
                    'series_id' => $series->id,
                    'season_id' => $season->id,
                    'episode_id' => $ep->id,
                    'is_watched' => true,
                    'watched_at' => $now,
                    'rating' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if (! empty($toInsert)) {
            foreach (array_chunk($toInsert, 200) as $chunk) {
                CapyUserEpisode::insert($chunk);
            }
        }

        // Atualiza status da série para watching ou completed
        $userSeries = CapyUserSeries::firstOrNew([
            'user_id' => $user->id,
            'series_id' => $series->id,
        ]);

        if ($this->isSeriesFullyWatched($user->id, $series)) {
            $userSeries->status = 'completed';
        } else {
            $userSeries->status = 'watching';
        }
        $userSeries->save();

        $ratings = $this->getUserSeriesRatings($user->id, $series->id);

        return [
            'season_id' => $season->id,
            'season_number' => $seasonNumber,
            'watched_episode_ids' => $episodeIds->all(),
            'user_status' => $userSeries->status,
            'season_average' => $ratings['seasons'][$seasonNumber]['average'] ?? null,
            'series_average' => $ratings['series_average'],
        ];
    }

    /**
     * Marca o episódio selecionado e todos os anteriores (da temporada e temporadas anteriores) como assistidos
     */
    public function markPreviousEpisodesWatched(User $user, CapyEpisode $targetEpisode): array
    {
        $series = $targetEpisode->series;
        if ($series) {
            // Sincroniza todas as temporadas até a temporada do episódio alvo
            for ($s = 1; $s <= $targetEpisode->season_number; $s++) {
                $this->syncSeasonEpisodes($series, $s);
            }
        }

        // Busca todos os episódios até o alvo
        $episodes = CapyEpisode::where('series_id', $targetEpisode->series_id)
            ->where('season_number', '>', 0)
            ->where(function ($query) use ($targetEpisode) {
                $query->where('season_number', '<', $targetEpisode->season_number)
                    ->orWhere(function ($q) use ($targetEpisode) {
                        $q->where('season_number', $targetEpisode->season_number)
                            ->where('episode_number', '<=', $targetEpisode->episode_number);
                    });
            })
            ->orderBy('season_number')
            ->orderBy('episode_number')
            ->get();

        $now = now();
        $episodeIds = $episodes->pluck('id');

        CapyUserEpisode::where('user_id', $user->id)
            ->whereIn('episode_id', $episodeIds)
            ->where('is_watched', false)
            ->update([
                'is_watched' => true,
                'watched_at' => $now,
                'updated_at' => $now,
            ]);

        $existingEpisodeIds = CapyUserEpisode::where('user_id', $user->id)
            ->whereIn('episode_id', $episodeIds)
            ->pluck('episode_id')
            ->flip();

        $toInsert = [];
        foreach ($episodes as $ep) {
            if (! $existingEpisodeIds->has($ep->id)) {
                $toInsert[] = [
                    'user_id' => $user->id,
                    'series_id' => $ep->series_id,
                    'season_id' => $ep->season_id,
                    'episode_id' => $ep->id,
                    'is_watched' => true,
                    'watched_at' => $now,
                    'rating' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if (! empty($toInsert)) {
            foreach (array_chunk($toInsert, 200) as $chunk) {
                CapyUserEpisode::insert($chunk);
            }
        }

        // Atualiza status da série
        $userSeries = CapyUserSeries::firstOrNew([
            'user_id' => $user->id,
            'series_id' => $targetEpisode->series_id,
        ]);

        $series = $targetEpisode->series;
        if ($series && $this->isSeriesFullyWatched($user->id, $series)) {
            $userSeries->status = 'completed';
        } else {
            $userSeries->status = 'watching';
        }
        $userSeries->save();

        $ratings = $this->getUserSeriesRatings($user->id, $targetEpisode->series_id);

        return [
            'target_episode_id' => $targetEpisode->id,
            'watched_episode_ids' => $episodeIds->all(),
            'user_status' => $userSeries->status,
            'season_average' => $ratings['seasons'][$targetEpisode->season_number]['average'] ?? null,
            'series_average' => $ratings['series_average'],
        ];
    }

    /**
     * Remove série da lista do usuário
     */
    public function removeSeriesStatus(User $user, CapySeries $series): void
    {
        CapyUserSeries::where('user_id', $user->id)
            ->where('series_id', $series->id)
            ->delete();
    }

    /**
     * Marca episódio como assistido e/ou define a nota de 1 a 10
     */
    public function rateOrWatchEpisode(User $user, CapyEpisode $episode, ?float $rating = null, ?bool $isWatched = true): CapyUserEpisode
    {
        $progress = CapyUserEpisode::firstOrNew([
            'user_id' => $user->id,
            'episode_id' => $episode->id,
        ]);

        $progress->series_id = $episode->series_id;
        $progress->season_id = $episode->season_id;
        $progress->is_watched = $isWatched ?? true;

        if ($progress->is_watched && ! $progress->watched_at) {
            $progress->watched_at = now();
        } elseif (! $progress->is_watched) {
            $progress->watched_at = null;
        }

        if ($rating !== null) {
            // Nota de 1.0 a 10.0 (clamp)
            $rating = max(1.0, min(10.0, round($rating, 1)));
            $progress->rating = $rating;
            $progress->is_watched = true;
            if (! $progress->watched_at) {
                $progress->watched_at = now();
            }
        }

        $progress->save();

        // Se o usuário ainda não adicionou a série na lista, coloca como 'watching'
        $userSeries = CapyUserSeries::firstOrCreate(
            ['user_id' => $user->id, 'series_id' => $episode->series_id],
            ['status' => 'watching']
        );

        $series = $episode->series;
        if ($series && $this->isSeriesFullyWatched($user->id, $series)) {
            $userSeries->status = 'completed';
        } elseif ($userSeries->status === 'completed') {
            $userSeries->status = 'watching';
        } elseif (! $userSeries->status || $userSeries->status === 'plan_to_watch') {
            $userSeries->status = 'watching';
        }
        $userSeries->save();

        return $progress;
    }

    /**
     * Remove a nota de um episódio
     */
    public function removeEpisodeRating(User $user, CapyEpisode $episode): ?CapyUserEpisode
    {
        $progress = CapyUserEpisode::where('user_id', $user->id)
            ->where('episode_id', $episode->id)
            ->first();

        if ($progress) {
            $progress->rating = null;
            $progress->save();
        }

        return $progress;
    }

    /**
     * Calcula as médias para um usuário específico em uma série
     * - Média do episódio: nota atribuída
     * - Média da temporada: média dos episódios avaliados daquela temporada
     * - Média da série: média das médias das temporadas avaliadas!
     */
    public function getUserSeriesRatings(int $userId, int $seriesId): array
    {
        $seasons = CapySeason::where('series_id', $seriesId)
            ->where('season_number', '>', 0) // ignora especiais no cálculo principal
            ->with(['episodes.userEpisodes' => function ($q) use ($userId) {
                $q->where('user_id', $userId);
            }])
            ->get();

        $seasonAverages = [];

        foreach ($seasons as $season) {
            $ratings = [];
            foreach ($season->episodes as $episode) {
                $userEp = $episode->userEpisodes->first();
                if ($userEp && $userEp->rating !== null) {
                    $ratings[] = (float) $userEp->rating;
                }
            }

            if (count($ratings) > 0) {
                $avg = round(array_sum($ratings) / count($ratings), 1);
                $seasonAverages[$season->season_number] = [
                    'season_id' => $season->id,
                    'season_number' => $season->season_number,
                    'average' => $avg,
                    'rated_episodes_count' => count($ratings),
                    'total_episodes' => $season->episodes->count(),
                ];
            } else {
                $seasonAverages[$season->season_number] = [
                    'season_id' => $season->id,
                    'season_number' => $season->season_number,
                    'average' => null,
                    'rated_episodes_count' => 0,
                    'total_episodes' => $season->episodes->count(),
                ];
            }
        }

        // Média da série: média aritmética das médias das temporadas avaliadas
        $validSeasonAverages = array_filter(
            array_column($seasonAverages, 'average'),
            fn ($val) => $val !== null
        );

        $seriesAverage = count($validSeasonAverages) > 0
            ? round(array_sum($validSeasonAverages) / count($validSeasonAverages), 1)
            : null;

        return [
            'series_average' => $seriesAverage,
            'seasons' => $seasonAverages,
        ];
    }

    /**
     * Calcula as médias comunitárias (todos os usuários da Capihouse)
     */
    public function getCommunitySeriesRatings(int $seriesId): array
    {
        $seasonRatings = CapyUserEpisode::where('capy_user_episodes.series_id', $seriesId)
            ->join('capy_seasons', 'capy_user_episodes.season_id', '=', 'capy_seasons.id')
            ->where('capy_seasons.season_number', '>', 0)
            ->whereNotNull('capy_user_episodes.rating')
            ->groupBy('capy_seasons.season_number', 'capy_seasons.id')
            ->select(
                'capy_seasons.season_number',
                'capy_seasons.id as season_id',
                DB::raw('ROUND(AVG(capy_user_episodes.rating), 1) as average'),
                DB::raw('COUNT(capy_user_episodes.id) as total_ratings')
            )
            ->get()
            ->keyBy('season_number');

        $seasonAverages = [];
        foreach ($seasonRatings as $num => $row) {
            $seasonAverages[$num] = [
                'season_id' => $row->season_id,
                'season_number' => (int) $row->season_number,
                'average' => (float) $row->average,
                'total_ratings' => (int) $row->total_ratings,
            ];
        }

        $validAverages = array_column($seasonAverages, 'average');
        $seriesAverage = count($validAverages) > 0
            ? round(array_sum($validAverages) / count($validAverages), 1)
            : null;

        return [
            'series_average' => $seriesAverage,
            'seasons' => $seasonAverages,
        ];
    }

    /**
     * Retorna o feed recente de atividades relevantes (notas, comentários e conclusões de séries).
     * Episódios apenas assistidos (sem nota) são ignorados para manter o feed focado no que é realmente legal.
     */
    public function getCommunityActivity(int $limit = 30)
    {
        // 1. Episódios avaliados (notas dadas pelos usuários)
        $ratedEpisodes = CapyUserEpisode::with([
            'user:id,name,username,avatar_url',
            'series:id,tmdb_id,name,poster_path',
            'episode:id,series_id,season_id,season_number,episode_number,name,still_path',
        ])
            ->whereNotNull('rating')
            ->where('rating', '>', 0)
            ->whereHas('user')
            ->whereHas('series')
            ->whereHas('episode')
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get()
            ->map(function ($ue) {
                $time = $ue->watched_at ?? $ue->updated_at;

                return [
                    'id' => 'rating_'.$ue->id,
                    'type' => 'rating',
                    'timestamp' => $time ? $time->toISOString() : now()->toISOString(),
                    'user' => [
                        'id' => $ue->user->id,
                        'name' => $ue->user->name,
                        'username' => $ue->user->username,
                        'avatar_url' => $ue->user->avatar_url,
                    ],
                    'series' => [
                        'id' => $ue->series->id,
                        'tmdb_id' => $ue->series->tmdb_id,
                        'name' => $ue->series->name,
                        'poster_url' => $ue->series->poster_url,
                    ],
                    'episode' => [
                        'id' => $ue->episode->id,
                        'season_number' => $ue->episode->season_number,
                        'episode_number' => $ue->episode->episode_number,
                        'name' => $ue->episode->name,
                    ],
                    'rating' => (float) $ue->rating,
                    'comment' => null,
                    'watched_at' => $time ? $time->diffForHumans() : 'recentemente',
                ];
            });

        // 2. Comentários em episódios
        $comments = CapyEpisodeComment::with([
            'user:id,name,username,avatar_url',
            'episode.series:id,tmdb_id,name,poster_path',
        ])
            ->whereHas('user')
            ->whereHas('episode.series')
            ->latest()
            ->limit($limit)
            ->get()
            ->map(function ($c) {
                $ep = $c->episode;
                $series = $ep?->series;

                return [
                    'id' => 'comment_'.$c->id,
                    'type' => 'comment',
                    'timestamp' => $c->created_at ? $c->created_at->toISOString() : now()->toISOString(),
                    'user' => [
                        'id' => $c->user->id,
                        'name' => $c->user->name,
                        'username' => $c->user->username,
                        'avatar_url' => $c->user->avatar_url,
                    ],
                    'series' => [
                        'id' => $series->id,
                        'tmdb_id' => $series->tmdb_id,
                        'name' => $series->name,
                        'poster_url' => $series->poster_url,
                    ],
                    'episode' => [
                        'id' => $ep->id,
                        'season_number' => $ep->season_number,
                        'episode_number' => $ep->episode_number,
                        'name' => $ep->name,
                    ],
                    'rating' => null,
                    'comment' => [
                        'id' => $c->id,
                        'content' => $c->content,
                        'is_spoiler' => (bool) $c->is_spoiler,
                    ],
                    'watched_at' => $c->created_at ? $c->created_at->diffForHumans() : 'recentemente',
                ];
            });

        // 3. Conclusão de séries (status 'completed')
        $completions = CapyUserSeries::with([
            'user:id,name,username,avatar_url',
            'series:id,tmdb_id,name,poster_path,number_of_seasons',
        ])
            ->where('status', 'completed')
            ->whereHas('user')
            ->whereHas('series')
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get()
            ->map(function ($us) {
                $time = $us->updated_at;

                return [
                    'id' => 'completed_'.$us->id,
                    'type' => 'completed',
                    'timestamp' => $time ? $time->toISOString() : now()->toISOString(),
                    'user' => [
                        'id' => $us->user->id,
                        'name' => $us->user->name,
                        'username' => $us->user->username,
                        'avatar_url' => $us->user->avatar_url,
                    ],
                    'series' => [
                        'id' => $us->series->id,
                        'tmdb_id' => $us->series->tmdb_id,
                        'name' => $us->series->name,
                        'poster_url' => $us->series->poster_url,
                        'number_of_seasons' => $us->series->number_of_seasons,
                    ],
                    'episode' => null,
                    'rating' => null,
                    'comment' => null,
                    'watched_at' => $time ? $time->diffForHumans() : 'recentemente',
                ];
            });

        return collect()
            ->concat($ratedEpisodes)
            ->concat($comments)
            ->concat($completions)
            ->sortByDesc(fn ($item) => strtotime($item['timestamp']))
            ->take($limit)
            ->values();
    }
}
