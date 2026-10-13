<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TmdbService
{
    protected string $baseUrl = 'https://api.themoviedb.org/3';
    protected ?string $apiKey;
    protected ?string $readToken;
    protected string $language;

    public function __construct()
    {
        $this->apiKey = config('services.tmdb.api_key');
        $this->readToken = config('services.tmdb.read_token');
        $this->language = config('services.tmdb.language', 'pt-BR');
    }

    protected function client()
    {
        $client = Http::baseUrl($this->baseUrl)->timeout(10);

        if ($this->readToken) {
            return $client->withToken($this->readToken);
        }

        return $client;
    }

    protected function defaultParams(): array
    {
        $params = ['language' => $this->language];
        if (!$this->readToken && $this->apiKey) {
            $params['api_key'] = $this->apiKey;
        }
        return $params;
    }

    /**
     * Busca séries por palavra-chave
     */
    public function search(string $query, int $page = 1): array
    {
        if (trim($query) === '') {
            return ['results' => [], 'total_results' => 0, 'page' => 1];
        }

        $response = $this->client()->get('/search/tv', array_merge($this->defaultParams(), [
            'query' => $query,
            'page' => $page,
            'include_adult' => false,
        ]));

        if ($response->successful()) {
            return $response->json();
        }

        Log::warning('TMDB Search Error: ' . $response->body());
        return ['results' => [], 'total_results' => 0, 'page' => $page];
    }

    /**
     * Busca séries populares
     */
    public function getPopular(int $page = 1): array
    {
        $response = $this->client()->get('/tv/popular', array_merge($this->defaultParams(), [
            'page' => $page,
        ]));

        return $response->successful() ? $response->json() : ['results' => []];
    }

    /**
     * Busca séries em alta na semana
     */
    public function getTrending(): array
    {
        $response = $this->client()->get('/trending/tv/week', $this->defaultParams());

        return $response->successful() ? $response->json() : ['results' => []];
    }

    /**
     * Busca detalhes da série com temporadas
     */
    public function getSeriesDetails(int $tmdbId): ?array
    {
        $response = $this->client()->get("/tv/{$tmdbId}", $this->defaultParams());

        return $response->successful() ? $response->json() : null;
    }

    /**
     * Busca detalhes de uma temporada específica com lista de episódios
     */
    public function getSeasonDetails(int $tmdbId, int $seasonNumber): ?array
    {
        $response = $this->client()->get("/tv/{$tmdbId}/season/{$seasonNumber}", $this->defaultParams());

        return $response->successful() ? $response->json() : null;
    }

    /**
     * Lista de gêneros de séries de TV do TMDB (sempre traduzidos para português)
     */
    public function getGenres(): array
    {
        $translationsById = [
            10759 => 'Ação e Aventura',
            16 => 'Animação',
            35 => 'Comédia',
            80 => 'Crime',
            99 => 'Documentário',
            18 => 'Drama',
            10751 => 'Família',
            10762 => 'Infantil',
            9648 => 'Mistério',
            10763 => 'Notícias',
            10764 => 'Reality Show',
            10765 => 'Ficção Científica e Fantasia',
            10766 => 'Novela',
            10767 => 'Entrevistas',
            10768 => 'Guerra e Política',
            37 => 'Faroeste',
            28 => 'Ação',
            12 => 'Aventura',
            14 => 'Fantasia',
            36 => 'História',
            27 => 'Terror',
            10402 => 'Música',
            10749 => 'Romance',
            878 => 'Ficção Científica',
            10770 => 'Cinema TV',
            53 => 'Suspense',
            10752 => 'Guerra',
        ];

        $translationsByName = [
            'Action & Adventure' => 'Ação e Aventura',
            'Animation' => 'Animação',
            'Comedy' => 'Comédia',
            'Crime' => 'Crime',
            'Documentary' => 'Documentário',
            'Drama' => 'Drama',
            'Family' => 'Família',
            'Kids' => 'Infantil',
            'Mystery' => 'Mistério',
            'News' => 'Notícias',
            'Reality' => 'Reality Show',
            'Sci-Fi & Fantasy' => 'Ficção Científica e Fantasia',
            'Sci-Fi & Fantasia' => 'Ficção Científica e Fantasia',
            'Soap' => 'Novela',
            'Talk' => 'Entrevistas',
            'Talk Show' => 'Entrevistas',
            'War & Politics' => 'Guerra e Política',
            'Western' => 'Faroeste',
        ];

        $fallback = [
            ['id' => 10759, 'name' => 'Ação e Aventura'],
            ['id' => 16, 'name' => 'Animação'],
            ['id' => 35, 'name' => 'Comédia'],
            ['id' => 80, 'name' => 'Crime'],
            ['id' => 99, 'name' => 'Documentário'],
            ['id' => 18, 'name' => 'Drama'],
            ['id' => 10751, 'name' => 'Família'],
            ['id' => 10762, 'name' => 'Infantil'],
            ['id' => 9648, 'name' => 'Mistério'],
            ['id' => 10763, 'name' => 'Notícias'],
            ['id' => 10764, 'name' => 'Reality Show'],
            ['id' => 10765, 'name' => 'Ficção Científica e Fantasia'],
            ['id' => 10766, 'name' => 'Novela'],
            ['id' => 10767, 'name' => 'Entrevistas'],
            ['id' => 10768, 'name' => 'Guerra e Política'],
            ['id' => 37, 'name' => 'Faroeste'],
        ];

        try {
            $response = $this->client()->get('/genre/tv/list', $this->defaultParams());
            if ($response->successful()) {
                $genres = $response->json('genres');
                if (!empty($genres) && is_array($genres)) {
                    $translated = array_map(function (array $g) use ($translationsById, $translationsByName) {
                        $id = $g['id'] ?? null;
                        $name = $g['name'] ?? '';
                        if ($id && isset($translationsById[$id])) {
                            $name = $translationsById[$id];
                        } elseif (isset($translationsByName[$name])) {
                            $name = $translationsByName[$name];
                        }
                        return [
                            'id' => $id,
                            'name' => $name,
                        ];
                    }, $genres);

                    return ['genres' => $translated];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('TMDB Genres Error: ' . $e->getMessage());
        }

        return ['genres' => $fallback];
    }

    /**
     * Descobrir séries com filtros avançados no TMDB
     */
    public function discover(array $filters = [], int $page = 1): array
    {
        $params = array_merge($this->defaultParams(), [
            'page' => $page,
            'include_adult' => false,
            'sort_by' => $filters['sort_by'] ?? 'popularity.desc',
        ]);

        if (!empty($filters['with_genres'])) {
            $params['with_genres'] = $filters['with_genres'];
        }

        if (isset($filters['min_rating']) && $filters['min_rating'] !== '' && (float) $filters['min_rating'] > 0) {
            $params['vote_average.gte'] = (float) $filters['min_rating'];
            $params['vote_count.gte'] = (int) ($filters['min_votes'] ?? 50);
        } elseif (($params['sort_by'] ?? '') === 'vote_average.desc') {
            $params['vote_count.gte'] = (int) ($filters['min_votes'] ?? 100);
        }

        if (!empty($filters['watch_provider'])) {
            $params['with_watch_providers'] = $filters['watch_provider'];
            $params['watch_region'] = $filters['watch_region'] ?? 'BR';
        }

        if (!empty($filters['year_from'])) {
            $params['first_air_date.gte'] = "{$filters['year_from']}-01-01";
        }

        if (!empty($filters['year_to'])) {
            $params['first_air_date.lte'] = "{$filters['year_to']}-12-31";
        }

        if (!empty($filters['language'])) {
            $params['with_original_language'] = $filters['language'];
        }

        if (isset($filters['status']) && $filters['status'] !== '') {
            $params['with_status'] = $filters['status'];
        }

        try {
            $response = $this->client()->get('/discover/tv', $params);

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning('TMDB Discover Error: ' . $response->body());
        } catch (\Throwable $e) {
            Log::warning('TMDB Discover Exception: ' . $e->getMessage());
        }

        return ['results' => [], 'total_results' => 0, 'page' => $page, 'total_pages' => 0];
    }
}
