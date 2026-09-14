<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class LoginController extends Controller
{
    public function login(LoginRequest $request)
    {
        $login = $request->input('login');
        $password = $request->input('password');

        $user = User::where('email', $login)->orWhere('username', $login)->first();

        if (!$user || !Hash::check($password, $user->password)) {
            return response()->json(['message' => 'Credenciais inválidas.'], 401);
        }

        if ($user->isBanned()) {
            return response()->json(['message' => 'Sua conta foi banida. Entre em contato com o administrador.'], 403);
        }

        if ($user->isPending()) {
            return response()->json(['message' => 'Sua conta ainda não foi aprovada pelo administrador.'], 403);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'user' => new UserResource($user),
            'token' => $token,
        ]);
    }
}
