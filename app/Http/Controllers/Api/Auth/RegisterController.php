<?php

namespace App\Http\Controllers\Api\Auth;

use App\Enums\UserStatuses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class RegisterController extends Controller
{
    public function register(RegisterRequest $request)
    {
        $user = User::create([
            'name' => $request->input('name'),
            'username' => $request->input('username'),
            'email' => $request->input('email'),
            'password' => Hash::make($request->input('password')),
            'status' => UserStatuses::PENDING,
        ]);

        return response()->json([
            'message' => 'Cadastro realizado com sucesso! Aguarde a aprovação do administrador.',
        ], 201);
    }
}
