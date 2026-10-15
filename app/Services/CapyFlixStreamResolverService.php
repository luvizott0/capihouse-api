<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CapyFlixStreamResolverService
{
    protected const TMDB_API_KEY = '9460fe099c4cb1ca3026582f448e9d29';

    public function __construct(
        protected CapyFlixStorageService $storageService
    ) {}

    /**
     * Obtém as credenciais ativas de Debrid (Torbox ou Real-Debrid).
     */
    public function getDebridConfig(): array
    {
        $provider = Cache::get('capyflix:debrid_provider', env('CAPYFLIX_DEBRID_PROVIDER', 'torbox'));
        $token = Cache::get('capyflix:debrid_token', env('CAPYFLIX_DEBRID_TOKEN', ''));

        return [
            'provider' => $provider,
            'has_token' => ! empty($token),
            'masked_token' => ! empty($token) ? substr($token, 0, 4).'••••'.substr($token, -4) : null,
        ];
    }

    /**
     * Salva as configurações de Debrid.
     */
    public function saveDebridConfig(string $provider, string $token): array
    {
        Cache::forever('capyflix:debrid_provider', $provider);
        Cache::forever('capyflix:debrid_token', trim($token));

        return $this->getDebridConfig();
    }

    /**
     * Resolve streams diretos para um filme ou série via TMDB ID ou IMDb ID.
     */
    public function resolveStreams(array $params): array
    {
        $tmdbId = $params['tmdb_id'] ?? null;
        $title = $params['title'] ?? null;
        $mediaType = $params['media_type'] ?? 'movie';
        $season = (int) ($params['season'] ?? 1);
        $episode = (int) ($params['episode'] ?? 1);
        $userToken = $params['token'] ?? null;
        $userProvider = $params['provider'] ?? null;

        $token = $userToken ?: Cache::get('capyflix:debrid_token', env('CAPYFLIX_DEBRID_TOKEN', ''));
        $provider = $userProvider ?: Cache::get('capyflix:debrid_provider', env('CAPYFLIX_DEBRID_PROVIDER', 'torbox'));

        $streams = [];

        // 0. Se já existir no NAS local, adiciona a fonte local com prioridade absoluta
        if (! empty($title)) {
            $localUrl = $this->storageService->findLocalMediaUrl($mediaType, $title, $season, $episode);
            if (! empty($localUrl)) {
                $streams[] = [
                    'id' => 'local-nas',
                    'name' => 'Servidor Local (NAS)',
                    'url' => $localUrl,
                    'badge' => 'LOCAL • MAX',
                    'isDirectVideo' => true,
                    'is_local' => true,
                ];
            }
        }

        $imdbId = $params['imdb_id'] ?? null;
        if (empty($imdbId) && ! empty($tmdbId)) {
            $imdbId = $this->getImdbIdFromTmdb((int) $tmdbId, $mediaType);
        }

        if (empty($imdbId)) {
            return $streams;
        }

        // 1. Se possuir Token de Torbox ou Real-Debrid configurado, consulta direto via Torrentio Debrid
        if (! empty($token)) {
            $streams = $this->fetchDebridStreams($imdbId, $mediaType, $season, $episode, $provider, $token);
        }

        // 2. Se não tiver streams diretos ou não tiver token, consulta índices abertos para permitir "Baixar no NAS"
        if (empty($streams)) {
            $streams = $this->fetchPublicTorrentioStreams($imdbId, $mediaType, $season, $episode);
        }

        return $streams;
    }

    /**
     * Converte TMDB ID em IMDb ID através da API pública do TMDB.
     */
    public function getImdbIdFromTmdb(int $tmdbId, string $mediaType): ?string
    {
        $cacheKey = "capyflix:imdb_id:{$mediaType}:{$tmdbId}";
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        try {
            $endpoint = $mediaType === 'movie' ? "movie/{$tmdbId}" : "tv/{$tmdbId}";
            $response = Http::timeout(5)->get("https://api.themoviedb.org/3/{$endpoint}/external_ids", [
                'api_key' => self::TMDB_API_KEY,
            ]);

            if ($response->successful()) {
                $imdbId = $response->json('imdb_id');
                if (! empty($imdbId)) {
                    Cache::put($cacheKey, $imdbId, now()->addDays(30));

                    return $imdbId;
                }
            }
        } catch (\Throwable $e) {
            Log::warning("Falha ao obter IMDb ID para TMDB {$tmdbId}: ".$e->getMessage());
        }

        return null;
    }

    /**
     * Busca streams de alta velocidade usando Debrid (Torbox ou Real-Debrid).
     */
    protected function fetchDebridStreams(
        string $imdbId,
        string $mediaType,
        int $season,
        int $episode,
        string $provider,
        string $token
    ): array {
        $streams = [];
        $queryPath = $mediaType === 'movie' ? "movie/{$imdbId}.json" : "series/{$imdbId}:{$season}:{$episode}.json";

        try {
            if ($provider === 'torbox') {
                // Torbox Stremio endpoint direto
                $url = "https://torbox.app/stremio/{$token}/stream/{$queryPath}";
                $response = Http::timeout(7)->get($url);

                if ($response->successful()) {
                    $rawStreams = $response->json('streams') ?? [];
                    foreach ($rawStreams as $idx => $s) {
                        if (! empty($s['url'])) {
                            $streams[] = [
                                'id' => "torbox-{$idx}",
                                'name' => 'Torbox CDN (Direto)',
                                'title' => $s['title'] ?? '1080p Stream',
                                'url' => $s['url'],
                                'badge' => 'TORBOX 1GBPS',
                                'isDirectVideo' => true,
                                'canDownloadToNas' => true,
                            ];
                        }
                    }
                }
            } elseif ($provider === 'realdebrid') {
                // Torrentio com Real-Debrid
                $url = "https://torrentio.strem.fun/realdebrid={$token}/stream/{$queryPath}";
                $response = Http::timeout(7)->get($url);

                if ($response->successful()) {
                    $rawStreams = $response->json('streams') ?? [];
                    foreach ($rawStreams as $idx => $s) {
                        if (! empty($s['url'])) {
                            $streams[] = [
                                'id' => "rd-{$idx}",
                                'name' => 'Real-Debrid (Direto)',
                                'title' => $s['title'] ?? '1080p Stream',
                                'url' => $s['url'],
                                'badge' => 'REAL-DEBRID',
                                'isDirectVideo' => true,
                                'canDownloadToNas' => true,
                            ];
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Erro ao resolver streams Debrid: '.$e->getMessage());
        }

        return $streams;
    }

    /**
     * Consulta torrents abertos do Torrentio para listar fontes com suporte a "Baixar no NAS".
     */
    protected function fetchPublicTorrentioStreams(
        string $imdbId,
        string $mediaType,
        int $season,
        int $episode
    ): array {
        $streams = [];
        $queryPath = $mediaType === 'movie' ? "movie/{$imdbId}.json" : "series/{$imdbId}:{$season}:{$episode}.json";

        try {
            $url = "https://torrentio.strem.fun/stream/{$queryPath}";
            $response = Http::timeout(6)->get($url);

            if ($response->successful()) {
                $rawStreams = $response->json('streams') ?? [];
                // Pega os 5 melhores por seeds
                $count = 0;
                foreach ($rawStreams as $idx => $s) {
                    if ($count >= 5) {
                        break;
                    }

                    $titleLines = explode("\n", $s['title'] ?? '');
                    $cleanTitle = $titleLines[0] ?? 'HD Stream';
                    $stats = $titleLines[1] ?? '';

                    $streams[] = [
                        'id' => "torrentio-{$idx}",
                        'name' => 'Acervo P2P / Web',
                        'title' => "{$cleanTitle} ({$stats})",
                        'infoHash' => $s['infoHash'] ?? null,
                        'fileIdx' => $s['fileIdx'] ?? 0,
                        'url' => ! empty($s['url']) ? $s['url'] : '',
                        'badge' => 'BAIXAR NO NAS',
                        'isDirectVideo' => ! empty($s['url']),
                        'canDownloadToNas' => true,
                    ];
                    $count++;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Erro ao consultar Torrentio público: '.$e->getMessage());
        }

        return $streams;
    }
}
