<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Models\User;
use App\Transformers\UserTransformer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Fractal\Facades\Fractal;

class AuthController extends ApiController
{
    /**
     * Register a new user with pending status.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name' => $request->validated('name'),
            'username' => $request->validated('username'),
            'email' => $request->validated('email'),
            'password' => $request->validated('password'),
            'status' => User::STATUS_PENDING,
        ]);

        $data = Fractal::create()
            ->item($user, new UserTransformer())
            ->toArray();

        return $this->created(
            $data['data'],
            'Pedido de registro enviado ao administrador.'
        );
    }

    /**
     * Login user with email or username.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $login = $request->validated('login');
        $password = $request->validated('password');

        // Determine if login is email or username
        $isEmail = filter_var($login, FILTER_VALIDATE_EMAIL) !== false;

        // Find user by email or username (case-insensitive)
        $normalizedLogin = Str::lower($login);
        $user = $isEmail
            ? User::whereRaw('LOWER(email) = ?', [$normalizedLogin])->first()
            : User::whereRaw('LOWER(username) = ?', [$normalizedLogin])->first();

        // Check credentials
        if (! $user || ! Hash::check($password, $user->password)) {
            return $this->error('Credenciais inválidas.', null, 401);
        }

        // Check if user is approved
        if (! $user->isApproved()) {
            $message = match ($user->status) {
                User::STATUS_PENDING => 'Sua conta ainda está pendente de aprovação.',
                User::STATUS_REJECTED => 'Sua solicitação de conta foi rejeitada.',
                User::STATUS_SUSPENDED => 'Sua conta está suspensa.',
                default => 'Você não tem permissão para acessar o sistema.',
            };

            return $this->error($message, null, 403);
        }

        // Login user (Sanctum cookie-based session)
        Auth::login($user);

        $data = Fractal::create()
            ->item($user, new UserTransformer())
            ->toArray();

        return $this->success(
            ['user' => $data['data']],
            'Login realizado com sucesso.'
        );
    }

    /**
     * Logout current user.
     */
    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $this->success(null, 'Logout realizado com sucesso.');
    }

    /**
     * Get authenticated user.
     */
    public function user(Request $request): JsonResponse
    {
        $data = Fractal::create()
            ->item($request->user(), new UserTransformer())
            ->toArray();

        return $this->success($data['data']);
    }
}
