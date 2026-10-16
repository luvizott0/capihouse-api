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
        $preferredAudio = $params['preferred_audio'] ?? null;
        $isAnimation = filter_var($params['is_animation'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $genres = (array) ($params['genres'] ?? []);
        foreach ($genres as $g) {
            $gUpper = strtoupper((string) $g);
            if (str_contains($gUpper, 'ANIMA') || str_contains($gUpper, 'CARTOON') || str_contains($gUpper, 'ANIME')) {
                $isAnimation = true;
                break;
            }
        }
        if (in_array($mediaType, ['anime', 'cartoon'])) {
            $isAnimation = true;
        }

        $includeP2p = filter_var($params['include_p2p'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $blockForeign = filter_var($params['block_foreign'] ?? false, FILTER_VALIDATE_BOOLEAN);

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
                    'has_portuguese' => true,
                    'audio_type' => 'local',
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
            $debridStreams = $this->fetchDebridStreams($imdbId, $mediaType, $season, $episode, $provider, $token);
            $streams = array_merge($streams, $debridStreams);
        }

        // 2. Consulta índices abertos para P2P / qBittorrent se solicitado ou se não houver streams debrid
        if ($includeP2p || count($streams) <= 1) {
            $p2pStreams = $this->fetchPublicTorrentioStreams(
                $imdbId,
                $mediaType,
                $season,
                $episode,
                $title,
                $isAnimation,
                $preferredAudio,
                $blockForeign
            );
            $streams = array_merge($streams, $p2pStreams);
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
     * - Animações / Desenhos / Animes: preferência por ÁUDIO DUBLADO (PT-BR), depois legendado PT-BR.
     * - Filmes e Séries Live-Action: preferência por ÁUDIO ORIGINAL com legendas em português (ou Dual).
     * - Suporta seleção explícita: 'dub' (apenas/preferência dublado) ou 'sub' (apenas/preferência legendado).
     * - Penaliza severamente releases em inglês puro sem legendas ou áudio em português.
     */
    public function evaluateTorrentPreference(
        string $rawTitle,
        string $mediaType,
        bool $isAnimation = false,
        ?string $preferredAudio = null
    ): array {
        $lower = mb_strtolower($rawTitle, 'UTF-8');
        $score = 0;

        $isAnimation = $isAnimation || in_array($mediaType, ['anime', 'cartoon']);

        $hasDub = (bool) preg_match('/(?:dublado|dub\b|dual[\s\.\-_]*[aá]udio|dual|pt-br|ptbr|brazilian|nacional|🇧🇷)/i', $lower);

        // Evita falsos positivos como "Rus Sub", "Ita Sub", "Fre Sub" serem considerados legendas em português
        $isForeignSubOnly = (bool) preg_match('/(?:rus|russian|ita|italian|fre|french|ger|german|spa|spanish|kor|chi|polish|pol)\s*sub/i', $lower);
        $hasPtSub = (bool) preg_match('/(?:legendado|leg\s*pt|sub\s*pt|legendas?(?:\s+em)?\s+pt|pt-br\s*sub|sub\s*pt-br|pob\b|🇵🇹|🇧🇷|portuguese\s*(?:sub|legend))/iu', $rawTitle);
        $hasGenericSub = ((bool) preg_match('/(?:legendado|leg\b|subbed|legendas)/i', $lower)) && ! $isForeignSubOnly;
        $hasSub = $hasPtSub || $hasGenericSub;

        $hasPortuguese = $hasDub || $hasPtSub;
        $badge = 'P2P TORRENT';
        $prefMatch = false;

        // Se o usuário especificou preferência explícita
        if ($preferredAudio === 'dub') {
            if ($hasDub) {
                $score += 2500;
                $badge = '🇧🇷 DUB PT-BR ★';
                $prefMatch = true;
            } elseif ($hasPtSub) {
                $score += 400;
                $badge = '💬 LEGENDADO PT-BR';
            } else {
                $score -= 4000;
                $badge = '⚠️ INGLÊS (SEM LEG PT)';
            }
        } elseif ($preferredAudio === 'sub') {
            if ($hasPtSub) {
                $score += 2500;
                $badge = '💬 LEGENDADO PT-BR ★';
                $prefMatch = true;
            } elseif ($hasDub) {
                $score += 600;
                $badge = '🇧🇷 DUB PT-BR';
            } else {
                $score -= 4000;
                $badge = '⚠️ INGLÊS (SEM LEG PT)';
            }
        } elseif ($isAnimation) {
            // Regra 1: Animações -> Preferência máxima por DUBLADO PT-BR, depois LEGENDADO PT-BR
            if ($hasDub) {
                $score += 2000;
                $badge = '🇧🇷 DUB PT-BR ★';
                $prefMatch = true;
            } elseif ($hasPtSub) {
                $score += 1200;
                $badge = '💬 LEGENDADO PT-BR ★';
                $prefMatch = true;
            } else {
                $score -= 3500;
                $badge = '⚠️ INGLÊS (SEM LEG PT)';
            }
        } else {
            // Regra 2: Filmes e Séries Live-Action -> Preferência por ÁUDIO ORIGINAL com legendas em português (ou Dual)
            if ($hasPtSub) {
                $score += 1800;
                $badge = '💬 ORIGINAL • LEG PT-BR ★';
                $prefMatch = true;
            } elseif ($hasDub) {
                $score += 1400;
                $badge = '🇧🇷 DUBLADO / DUAL ★';
                $prefMatch = true;
            } else {
                $score -= 3500;
                $badge = '⚠️ INGLÊS (SEM LEG PT)';
            }
        }

        // Bônus de resolução
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
            'has_portuguese' => $hasPortuguese,
            'audio_type' => $hasDub ? 'dub' : ($hasPtSub ? 'sub' : 'foreign'),
            'is_dubbed' => $hasDub,
            'is_subbed' => $hasSub,
            'is_subbed_pt' => $hasPtSub,
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
        ?string $mediaTitle = null,
        bool $isAnimation = false,
        ?string $preferredAudio = null,
        bool $blockForeign = false
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

                    $rawTitle = $s['title'] ?? '';
                    $titleLines = explode("\n", $rawTitle);
                    $cleanTitle = $titleLines[0] ?? 'HD Stream';

                    // Encontra a linha de estatísticas (seeds, tamanho)
                    $stats = '';
                    foreach ($titleLines as $line) {
                        if (str_contains($line, '👤') || str_contains($line, '💾') || str_contains($line, '⚙️')) {
                            $stats = $line;
                            break;
                        }
                    }
                    if (empty($stats)) {
                        $stats = $titleLines[1] ?? ($titleLines[0] ?? '');
                    }

                    // Extração de seeds do título inteiro
                    $seeds = 0;
                    if (preg_match('/👤\s*(\d+)/u', $rawTitle, $mSeed)) {
                        $seeds = (int) $mSeed[1];
                    }

                    // Avaliação de preferência (Dublado para animações / Áudio Original + Legendas para filmes e séries)
                    $eval = $this->evaluateTorrentPreference($rawTitle, $mediaType, $isAnimation, $preferredAudio);
                    $score = $eval['score'];

                    // Ponderação de Seeds:
                    // Torrents com 0 seeds são severamente penalizados (evita torrents mortos congelados)
                    if ($seeds === 0) {
                        $score -= 3000;
                    } else {
                        // Bônus significativo por seeds ativos (até 1500 pts adicionais)
                        $score += min($seeds, 100) * 15;
                    }

                    $dnName = $mediaTitle ? "{$mediaTitle} - {$cleanTitle}" : $cleanTitle;
                    $magnet = "magnet:?xt=urn:btih:{$infoHash}&dn=".rawurlencode($dnName).$trParams;

                    $streams[] = [
                        'id' => "torrentio-{$idx}",
                        'name' => 'qBittorrent (P2P Local)',
                        'title' => "{$cleanTitle} ({$stats})",
                        'clean_name' => $cleanTitle,
                        'raw_title' => $rawTitle,
                        'stats' => $stats,
                        'infoHash' => strtolower($infoHash),
                        'magnet' => $magnet,
                        'fileIdx' => $s['fileIdx'] ?? 0,
                        'url' => ! empty($s['url']) ? $s['url'] : '',
                        'badge' => $eval['badge'],
                        'score' => $score,
                        'seeds' => $seeds,
                        'audio_type' => $eval['audio_type'],
                        'has_portuguese' => $eval['has_portuguese'],
                        'is_dubbed' => $eval['is_dubbed'],
                        'is_subbed' => $eval['is_subbed'],
                        'preference_matched' => $eval['preference_matched'],
                        'isDirectVideo' => ! empty($s['url']),
                        'canDownloadToNas' => true,
                    ];
                }

                // Se houver streams com português e o bloqueio estiver ativo, remove versões sem português
                if ($blockForeign) {
                    $ptStreams = array_filter($streams, fn ($st) => ($st['has_portuguese'] ?? false) === true);
                    if (! empty($ptStreams)) {
                        $streams = $ptStreams;
                    }
                }

                // Ordena streams em ordem decrescente de pontuação de preferência + seeds
                usort($streams, fn ($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));

                // Retorna os top 12 melhores
                $streams = array_slice($streams, 0, 12);
            }
        } catch (\Throwable $e) {
            Log::warning('Erro ao consultar Torrentio público: '.$e->getMessage());
        }

        return $streams;
    }
}
