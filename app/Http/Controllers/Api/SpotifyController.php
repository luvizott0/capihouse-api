<?php

namespace App\Http\Controllers\Api;

use App\Events\PostCreated;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Hashtag;
use App\Models\Post;
use App\Models\User;
use App\Services\LastFmService;
use App\Services\SpotifyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class SpotifyController extends Controller
{
    public function __construct(
        protected SpotifyService $spotifyService,
        protected LastFmService $lastFmService
    ) {}

    /**
     * Retorna a URL para redirecionar o usuário para a autorização no Spotify.
     */
    public function authUrl(Request $request): JsonResponse
    {
        if (! $this->spotifyService->isConfigured()) {
            return response()->json([
                'message' => 'A integração com o Spotify ainda não foi configurada nas credenciais do servidor.',
            ], 422);
        }

        $frontendUrl = $request->query('frontend_url') ?? $request->header('origin') ?? config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:5173'));
        if ($frontendUrl && str_starts_with($frontendUrl, 'http')) {
            $parts = parse_url($frontendUrl);
            if (isset($parts['scheme']) && isset($parts['host'])) {
                $frontendUrl = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
            }
        }
        $frontendUrl = rtrim((string) $frontendUrl, '/');

        $url = $this->spotifyService->getAuthUrl($request->user()->id, $frontendUrl);

        return response()->json([
            'url' => $url,
        ]);
    }

    /**
     * Processa o retorno OAuth do Spotify.
     */
    public function callback(Request $request): JsonResponse|RedirectResponse
    {
        $code = $request->query('code') ?? $request->input('code');
        $state = $request->query('state') ?? $request->input('state');
        $error = $request->query('error') ?? $request->input('error');

        $defaultFrontend = rtrim(config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:5173')), '/');
        $frontendUrl = $defaultFrontend;

        $stateData = null;
        if ($state) {
            $stateData = $this->spotifyService->validateState($state);
            if ($stateData && ! empty($stateData['frontend_url'])) {
                $frontendUrl = rtrim((string) $stateData['frontend_url'], '/');
            }
        }

        if ($error || ! $code || ! $state) {
            if ($request->wantsJson()) {
                return response()->json([
                    'message' => 'Autorização cancelada ou recusada pelo Spotify.',
                ], 400);
            }

            return redirect("{$frontendUrl}/profile?spotify_error=access_denied");
        }

        if (! $stateData) {
            if ($request->wantsJson()) {
                return response()->json([
                    'message' => 'Sessão de autorização inválida ou expirada. Tente novamente.',
                ], 400);
            }

            return redirect("{$frontendUrl}/profile?spotify_error=invalid_state");
        }

        $user = User::find($stateData['user_id']);
        if (! $user) {
            if ($request->wantsJson()) {
                return response()->json([
                    'message' => 'Usuário não encontrado.',
                ], 404);
            }

            return redirect("{$frontendUrl}/profile?spotify_error=user_not_found");
        }

        $tokens = $this->spotifyService->handleCallback($code);
        if (! $tokens) {
            if ($request->wantsJson()) {
                return response()->json([
                    'message' => 'Falha ao trocar código de autorização pelos tokens do Spotify.',
                ], 500);
            }

            return redirect("{$frontendUrl}/profile?spotify_error=token_exchange_failed");
        }

        $user->update($tokens);

        // Limpa cache de status anterior
        Cache::forget("spotify_now_playing_{$user->id}");
        Cache::forget("user_spotify_track_{$user->id}");

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Conta do Spotify conectada com sucesso!',
                'user' => new UserResource($user->fresh()),
            ]);
        }

        return redirect("{$frontendUrl}/profile?spotify=connected");
    }

    /**
     * Desconecta a conta do Spotify do usuário autenticado.
     */
    public function disconnect(Request $request): JsonResponse
    {
        $user = $request->user();

        $user->update([
            'spotify_id' => null,
            'spotify_access_token' => null,
            'spotify_refresh_token' => null,
            'spotify_token_expires_at' => null,
            'spotify_avatar_url' => null,
            'spotify_profile_url' => null,
            'spotify_display_name' => null,
        ]);

        Cache::forget("spotify_now_playing_{$user->id}");
        Cache::forget("user_spotify_track_{$user->id}");

        return response()->json([
            'message' => 'Conta do Spotify desconectada com sucesso.',
            'user' => new UserResource($user->fresh()),
        ]);
    }

    /**
     * Pesquisa faixas no catálogo do Spotify por nome.
     */
    public function search(Request $request): JsonResponse
    {
        $query = $request->query('q', '');
        if (mb_strlen(trim($query)) < 2) {
            return response()->json([
                'tracks' => [],
            ]);
        }

        $tracks = $this->spotifyService->searchTracks($query, 10);

        return response()->json([
            'tracks' => $tracks,
        ]);
    }

    /**
     * Define ou atualiza a música favorita fixada no perfil do usuário.
     */
    public function updateFavoriteMusic(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id' => 'nullable|string|max:255',
            'title' => 'required|string|max:255',
            'artist' => 'required|string|max:255',
            'album' => 'nullable|string|max:255',
            'album_art' => 'nullable|string|max:1000',
            'spotify_url' => 'nullable|string|max:1000',
            'preview_url' => 'nullable|string|max:1000',
            'duration_ms' => 'nullable|integer',
        ], [
            'title.required' => 'O título da música é obrigatório.',
            'artist.required' => 'O nome do artista é obrigatório.',
        ]);

        $user = $request->user();
        $user->update([
            'favorite_music' => $validated,
        ]);

        return response()->json([
            'message' => 'Música do perfil atualizada com sucesso!',
            'user' => new UserResource($user->fresh()),
        ]);
    }

    /**
     * Remove a música favorita fixada no perfil.
     */
    public function removeFavoriteMusic(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->update([
            'favorite_music' => null,
        ]);

        return response()->json([
            'message' => 'Música do perfil removida com sucesso.',
            'user' => new UserResource($user->fresh()),
        ]);
    }

    /**
     * Retorna a faixa tocando no momento ou recente para o perfil especificado (Spotify ou Last.fm).
     */
    public function currentlyPlaying(User $user): JsonResponse
    {
        // 1. Se tiver Spotify conectado, prioriza Spotify
        if ($user->hasSpotifyConnected()) {
            $spotifyData = $this->spotifyService->getCurrentlyPlaying($user);

            // Se o Spotify estiver tocando no momento, retorna direto
            if (! empty($spotifyData['is_playing'])) {
                $spotifyData['source'] = 'spotify';
                $spotifyData['has_lastfm'] = $user->hasLastFmConnected();

                return response()->json($spotifyData);
            }

            // Se o Spotify não está tocando, mas o usuário tem Last.fm, verifica se o Last.fm está tocando agora
            if ($user->hasLastFmConnected()) {
                $lastfmData = $this->lastFmService->getCurrentlyPlaying($user);
                if (! empty($lastfmData['is_playing'])) {
                    return response()->json($lastfmData);
                }
            }

            // Se o Spotify possui registro de música recente
            if (! empty($spotifyData['title'])) {
                $spotifyData['source'] = 'spotify';
                $spotifyData['has_lastfm'] = $user->hasLastFmConnected();

                return response()->json($spotifyData);
            }

            // Se o Spotify não retornou música recente, mas o Last.fm possui
            if ($user->hasLastFmConnected() && isset($lastfmData) && ! empty($lastfmData['title'])) {
                return response()->json($lastfmData);
            }

            $spotifyData['source'] = 'spotify';
            $spotifyData['has_lastfm'] = $user->hasLastFmConnected();

            return response()->json($spotifyData);
        }

        // 2. Se não tem Spotify conectado, mas tem Last.fm conectado
        if ($user->hasLastFmConnected()) {
            $lastfmData = $this->lastFmService->getCurrentlyPlaying($user);

            return response()->json($lastfmData);
        }

        // 3. Nenhum provedor de música conectado
        return response()->json([
            'is_playing' => false,
            'is_recent' => false,
            'has_spotify' => false,
            'has_lastfm' => false,
        ]);
    }

    /**
     * Publica uma música do Spotify ou Last.fm como um post no feed (repost/compartilhamento de música).
     */
    public function repostMusic(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'track' => 'required|array',
            'track.title' => 'required|string|max:255',
            'track.artist' => 'required|string|max:255',
            'track.album' => 'nullable|string|max:255',
            'track.album_art' => 'nullable|string|max:1000',
            'track.spotify_url' => 'nullable|string|max:1000',
            'track.preview_url' => 'nullable|string|max:1000',
            'track.duration_ms' => 'nullable|integer',
            'track.id' => 'nullable|string|max:255',
            'track.source' => 'nullable|string|in:spotify,lastfm',
            'source' => 'nullable|string|in:spotify,lastfm',
            'content' => 'nullable|string|max:2000',
            'feeling_name' => 'nullable|string|max:15',
            'feeling_emoji' => 'nullable|string|max:32',
            'hashtags' => 'nullable|array',
            'hashtags.*' => 'string|max:50',
            'from_user' => 'nullable|string|max:255',
        ]);

        $user = $request->user();
        $track = $validated['track'];
        $trackId = $track['id'] ?? Str::uuid()->toString();
        $source = $validated['source'] ?? ($track['source'] ?? 'spotify');

        $post = Post::create([
            'user_id' => $user->id,
            'category' => 'feed',
            'entertainment_type' => 'music',
            'external_source' => $source,
            'external_id' => $source.':'.$trackId.':'.now()->timestamp,
            'content' => $validated['content'] ?? null,
            'metadata' => [
                'track_id' => $trackId,
                'track_title' => $track['title'],
                'artist' => $track['artist'],
                'track_artist' => $track['artist'],
                'album' => $track['album'] ?? '',
                'track_album' => $track['album'] ?? '',
                'album_art' => $track['album_art'] ?? null,
                'spotify_url' => $track['spotify_url'] ?? null,
                'preview_url' => $track['preview_url'] ?? null,
                'duration_ms' => $track['duration_ms'] ?? 0,
                'reposted_from' => $validated['from_user'] ?? null,
            ],
            'watched_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (! empty($validated['feeling_name'])) {
            $post->feeling()->create([
                'name' => $validated['feeling_name'],
                'emoji' => $validated['feeling_emoji'] ?? '🎵',
            ]);
        }

        if (! empty($validated['hashtags'])) {
            foreach ($validated['hashtags'] as $tag) {
                $hashtag = Hashtag::firstOrCreate(['name' => ltrim($tag, '#')]);
                $post->hashtags()->attach($hashtag->id);
            }
        }

        try {
            $post->load([
                'user',
                'media',
                'feeling',
                'hashtags',
                'mentions',
                'comments.user',
                'comments.parent.user',
            ]);
            $post->is_liked = false;
            $post->likes_count = 0;
            $post->comments_count = 0;
            broadcast(new PostCreated($post));
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json([
            'message' => 'Música compartilhada no feed com sucesso!',
            'post' => $post,
        ], 201);
    }
}
