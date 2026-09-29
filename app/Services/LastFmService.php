<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LastFmService
{
    protected ?string $apiKey;

    protected string $apiUrl = 'https://ws.audioscrobbler.com/2.0/';

    public function __construct(
        protected ?SpotifyService $spotifyService = null
    ) {
        $this->apiKey = config('services.lastfm.api_key');
    }

    /**
     * Verifica se a chave de API do Last.fm está configurada.
     */
    public function isConfigured(): bool
    {
        return ! empty($this->apiKey);
    }

    /**
     * Valida se um usuário existe no Last.fm.
     */
    public function verifyUser(string $username): bool
    {
        $username = trim($username);
        if (empty($username)) {
            return false;
        }

        if (! $this->isConfigured()) {
            // Se a chave não estiver configurada no backend, aceita o nome sem validação de rede
            return true;
        }

        try {
            $response = Http::withUserAgent('CapiHouse/1.0 (+https://capihouse.app)')
                ->timeout(4)
                ->get($this->apiUrl, [
                    'method' => 'user.getinfo',
                    'user' => $username,
                    'api_key' => $this->apiKey,
                    'format' => 'json',
                ]);

            if ($response->successful()) {
                $error = $response->json('error');

                return empty($error);
            }

            return false;
        } catch (Exception $e) {
            Log::warning("Erro ao validar usuário Last.fm '{$username}': ".$e->getMessage());

            return true;
        }
    }

    /**
     * Obtém a faixa tocando agora ou recentemente via Last.fm.
     */
    public function getCurrentlyPlaying(User $user): array
    {
        if (! $user->hasLastFmConnected()) {
            return ['is_playing' => false, 'has_lastfm' => false];
        }

        $cacheKey = "lastfm_now_playing_{$user->id}";

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        if (! $this->isConfigured()) {
            $payload = [
                'is_playing' => false,
                'is_recent' => false,
                'has_lastfm' => true,
                'error' => 'API do Last.fm não configurada no servidor.',
            ];
            Cache::put($cacheKey, $payload, 30);

            return $payload;
        }

        try {
            $response = Http::withUserAgent('CapiHouse/1.0 (+https://capihouse.app)')
                ->timeout(4)
                ->get($this->apiUrl, [
                    'method' => 'user.getrecenttracks',
                    'user' => $user->lastfm_username,
                    'api_key' => $this->apiKey,
                    'format' => 'json',
                    'limit' => 2,
                ]);

            if (! $response->successful()) {
                Log::warning("Erro na API do Last.fm para usuário #{$user->id} ({$user->lastfm_username}): ".$response->body());
                $payload = ['is_playing' => false, 'has_lastfm' => true];
                Cache::put($cacheKey, $payload, 15);

                return $payload;
            }

            $rawTracks = $response->json('recenttracks.track');
            if (empty($rawTracks)) {
                $payload = [
                    'is_playing' => false,
                    'is_recent' => false,
                    'has_lastfm' => true,
                ];
                Cache::put($cacheKey, $payload, 15);

                return $payload;
            }

            // Normaliza para array de itens
            $tracks = isset($rawTracks['name']) ? [$rawTracks] : $rawTracks;
            $first = $tracks[0] ?? null;

            if (! $first || empty($first['name'])) {
                $payload = [
                    'is_playing' => false,
                    'is_recent' => false,
                    'has_lastfm' => true,
                ];
                Cache::put($cacheKey, $payload, 15);

                return $payload;
            }

            $isNowPlaying = isset($first['@attr']['nowplaying']) && $first['@attr']['nowplaying'] === 'true';

            $artistName = '';
            if (isset($first['artist']['#text'])) {
                $artistName = (string) $first['artist']['#text'];
            } elseif (is_string($first['artist'] ?? null)) {
                $artistName = $first['artist'];
            }

            $albumName = '';
            if (isset($first['album']['#text'])) {
                $albumName = (string) $first['album']['#text'];
            }

            $albumArt = null;
            if (! empty($first['image']) && is_array($first['image'])) {
                // Tenta extralarge, depois large, depois medium
                $imagesBySize = [];
                foreach ($first['image'] as $img) {
                    if (isset($img['size']) && ! empty($img['#text'])) {
                        $imagesBySize[$img['size']] = $img['#text'];
                    }
                }
                $albumArt = $imagesBySize['extralarge'] ?? $imagesBySize['large'] ?? $imagesBySize['medium'] ?? null;

                // Remove imagem padrão placeholder do Last.fm se for vazia/inválida
                if ($albumArt && str_contains($albumArt, '2a96cbd8b46e442fc41c2b86b821562f')) {
                    $albumArt = null;
                }
            }

            $trackUrl = $first['url'] ?? null;
            $playedAt = null;
            if (! $isNowPlaying && isset($first['date']['uts'])) {
                $playedAt = Carbon::createFromTimestamp((int) $first['date']['uts'])->toIso8601String();
            }

            // Resolve metadados estendidos (duração e link oficial)
            $details = $this->resolveTrackDetails($first['name'], $artistName);
            $durationMs = $details['duration_ms'] ?? 210000;
            if (! empty($details['album_art']) && empty($albumArt)) {
                $albumArt = $details['album_art'];
            }
            $spotifyUrl = $details['spotify_url'] ?? $trackUrl;

            // Estimativa inteligente de progresso em tempo real
            $progressMs = 0;
            if ($isNowPlaying) {
                $trackKey = md5(mb_strtolower($artistName).'_'.mb_strtolower($first['name']));
                $sessionKey = "user_music_session_{$user->id}";
                $session = Cache::get($sessionKey);

                if (is_array($session) && ($session['track_key'] ?? null) === $trackKey && ! empty($session['started_at'])) {
                    $startedAt = (int) $session['started_at'];
                } else {
                    $startedAt = now()->timestamp;
                    Cache::put($sessionKey, [
                        'track_key' => $trackKey,
                        'started_at' => $startedAt,
                    ], 600);
                }

                $elapsedMs = (now()->timestamp - $startedAt) * 1000;
                $progressMs = min($durationMs, max(0, $elapsedMs));
            } else {
                Cache::forget("user_music_session_{$user->id}");
            }

            $trackData = [
                'is_playing' => $isNowPlaying,
                'is_recent' => ! $isNowPlaying,
                'has_spotify' => false,
                'has_lastfm' => true,
                'source' => 'lastfm',
                'track_id' => 'lastfm:'.md5($artistName.'_'.$first['name']),
                'title' => $first['name'],
                'artist' => $artistName,
                'album' => $albumName,
                'album_art' => $albumArt,
                'spotify_url' => $spotifyUrl,
                'url' => $trackUrl,
                'duration_ms' => $durationMs,
                'progress_ms' => $progressMs,
                'played_at' => $playedAt,
                'fetched_at' => now()->timestamp,
            ];

            Cache::put($cacheKey, $trackData, 12);

            if ($isNowPlaying) {
                Cache::put("user_spotify_track_{$user->id}", [
                    'is_playing' => true,
                    'source' => 'lastfm',
                    'title' => $trackData['title'],
                    'artist' => $trackData['artist'],
                    'spotify_url' => $trackData['url'],
                ], 45);
            } else {
                Cache::forget("user_spotify_track_{$user->id}");
            }

            return $trackData;
        } catch (Exception $e) {
            Log::warning("Erro ao consultar Last.fm para usuário #{$user->id}: ".$e->getMessage());
            $payload = ['is_playing' => false, 'has_lastfm' => true];
            Cache::put($cacheKey, $payload, 15);

            return $payload;
        }
    }

    /**
     * Resolve detalhes estendidos da faixa (duração, capa em alta resolução e link).
     */
    public function resolveTrackDetails(string $title, string $artist): array
    {
        $cacheKey = 'music_meta_'.md5(mb_strtolower($artist).'_'.mb_strtolower($title));

        return Cache::remember($cacheKey, 604800, function () use ($title, $artist) {
            $durationMs = null;
            $albumArt = null;
            $spotifyUrl = null;

            // 1. Tenta consultar via busca pública do catálogo Spotify (Client Credentials sem limites de usuários)
            if ($this->spotifyService && $this->spotifyService->isConfigured()) {
                try {
                    $spotifyTracks = $this->spotifyService->searchTracks("{$title} {$artist}", 1);
                    if (! empty($spotifyTracks[0])) {
                        $st = $spotifyTracks[0];
                        if (! empty($st['duration_ms']) && $st['duration_ms'] > 0) {
                            $durationMs = (int) $st['duration_ms'];
                        }
                        if (! empty($st['album_art'])) {
                            $albumArt = $st['album_art'];
                        }
                        if (! empty($st['spotify_url'])) {
                            $spotifyUrl = $st['spotify_url'];
                        }
                    }
                } catch (\Throwable $e) {
                    // Silencioso
                }
            }

            // 2. Se não obtiver duração, tenta buscar via Last.fm track.getInfo
            if (! $durationMs && $this->isConfigured()) {
                try {
                    $response = Http::withUserAgent('CapiHouse/1.0 (+https://capihouse.app)')
                        ->timeout(3)
                        ->get($this->apiUrl, [
                            'method' => 'track.getInfo',
                            'artist' => $artist,
                            'track' => $title,
                            'api_key' => $this->apiKey,
                            'format' => 'json',
                        ]);

                    if ($response->successful()) {
                        $rawDur = (int) $response->json('track.duration');
                        if ($rawDur > 0) {
                            $durationMs = $rawDur;
                        }
                    }
                } catch (\Throwable $e) {
                    // Silencioso
                }
            }

            // Fallback para duração padrão estimada (3 min e 30 seg)
            if (! $durationMs || $durationMs <= 0) {
                $durationMs = 210000;
            }

            return [
                'duration_ms' => $durationMs,
                'album_art' => $albumArt,
                'spotify_url' => $spotifyUrl,
            ];
        });
    }
}
