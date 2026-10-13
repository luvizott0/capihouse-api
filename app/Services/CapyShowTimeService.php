<?php

namespace App\Services;

use App\Models\CapyEpisode;
use App\Models\CapyEpisodeComment;
use App\Models\CapyEpisodeCommentLike;
use App\Models\CapySeason;
use App\Models\CapySeries;
use App\Models\CapyUserEpisode;
use App\Models\CapyUserSeries;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CapyShowTimeService
{
    public function __construct(protected TmdbService $tmdb)
    {
    }

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
        if (!$data) {
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
                'first_air_date' => !empty($data['first_air_date']) ? $data['first_air_date'] : null,
                'status' => $data['status'] ?? null,
                'vote_average' => $data['vote_average'] ?? 0,
                'number_of_seasons' => $data['number_of_seasons'] ?? 0,
                'number_of_episodes' => $data['number_of_episodes'] ?? 0,
                'genres' => $data['genres'] ?? [],
                'synced_at' => now(),
            ]
        );

        // Salvar temporadas
        if (!empty($data['seasons'])) {
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
                        'air_date' => !empty($sData['air_date']) ? $sData['air_date'] : null,
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
        if (!$data) {
            return $season;
        }

        if (!$season) {
            $season = CapySeason::create([
                'series_id' => $series->id,
                'season_number' => $seasonNumber,
                'tmdb_id' => $data['id'] ?? null,
                'name' => $data['name'] ?? "Temporada {$seasonNumber}",
                'overview' => $data['overview'] ?? null,
                'poster_path' => $data['poster_path'] ?? null,
                'air_date' => !empty($data['air_date']) ? $data['air_date'] : null,
                'episode_count' => count($data['episodes'] ?? []),
            ]);
        }

        if (!empty($data['episodes'])) {
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
                        'air_date' => !empty($epData['air_date']) ? $epData['air_date'] : null,
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
            if (!$existingEpisodeIds->has($ep->id)) {
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

        if (!empty($toInsert)) {
            foreach (array_chunk($toInsert, 200) as $chunk) {
                CapyUserEpisode::insert($chunk);
            }
        }
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
        
        if ($progress->is_watched && !$progress->watched_at) {
            $progress->watched_at = now();
        } elseif (!$progress->is_watched) {
            $progress->watched_at = null;
        }

        if ($rating !== null) {
            // Nota de 1.0 a 10.0 (clamp)
            $rating = max(1.0, min(10.0, round($rating, 1)));
            $progress->rating = $rating;
            $progress->is_watched = true;
            if (!$progress->watched_at) {
                $progress->watched_at = now();
            }
        }

        $progress->save();

        // Se o usuário ainda não adicionou a série na lista, coloca como 'watching'
        $userSeries = CapyUserSeries::firstOrCreate(
            ['user_id' => $user->id, 'series_id' => $episode->series_id],
            ['status' => 'watching']
        );

        if ($userSeries->status === 'plan_to_watch') {
            $userSeries->update(['status' => 'watching']);
        }

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
     * Retorna o feed recente de atividades da Capihouse (quem assistiu ou comentou)
     */
    public function getCommunityActivity(int $limit = 20)
    {
        return CapyUserEpisode::with(['user', 'series', 'episode'])
            ->where('is_watched', true)
            ->orderByDesc('watched_at')
            ->limit($limit)
            ->get();
    }
}
