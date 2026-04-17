<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Models\Feeling;
use App\Transformers\FeelingTransformer;
use Illuminate\Http\JsonResponse;
use Spatie\Fractal\Facades\Fractal;

class FeelingController extends ApiController
{
    /**
     * List active feelings.
     */
    public function index(): JsonResponse
    {
        $feelings = Feeling::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $data = Fractal::create()
            ->collection($feelings, new FeelingTransformer())
            ->toArray();

        return $this->success([
            'items' => $data['data'],
        ], 'Sentimentos carregados com sucesso.');
    }
}
