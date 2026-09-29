<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Services\LastFmService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class LastFmController extends Controller
{
    public function __construct(
        protected LastFmService $lastFmService
    ) {}

    /**
     * Vincula a conta do Last.fm ao perfil do usuário autenticado.
     */
    public function connect(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'username' => 'required|string|min:1|max:100',
        ], [
            'username.required' => 'Informe seu nome de usuário do Last.fm.',
            'username.max' => 'O nome de usuário pode ter no máximo 100 caracteres.',
        ]);

        $username = trim(ltrim($validated['username'], '@'));

        if (! $this->lastFmService->verifyUser($username)) {
            return response()->json([
                'message' => "Não encontramos o usuário '{$username}' no Last.fm. Verifique a grafia e tente novamente.",
            ], 422);
        }

        $user = $request->user();
        $user->update([
            'lastfm_username' => $username,
        ]);

        Cache::forget("lastfm_now_playing_{$user->id}");
        Cache::forget("user_spotify_track_{$user->id}");

        return response()->json([
            'message' => "Conta @{$username} do Last.fm conectada com sucesso!",
            'user' => new UserResource($user->fresh()),
        ]);
    }

    /**
     * Desconecta a conta do Last.fm do usuário autenticado.
     */
    public function disconnect(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->update([
            'lastfm_username' => null,
        ]);

        Cache::forget("lastfm_now_playing_{$user->id}");
        Cache::forget("user_spotify_track_{$user->id}");

        return response()->json([
            'message' => 'Conta do Last.fm desconectada com sucesso.',
            'user' => new UserResource($user->fresh()),
        ]);
    }
}
