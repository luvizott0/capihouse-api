<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsApproved
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->isBanned()) {
            return response()->json(['message' => 'Sua conta foi banida. Entre em contato com o administrador.'], 403);
        }

        if ($user && ! $user->isApproved()) {
            return response()->json(['message' => 'Sua conta ainda não foi aprovada pelo administrador.'], 403);
        }

        return $next($request);
    }
}
