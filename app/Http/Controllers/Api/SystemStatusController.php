<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\StorageHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SystemStatusController extends Controller
{
    public function __construct(
        protected StorageHealthService $storageHealthService
    ) {}

    /**
     * Retorna o status de conectividade e disponibilidade do NAS / Storage.
     */
    public function storageStatus(Request $request): JsonResponse
    {
        $fresh = $request->boolean('fresh', false);
        $status = $this->storageHealthService->check($fresh);

        return response()->json($status);
    }
}
