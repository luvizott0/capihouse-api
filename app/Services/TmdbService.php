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
}
