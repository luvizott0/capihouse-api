<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\SpotifyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SpotifyController extends Controller
{
    public function __construct(
        protected SpotifyService $spotifyService
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

        $url = $this->spotifyService->getAuthUrl($request->user()->id);

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

        $frontendUrl = env('FRONTEND_URL', 'http://localhost:5173');

        if ($error || ! $code || ! $state) {
            if ($request->wantsJson()) {
                return response()->json([
                    'message' => 'Autorização cancelada ou recusada pelo Spotify.',
                ], 400);
            }

            return redirect("{$frontendUrl}/profile?spotify_error=access_denied");
        }

        $stateData = $this->spotifyService->validateState($state);
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
     * Retorna a faixa tocando no momento para o perfil especificado.
     */
    public function currentlyPlaying(User $user): JsonResponse
    {
        $data = $this->spotifyService->getCurrentlyPlaying($user);

        return response()->json($data);
    }
}
