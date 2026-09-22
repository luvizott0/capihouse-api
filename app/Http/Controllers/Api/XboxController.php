<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Jobs\SyncXboxJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class XboxController extends Controller
{
    /**
     * Conecta uma conta do Xbox ao perfil e dispara a sincronização inicial assíncrona.
     */
    public function connect(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'gamertag' => 'required|string|max:100',
        ], [
            'gamertag.required' => 'Informe a sua Gamertag do Xbox.',
            'gamertag.max' => 'A Gamertag pode ter no máximo 100 caracteres.',
        ]);

        $gamertag = trim($validated['gamertag']);

        $user = $request->user();
        $user->update([
            'xbox_gamertag' => $gamertag,
            'xbox_xuid' => null, // Será preenchido na sincronização
        ]);

        $hasApiKey = ! empty(config('services.openxbl.api_key'));

        if ($hasApiKey) {
            Cache::put("xbox_syncing_{$user->id}", true, now()->addMinutes(5));
            // Dispara sincronização em segundo plano
            SyncXboxJob::dispatch($user);
        }

        return response()->json([
            'message' => "Gamertag '{$gamertag}' conectada com sucesso!",
            'user' => new UserResource($user->fresh()),
        ]);
    }

    /**
     * Desconecta a conta do Xbox.
     */
    public function disconnect(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->update([
            'xbox_gamertag' => null,
            'xbox_xuid' => null,
            'xbox_last_synced_at' => null,
        ]);

        Cache::forget("xbox_syncing_{$user->id}");

        return response()->json([
            'message' => 'Conta do Xbox desconectada com sucesso.',
            'user' => new UserResource($user->fresh()),
        ]);
    }

    /**
     * Dispara manualmente a sincronização em segundo plano.
     */
    public function sync(Request $request): JsonResponse
    {
        $user = $request->user();

        if (empty($user->xbox_gamertag)) {
            return response()->json([
                'message' => 'Nenhuma conta do Xbox vinculada ao seu perfil.',
            ], 422);
        }

        if (empty(config('services.openxbl.api_key'))) {
            return response()->json([
                'message' => 'O serviço de sincronização do Xbox não está disponível no momento.',
            ], 422);
        }

        Cache::put("xbox_syncing_{$user->id}", true, now()->addMinutes(5));

        SyncXboxJob::dispatch($user);

        return response()->json([
            'message' => 'Sincronização do Xbox iniciada em segundo plano! Suas conquistas e jogos aparecerão em breve.',
            'user' => new UserResource($user->fresh()),
        ]);
    }
}
