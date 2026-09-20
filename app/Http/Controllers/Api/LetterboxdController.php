<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SyncLetterboxdJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LetterboxdController extends Controller
{
    /**
     * Conecta uma conta do Letterboxd ao perfil e dispara a sincronização inicial assíncrona.
     */
    public function connect(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'username' => 'required|string|max:100',
        ], [
            'username.required' => 'Informe o seu nome de usuário do Letterboxd.',
            'username.max' => 'O nome de usuário pode ter no máximo 100 caracteres.',
        ]);

        $username = trim(ltrim($validated['username'], '@'));

        $user = $request->user();
        $user->update([
            'letterboxd_username' => $username,
        ]);

        // Dispara sincronização em segundo plano
        SyncLetterboxdJob::dispatch($user);

        return response()->json([
            'message' => "Conta @{$username} do Letterboxd conectada! A sincronização inicial foi iniciada em segundo plano.",
            'user' => $user->fresh(),
        ]);
    }

    /**
     * Desconecta a conta do Letterboxd.
     */
    public function disconnect(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->update([
            'letterboxd_username' => null,
            'letterboxd_last_synced_at' => null,
        ]);

        return response()->json([
            'message' => 'Conta do Letterboxd desconectada com sucesso.',
            'user' => $user->fresh(),
        ]);
    }

    /**
     * Dispara manualmente a sincronização em segundo plano.
     */
    public function sync(Request $request): JsonResponse
    {
        $user = $request->user();

        if (empty($user->letterboxd_username)) {
            return response()->json([
                'message' => 'Nenhuma conta do Letterboxd vinculada ao seu perfil.',
            ], 422);
        }

        SyncLetterboxdJob::dispatch($user);

        return response()->json([
            'message' => 'Sincronização iniciada em segundo plano! Suas novas avaliações aparecerão em breve.',
        ]);
    }
}
