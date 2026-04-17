<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\V1\Feeling\StoreFeelingRequest;
use App\Http\Requests\Api\V1\Feeling\UpdateFeelingRequest;
use App\Models\Feeling;
use App\Transformers\FeelingTransformer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Spatie\Fractal\Facades\Fractal;

class FeelingController extends ApiController
{
    /**
     * List feelings for admin management.
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('manage-feelings');

        $includeDeleted = filter_var($request->query('include_deleted', false), FILTER_VALIDATE_BOOLEAN);
        $query = Feeling::query()->orderBy('name');

        if ($includeDeleted) {
            $query->withTrashed();
        }

        $feelings = $query->get();

        $data = Fractal::create()
            ->collection($feelings, new FeelingTransformer())
            ->toArray();

        return $this->success([
            'items' => $data['data'],
        ], 'Sentimentos carregados com sucesso.');
    }

    /**
     * Create a new feeling.
     */
    public function store(StoreFeelingRequest $request): JsonResponse
    {
        Gate::authorize('manage-feelings');

        $validated = $request->validated();

        $feeling = Feeling::create([
            'name' => $validated['name'],
            'color' => $validated['color'],
            'emoji' => $validated['emoji'],
            'is_active' => $validated['is_active'] ?? true,
        ]);

        $data = Fractal::create()
            ->item($feeling, new FeelingTransformer())
            ->toArray();

        return $this->created($data['data'], 'Sentimento criado com sucesso.');
    }

    /**
     * Update an existing feeling.
     */
    public function update(UpdateFeelingRequest $request, int $id): JsonResponse
    {
        Gate::authorize('manage-feelings');

        $feeling = Feeling::query()->findOrFail($id);
        $feeling->fill($request->validated());
        $feeling->save();

        $data = Fractal::create()
            ->item($feeling, new FeelingTransformer())
            ->toArray();

        return $this->success($data['data'], 'Sentimento atualizado com sucesso.');
    }

    /**
     * Soft delete a feeling.
     */
    public function destroy(int $id): JsonResponse
    {
        Gate::authorize('manage-feelings');

        $feeling = Feeling::query()->findOrFail($id);
        $feeling->delete();

        return $this->success(null, 'Sentimento removido com sucesso.');
    }
}
