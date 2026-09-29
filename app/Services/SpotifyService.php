<?php

namespace App\Services;

use App\Models\User;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SpotifyService
{
    protected ?string $clientId;

    protected ?string $clientSecret;

    protected ?string $redirectUri;

    public function __construct()
    {
        $this->clientId = config('services.spotify.client_id');
        $this->clientSecret = config('services.spotify.client_secret');
        $this->redirectUri = config('services.spotify.redirect_uri');
    }

    /**
     * Verifica se as credenciais do aplicativo Spotify estão configuradas.
     */
    public function isConfigured(): bool
    {
        return ! empty($this->clientId) && ! empty($this->clientSecret);
    }

    /**
     * Gera a URL para autorização OAuth 2.0 do Spotify.
     */
    public function getAuthUrl(int $userId): string
    {
        $state = encrypt([
            'user_id' => $userId,
            'timestamp' => now()->timestamp,
        ]);

        $query = http_build_query([
            'client_id' => $this->clientId,
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri,
            'scope' => 'user-read-currently-playing user-read-playback-state user-read-recently-played',
            'state' => $state,
            'show_dialog' => 'true',
        ]);

        return 'https://accounts.spotify.com/authorize?'.$query;
    }

    /**
     * Decodifica e valida o state recebido no callback.
     */
    public function validateState(string $state): ?array
    {
        try {
            $payload = decrypt($state);
            if (! is_array($payload) || ! isset($payload['user_id'])) {
                return null;
            }

            // State expira em 30 minutos
            if (isset($payload['timestamp']) && now()->timestamp - $payload['timestamp'] > 1800) {
                return null;
            }

            return $payload;
        } catch (Exception $e) {
            Log::warning('Erro ao descriptografar state do Spotify: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Processa a troca do código de autorização por tokens de acesso.
     */
    public function handleCallback(string $code): ?array
    {
        try {
            $response = Http::asForm()
                ->withBasicAuth($this->clientId, $this->clientSecret)
                ->timeout(5)
                ->post('https://accounts.spotify.com/api/token', [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $this->redirectUri,
                ]);

            if (! $response->successful()) {
                Log::error('Erro ao trocar authorization code do Spotify: '.$response->body());

                return null;
            }

            $tokenData = $response->json();
            $accessToken = $tokenData['access_token'] ?? null;
            $refreshToken = $tokenData['refresh_token'] ?? null;
            $expiresIn = $tokenData['expires_in'] ?? 3600;

            if (! $accessToken || ! $refreshToken) {
                return null;
            }

            // Busca perfil do usuário no Spotify
            $userProfileResponse = Http::withToken($accessToken)
                ->timeout(5)
                ->get('https://api.spotify.com/v1/me');

            $profileData = $userProfileResponse->successful() ? $userProfileResponse->json() : [];

            return [
                'spotify_id' => $profileData['id'] ?? null,
                'spotify_display_name' => $profileData['display_name'] ?? null,
                'spotify_avatar_url' => $profileData['images'][0]['url'] ?? null,
                'spotify_profile_url' => $profileData['external_urls']['spotify'] ?? null,
                'spotify_access_token' => $accessToken,
                'spotify_refresh_token' => $refreshToken,
                'spotify_token_expires_at' => now()->addSeconds($expiresIn),
            ];
        } catch (Exception $e) {
            Log::error('Exceção no callback do Spotify: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Obtém um token de acesso de usuário válido, renovando caso necessário.
     */
    public function getValidUserAccessToken(User $user): ?string
    {
        if (empty($user->spotify_refresh_token)) {
            return null;
        }

        // Se o token ainda é válido por pelo menos 1 minuto, use-o
        if ($user->spotify_access_token && $user->spotify_token_expires_at && $user->spotify_token_expires_at->gt(now()->addMinute())) {
            return $user->spotify_access_token;
        }

        // Renova o token
        try {
            $response = Http::asForm()
                ->withBasicAuth($this->clientId, $this->clientSecret)
                ->timeout(5)
                ->post('https://accounts.spotify.com/api/token', [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $user->spotify_refresh_token,
                ]);

            if (! $response->successful()) {
                Log::warning("Falha ao renovar token Spotify do usuário #{$user->id}: ".$response->body());

                return null;
            }

            $data = $response->json();
            $newAccessToken = $data['access_token'] ?? null;
            $expiresIn = $data['expires_in'] ?? 3600;

            $updateData = [
                'spotify_access_token' => $newAccessToken,
                'spotify_token_expires_at' => now()->addSeconds($expiresIn),
            ];

            // Alguns provedores retornam novo refresh token
            if (! empty($data['refresh_token'])) {
                $updateData['spotify_refresh_token'] = $data['refresh_token'];
            }

            $user->update($updateData);

            return $newAccessToken;
        } catch (Exception $e) {
            Log::error("Erro de conexão ao renovar token Spotify do usuário #{$user->id}: ".$e->getMessage());

            return null;
        }
    }

    /**
     * Obtém o token de aplicação (Client Credentials Flow) para buscas no catálogo público.
     */
    public function getAppAccessToken(): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        return Cache::remember('spotify_app_client_token', 3300, function () {
            try {
                $response = Http::asForm()
                    ->withBasicAuth($this->clientId, $this->clientSecret)
                    ->timeout(4)
                    ->post('https://accounts.spotify.com/api/token', [
                        'grant_type' => 'client_credentials',
                    ]);

                if ($response->successful()) {
                    return $response->json('access_token');
                }

                Log::warning('Erro ao obter token de aplicação do Spotify: '.$response->body());

                return null;
            } catch (Exception $e) {
                Log::error('Erro ao conectar ao Spotify para obter token de aplicação: '.$e->getMessage());

                return null;
            }
        });
    }

    /**
     * Pesquisa faixas no Spotify por nome/termo de busca.
     */
    public function searchTracks(string $query, int $limit = 10): array
    {
        $query = trim($query);
        if (empty($query)) {
            return [];
        }

        $appToken = $this->getAppAccessToken();
        if (! $appToken) {
            return [];
        }

        $safeLimit = min(max((int) $limit, 1), 10);
        $cacheKey = 'spotify_search_'.md5(mb_strtolower($query).'_'.$safeLimit);

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $appToken = $this->getAppAccessToken();
        if (! $appToken) {
            return [];
        }

        try {
            $response = Http::withToken($appToken)
                ->timeout(4)
                ->get('https://api.spotify.com/v1/search', [
                    'q' => $query,
                    'type' => 'track',
                    'limit' => $safeLimit,
                ]);

            if ($response->status() === 401) {
                Cache::forget('spotify_app_client_token');
                $appToken = $this->getAppAccessToken();
                if ($appToken) {
                    $response = Http::withToken($appToken)
                        ->timeout(4)
                        ->get('https://api.spotify.com/v1/search', [
                            'q' => $query,
                            'type' => 'track',
                            'limit' => $safeLimit,
                        ]);
                }
            }

            if (! $response || ! $response->successful()) {
                Log::warning('Erro na busca de faixas no Spotify: '.($response ? $response->body() : 'no response'));

                return [];
            }

            $items = $response->json('tracks.items') ?? [];

            $results = collect($items)->map(function ($item) {
                $artists = collect($item['artists'] ?? [])->pluck('name')->join(', ');
                $albumArt = $item['album']['images'][0]['url'] ?? null;
                if (isset($item['album']['images'][1])) {
                    // Prefere a imagem média (300x300) se disponível
                    $albumArt = $item['album']['images'][1]['url'];
                }

                return [
                    'id' => $item['id'],
                    'title' => $item['name'],
                    'artist' => $artists,
                    'album' => $item['album']['name'] ?? '',
                    'album_art' => $albumArt,
                    'spotify_url' => $item['external_urls']['spotify'] ?? null,
                    'duration_ms' => $item['duration_ms'] ?? 0,
                    'preview_url' => $item['preview_url'] ?? null,
                ];
            })->all();

            if (! empty($results)) {
                Cache::put($cacheKey, $results, 600);
            }

            return $results;
        } catch (Exception $e) {
            Log::warning('Erro na busca de faixas no Spotify: '.$e->getMessage());

            return [];
        }
    }

    /**
     * Obtém o que o usuário está ouvindo agora no Spotify (com cache leve).
     */
    public function getCurrentlyPlaying(User $user): array
    {
        if (! $user->hasSpotifyConnected()) {
            return ['is_playing' => false, 'has_spotify' => false];
        }

        $cacheKey = "spotify_now_playing_{$user->id}";

        // Se estiver em cache recente (10s), retorna direto sem chamada de rede externa
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $token = $this->getValidUserAccessToken($user);
        if (! $token) {
            $payload = ['is_playing' => false, 'has_spotify' => false];
            Cache::put($cacheKey, $payload, 15);
            Cache::forget("user_spotify_track_{$user->id}");

            return $payload;
        }

        try {
            $response = Http::withToken($token)
                ->timeout(2)
                ->get('https://api.spotify.com/v1/me/player/currently-playing');

            $data = $response->successful() ? $response->json() : null;
            $isPlaying = (bool) ($data['is_playing'] ?? false);
            $item = $data['item'] ?? null;

            if ($isPlaying && $item) {
                $artists = collect($item['artists'] ?? [])->pluck('name')->join(', ');
                $albumArt = $item['album']['images'][0]['url'] ?? null;
                if (isset($item['album']['images'][1])) {
                    $albumArt = $item['album']['images'][1]['url'];
                }

                $trackData = [
                    'is_playing' => true,
                    'is_recent' => false,
                    'has_spotify' => true,
                    'track_id' => $item['id'] ?? null,
                    'title' => $item['name'] ?? '',
                    'artist' => $artists,
                    'album' => $item['album']['name'] ?? '',
                    'album_art' => $albumArt,
                    'spotify_url' => $item['external_urls']['spotify'] ?? null,
                    'progress_ms' => $data['progress_ms'] ?? 0,
                    'duration_ms' => $item['duration_ms'] ?? 0,
                    'preview_url' => $item['preview_url'] ?? null,
                    'fetched_at' => now()->timestamp,
                ];

                Cache::put($cacheKey, $trackData, 10);

                // Disponibiliza para a listagem rápida de online users
                Cache::put("user_spotify_track_{$user->id}", [
                    'is_playing' => true,
                    'title' => $trackData['title'],
                    'artist' => $trackData['artist'],
                    'spotify_url' => $trackData['spotify_url'],
                ], 45);

                return $trackData;
            }

            // Não está tocando no momento: busca a última música tocada recentemente
            $recentResponse = Http::withToken($token)
                ->timeout(2)
                ->get('https://api.spotify.com/v1/me/player/recently-played', [
                    'limit' => 1,
                ]);

            if ($recentResponse->successful()) {
                $recentItem = $recentResponse->json('items.0');
                $recentTrack = $recentItem['track'] ?? null;

                if ($recentTrack) {
                    $artists = collect($recentTrack['artists'] ?? [])->pluck('name')->join(', ');
                    $albumArt = $recentTrack['album']['images'][0]['url'] ?? null;
                    if (isset($recentTrack['album']['images'][1])) {
                        $albumArt = $recentTrack['album']['images'][1]['url'];
                    }

                    $recentData = [
                        'is_playing' => false,
                        'is_recent' => true,
                        'has_spotify' => true,
                        'track_id' => $recentTrack['id'] ?? null,
                        'title' => $recentTrack['name'] ?? '',
                        'artist' => $artists,
                        'album' => $recentTrack['album']['name'] ?? '',
                        'album_art' => $albumArt,
                        'spotify_url' => $recentTrack['external_urls']['spotify'] ?? null,
                        'duration_ms' => $recentTrack['duration_ms'] ?? 0,
                        'played_at' => $recentItem['played_at'] ?? null,
                        'preview_url' => $recentTrack['preview_url'] ?? null,
                        'fetched_at' => now()->timestamp,
                    ];

                    Cache::put($cacheKey, $recentData, 15);
                    Cache::forget("user_spotify_track_{$user->id}");

                    return $recentData;
                }
            }

            // Nenhuma música recente encontrada (conta sem reproduções recentes)
            $payload = [
                'is_playing' => false,
                'is_recent' => false,
                'has_spotify' => true,
            ];
            Cache::put($cacheKey, $payload, 15);
            Cache::forget("user_spotify_track_{$user->id}");

            return $payload;
        } catch (Exception $e) {
            Log::warning("Erro ao buscar playback/recent Spotify do usuário #{$user->id}: ".$e->getMessage());
            $payload = ['is_playing' => false, 'has_spotify' => true];
            Cache::put($cacheKey, $payload, 15);

            return $payload;
        }
    }
}
