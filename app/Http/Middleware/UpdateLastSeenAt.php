<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class UpdateLastSeenAt
{
    /**
     * Throttle window in seconds — only updates the DB once per window per user.
     */
    private const THROTTLE_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = $request->user();

        if ($user) {
            $cacheKey = "last_seen_at:{$user->id}";

            if (! Cache::has($cacheKey)) {
                $user->timestamps = false;
                $user->last_seen_at = now();
                $user->save();

                Cache::put($cacheKey, true, self::THROTTLE_SECONDS);
            }
        }

        return $response;
    }
}
