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

        if (empty($imdbId) && ! empty($title)) {
            $imdbId = $this->searchImdbIdByTitle((string) $title, $mediaType);
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
            $streams = $this->fetchPublicTorrentioStreams($imdbId, $mediaType, $season, $episode, $title);
        }

        return $streams;
    }

    /**
     * Busca o IMDb ID no TMDB através do título da mídia (filme ou série).
     */
    public function searchImdbIdByTitle(string $title, string $mediaType): ?string
    {
        $cleanTitle = trim($title);
        if (empty($cleanTitle)) {
            return null;
        }

        $cacheKey = 'capyflix:imdb_search:'.md5("{$mediaType}:{$cleanTitle}");
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        try {
            $endpoint = ($mediaType === 'movie') ? 'search/movie' : 'search/tv';
            $response = Http::timeout(5)->get("https://api.themoviedb.org/3/{$endpoint}", [
                'api_key' => self::TMDB_API_KEY,
                'query' => $cleanTitle,
            ]);

            if ($response->successful()) {
                $results = $response->json('results') ?? [];
                if (! empty($results)) {
                    $matchedId = null;
                    foreach ($results as $r) {
                        $name = $r['name'] ?? ($r['title'] ?? '');
                        $origName = $r['original_name'] ?? ($r['original_title'] ?? '');
                        if (strcasecmp($name, $cleanTitle) === 0 || strcasecmp($origName, $cleanTitle) === 0) {
                            $matchedId = (int) ($r['id'] ?? 0);
                            break;
                        }
                    }

                    if (! $matchedId) {
                        $matchedId = (int) ($results[0]['id'] ?? 0);
                    }

                    if ($matchedId > 0) {
                        $imdbId = $this->getImdbIdFromTmdb($matchedId, $mediaType);
                        if (! empty($imdbId)) {
                            Cache::put($cacheKey, $imdbId, now()->addDays(30));

                            return $imdbId;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning("Falha ao buscar IMDb ID para título '{$title}': ".$e->getMessage());
        }

        return null;
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
     * Avalia e pontua um título de torrent de acordo com a preferência do usuário:
     * - Animações / Desenhos / Animes: preferência por ÁUDIO DUBLADO (PT-BR).
     * - Filmes e Séries: preferência por ÁUDIO ORIGINAL com legendas em português (ou Dual).
     */
    public function evaluateTorrentPreference(string $rawTitle, string $mediaType): array
    {
        $lower = mb_strtolower($rawTitle, 'UTF-8');
        $score = 0;
        $isAnimation = in_array($mediaType, ['anime', 'cartoon']);

        $hasDub = (bool) preg_match('/(?:dublado|dub\b|dual|pt-br|ptbr|portugu[eê]s|nacional|brazilian|🇧🇷)/i', $lower);
        $hasSub = (bool) preg_match('/(?:legendado|leg\b|sub\b|subbed|legendas|pt-br\s*sub)/i', $lower);
        $hasOriginal = (bool) preg_match('/(?:original|eng|english|multi|dual|jap|japanese|bluray|web-dl|hevc|x265|1080p|720p)/i', $lower);

        $badge = 'P2P TORRENT';
        $prefMatch = false;

        if ($isAnimation) {
            // Regra 1: Animações clássicas -> Preferência por DUBLADO
            if ($hasDub) {
                $score += 1000;
                $badge = '🇧🇷 DUB PT-BR ★';
                $prefMatch = true;
            } elseif ($hasSub) {
                $score += 300;
                $badge = 'LEGENDADO';
            } else {
                $score += 100;
                $badge = 'ÁUDIO ORIGINAL';
            }
        } else {
            // Regra 2: Filmes e Séries -> Preferência por ÁUDIO ORIGINAL com legendas em português (ou Dual)
            if ($hasOriginal && ($hasSub || preg_match('/(?:dual|multi|pt-br)/i', $lower))) {
                $score += 1000;
                $badge = 'ORIGINAL • LEG PT-BR ★';
                $prefMatch = true;
            } elseif ($hasSub) {
                $score += 800;
                $badge = 'ORIGINAL LEGENDADO';
                $prefMatch = true;
            } elseif (preg_match('/(?:dual|multi)/i', $lower)) {
                $score += 700;
                $badge = 'DUAL / MULTI ÁUDIO';
                $prefMatch = true;
            } elseif ($hasDub) {
                // Dublado puro tem menor pontuação para filmes/séries pois a preferência é áudio original
                $score += 200;
                $badge = 'DUBLADO';
            } else {
                $score += 400;
                $badge = 'ÁUDIO ORIGINAL HD';
            }
        }

        // Resolução e qualidade
        if (str_contains($lower, '1080p')) {
            $score += 150;
        } elseif (str_contains($lower, '720p')) {
            $score += 80;
        } elseif (str_contains($lower, '2160p') || str_contains($lower, '4k')) {
            $score += 50;
        }

        return [
            'score' => $score,
            'badge' => $badge,
            'preference_matched' => $prefMatch,
            'is_dubbed' => $hasDub,
            'is_subbed' => $hasSub,
        ];
    }

    /**
     * Consulta torrents abertos do Torrentio, pontua de acordo com preferências e cria magnet links.
     */
    protected function fetchPublicTorrentioStreams(
        string $imdbId,
        string $mediaType,
        int $season,
        int $episode,
        ?string $mediaTitle = null
    ): array {
        $streams = [];
        $queryPath = $mediaType === 'movie' ? "movie/{$imdbId}.json" : "series/{$imdbId}:{$season}:{$episode}.json";

        $trackers = [
            'udp://tracker.opentrackr.org:1337/announce',
            'udp://open.stealth.si:80/announce',
            'udp://tracker.torrent.eu.org:451/announce',
            'udp://tracker.openbittorrent.com:6969/announce',
            'udp://exodus.desync.com:6969/announce',
        ];
        $trParams = implode('', array_map(fn ($tr) => '&tr='.rawurlencode($tr), $trackers));

        try {
            $url = "https://torrentio.strem.fun/stream/{$queryPath}";
            $response = Http::timeout(6)->get($url);

            if ($response->successful()) {
                $rawStreams = $response->json('streams') ?? [];

                foreach ($rawStreams as $idx => $s) {
                    $infoHash = $s['infoHash'] ?? null;
                    if (empty($infoHash)) {
                        continue;
                    }

                    $titleLines = explode("\n", $s['title'] ?? '');
                    $cleanTitle = $titleLines[0] ?? 'HD Stream';
                    $stats = $titleLines[1] ?? ($titleLines[0] ?? '');

                    // Avaliação de preferência (Dublado para animações / Áudio Original + Legendas para filmes e séries)
                    $eval = $this->evaluateTorrentPreference($s['title'] ?? '', $mediaType);
                    $score = $eval['score'];

                    // Bônus de seeds
                    $seeds = 0;
                    if (preg_match('/👤\s*(\d+)/u', $stats, $mSeed)) {
                        $seeds = (int) $mSeed[1];
                        $score += min($seeds, 100) * 2;
                    }

                    $dnName = $mediaTitle ? "{$mediaTitle} - {$cleanTitle}" : $cleanTitle;
                    $magnet = "magnet:?xt=urn:btih:{$infoHash}&dn=".rawurlencode($dnName).$trParams;

                    $streams[] = [
                        'id' => "torrentio-{$idx}",
                        'name' => 'qBittorrent (P2P Local)',
                        'title' => "{$cleanTitle} ({$stats})",
                        'infoHash' => strtolower($infoHash),
                        'magnet' => $magnet,
                        'fileIdx' => $s['fileIdx'] ?? 0,
                        'url' => ! empty($s['url']) ? $s['url'] : '',
                        'badge' => $eval['badge'],
                        'score' => $score,
                        'seeds' => $seeds,
                        'preference_matched' => $eval['preference_matched'],
                        'isDirectVideo' => ! empty($s['url']),
                        'canDownloadToNas' => true,
                    ];
                }

                // Ordena streams em ordem decrescente de pontuação de preferência + seeds
                usort($streams, fn ($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));

                // Retorna os top 8 melhores
                $streams = array_slice($streams, 0, 8);
            }
        } catch (\Throwable $e) {
            Log::warning('Erro ao consultar Torrentio público: '.$e->getMessage());
        }

        return $streams;
    }
}
