<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    /**
     * Health check endpoint.
     */
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'message' => 'API is healthy',
            'data' => [
                'version' => '1.0.0',
                'laravel' => app()->version(),
                'php' => PHP_VERSION,
                'timestamp' => now()->toIso8601String(),
            ],
        ]);
    }
}
