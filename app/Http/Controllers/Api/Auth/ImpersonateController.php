<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;

class ImpersonateController extends Controller
{
    /**
     * Personifica um usuário.
     * Permitido se estiver em ambiente local/testing OU se o usuário logado for Admin.
     */
    public function impersonate(Request $request)
    {
        $isLocalOrTesting = app()->environment('local', 'testing');
        $isAdmin = auth('sanctum')->check() && auth('sanctum')->user()->isAdmin();

        if (!$isLocalOrTesting && !$isAdmin) {
            return response()->json([
                'message' => 'Acesso negado. A funcionalidade de impersonate só está disponível em ambiente local ou para administradores.'
            ], 403);
        }

        $userId = $request->input('user_id');
        $login = $request->input('login') ?? $request->input('username') ?? $request->input('email');

        if (!$userId && !$login) {
            return response()->json([
                'message' => 'Informe o user_id, username ou email do usuário para personificar.'
            ], 422);
        }

        $targetUser = null;
        if ($userId) {
            $targetUser = User::find($userId);
        } else {
            $targetUser = User::where('username', $login)
                ->orWhere('email', $login)
                ->first();
        }

        if (!$targetUser) {
            return response()->json([
                'message' => 'Usuário não encontrado para personificação.'
            ], 404);
        }

        // Gera token para o usuário personificado
        $token = $targetUser->createToken('impersonate_token')->plainTextToken;

        return response()->json([
            'user' => new UserResource($targetUser),
            'token' => $token,
            'impersonating' => true,
        ]);
    }

    /**
     * Personifica um usuário diretamente via rota de administração (/api/admin/users/{user}/impersonate).
     */
    public function impersonateUser(User $user)
    {
        $token = $user->createToken('impersonate_token')->plainTextToken;

        return response()->json([
            'user' => new UserResource($user),
            'token' => $token,
            'impersonating' => true,
        ]);
    }

    /**
     * Retorna a lista simplificada de usuários disponíveis para teste no ambiente local.
     * Retorna 404 em produção.
     */
    public function devUsers()
    {
        if (!app()->environment('local', 'testing')) {
            return response()->json(['message' => 'Indisponível neste ambiente.'], 404);
        }

        $users = User::select(['id', 'name', 'username', 'email', 'role', 'status', 'avatar_url', 'bio'])
            ->orderBy('id')
            ->get();

        return response()->json([
            'users' => $users,
        ]);
    }
}
