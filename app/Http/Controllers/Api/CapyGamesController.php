<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\CapyGame;
use App\Models\User;
use App\Services\CapyGamesService;
use App\Services\SteamSyncService;
use App\Services\XboxSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class CapyGamesController extends Controller
{
    public function __construct(
        protected CapyGamesService $gamesService,
        protected SteamSyncService $steamService,
        protected XboxSyncService $xboxService
    ) {}

    /**
     * Busca jogos no catálogo (Steam, Xbox e locais)
     */
    public function search(Request $request): JsonResponse
    {
        $query = (string) $request->input('q', '');
        $results = $this->gamesService->searchGames($query);

        return response()->json(['data' => $results]);
    }

    /**
     * Jogos populares / em destaque
     */
    public function popular(): JsonResponse
    {
        $results = $this->gamesService->getPopularGames();

        return response()->json(['data' => $results]);
    }

    /**
     * Detalhes de um jogo específico e amigos da comunidade
     */
    public function showGame(int $id, Request $request): JsonResponse
    {
        $details = $this->gamesService->getGameDetails($id, $request->user());

        return response()->json($details);
    }

    /**
     * Minha biblioteca de jogos
     */
    public function myLibrary(Request $request): JsonResponse
    {
        $status = $request->input('status');
        $platform = $request->input('platform');

        $library = $this->gamesService->getUserLibrary($request->user()->id, $status, $platform);

        return response()->json(['data' => $library]);
    }

    /**
     * Define ou atualiza status, nota, horas e review de um jogo
     */
    public function setStatus(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'game_id' => 'nullable|integer',
            'external_id' => 'nullable|string',
            'external_source' => 'nullable|string',
            'title' => 'nullable|string',
            'cover_url' => 'nullable|string',
            'status' => 'required|string|in:plan_to_play,playing,completed,mastered,dropped,on_hold',
            'platform' => 'nullable|string|max:100',
            'hours_played' => 'nullable|numeric|min:0|max:10000',
            'rating' => 'nullable|numeric|min:0|max:10',
            'review' => 'nullable|string|max:5000',
            'is_favorite' => 'nullable|boolean',
        ]);

        $game = null;
        if (! empty($validated['game_id'])) {
            $game = CapyGame::find($validated['game_id']);
        }

        if (! $game) {
            if (empty($validated['title'])) {
                return response()->json(['message' => 'O título do jogo é obrigatório para cadastrá-lo.'], 422);
            }
            $game = $this->gamesService->findOrCreateGame([
                'title' => $validated['title'],
                'external_id' => $validated['external_id'] ?? null,
                'external_source' => $validated['external_source'] ?? 'manual',
                'cover_url' => $validated['cover_url'] ?? null,
                'platforms' => $validated['platform'] ? [$validated['platform']] : ['PC'],
            ]);
        }

        $userGame = $this->gamesService->setGameStatus($request->user(), $game, $validated);

        return response()->json([
            'message' => 'Jogo atualizado com sucesso na sua biblioteca!',
            'user_game' => $userGame->load('game'),
        ]);
    }

    /**
     * Remove um jogo da biblioteca
     */
    public function removeGame(int $id, Request $request): JsonResponse
    {
        $removed = $this->gamesService->removeGame($request->user(), $id);

        if (! $removed) {
            return response()->json(['message' => 'Jogo não encontrado na sua biblioteca.'], 404);
        }

        return response()->json(['message' => 'Jogo removido da sua biblioteca com sucesso.']);
    }

    /**
     * Feed comunitário de jogos da Capihouse (conquistas, zeramentos, reviews)
     */
    public function communityFeed(Request $request): JsonResponse
    {
        $limit = max(1, min(50, (int) $request->input('limit', 30)));
        $feed = $this->gamesService->getCommunityFeed($limit);

        return response()->json(['data' => $feed]);
    }

    /**
     * Lista de membros da Capihouse gamers
     */
    public function users(): JsonResponse
    {
        $users = User::select('id', 'name', 'username', 'avatar_url', 'xbox_gamertag', 'steam_username')
            ->where('status', 'approved')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $users]);
    }

    /**
     * Perfil gamer de um membro
     */
    public function userProfile(int $id): JsonResponse
    {
        $profile = $this->gamesService->getUserGamerProfile($id);

        return response()->json($profile);
    }

    /**
     * Conectar Steam
     */
    public function connectSteam(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'steam' => 'required|string|max:150',
        ], [
            'steam.required' => 'Informe a sua Steam ID, URL de perfil ou apelido personalizado.',
        ]);

        $input = trim($validated['steam']);

        // Se o usuário colou uma URL completa:
        // https://steamcommunity.com/id/nome ou https://steamcommunity.com/profiles/76561198...
        if (preg_match('#steamcommunity\.com/id/([^/?]+)#i', $input, $m)) {
            $steamId = null;
            $steamUsername = $m[1];
        } elseif (preg_match('#steamcommunity\.com/profiles/(\d+)#i', $input, $m)) {
            $steamId = $m[1];
            $steamUsername = null;
        } elseif (is_numeric($input) && strlen($input) >= 16) {
            $steamId = $input;
            $steamUsername = null;
        } else {
            $steamId = null;
            $steamUsername = $input;
        }

        $user = $request->user();
        $user->update([
            'steam_id' => $steamId,
            'steam_username' => $steamUsername ?: ($user->steam_username ?: $steamId),
        ]);

        Cache::put("steam_syncing_{$user->id}", true, now()->addMinutes(3));

        try {
            $count = $this->steamService->sync($user);
            Cache::forget("steam_syncing_{$user->id}");

            return response()->json([
                'message' => "Conta Steam conectada com sucesso! {$count} jogo(s) sincronizado(s).",
                'user' => new UserResource($user->fresh()),
            ]);
        } catch (\Throwable $e) {
            Cache::forget("steam_syncing_{$user->id}");

            return response()->json([
                'message' => "Conta Steam salva, porém a sincronização automática falhou: {$e->getMessage()}",
                'user' => new UserResource($user->fresh()),
            ]);
        }
    }

    /**
     * Desconectar Steam
     */
    public function disconnectSteam(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->update([
            'steam_id' => null,
            'steam_username' => null,
            'steam_last_synced_at' => null,
        ]);

        return response()->json([
            'message' => 'Conta Steam desconectada com sucesso.',
            'user' => new UserResource($user->fresh()),
        ]);
    }

    /**
     * Sincronizar Steam manualmente
     */
    public function syncSteam(Request $request): JsonResponse
    {
        $user = $request->user();

        if (empty($user->steam_id) && empty($user->steam_username)) {
            return response()->json(['message' => 'Nenhuma conta Steam vinculada ao seu perfil.'], 422);
        }

        Cache::put("steam_syncing_{$user->id}", true, now()->addMinutes(3));

        try {
            $count = $this->steamService->sync($user);
            Cache::forget("steam_syncing_{$user->id}");

            return response()->json([
                'message' => "Sincronização Steam concluída! {$count} jogo(s) atualizados.",
                'user' => new UserResource($user->fresh()),
            ]);
        } catch (\Throwable $e) {
            Cache::forget("steam_syncing_{$user->id}");

            return response()->json([
                'message' => "Erro ao sincronizar com a Steam: {$e->getMessage()}",
            ], 422);
        }
    }

    /**
     * Sincronizar Xbox manualmente para o CapyGames
     */
    public function syncXbox(Request $request): JsonResponse
    {
        $user = $request->user();

        if (empty($user->xbox_gamertag)) {
            return response()->json(['message' => 'Nenhuma Gamertag do Xbox vinculada ao seu perfil.'], 422);
        }

        try {
            $count = $this->xboxService->sync($user);

            return response()->json([
                'message' => "Sincronização Xbox concluída! {$count} jogo(s) atualizados.",
                'user' => new UserResource($user->fresh()),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => "Erro ao sincronizar com Xbox Live: {$e->getMessage()}",
            ], 422);
        }
    }
}
