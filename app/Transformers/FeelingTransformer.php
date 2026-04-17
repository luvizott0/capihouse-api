<?php

namespace App\Transformers;

use App\Models\Feeling;
use League\Fractal\TransformerAbstract;

class FeelingTransformer extends TransformerAbstract
{
    /**
     * Transform a Feeling model into an array.
     *
     * @return array<string, mixed>
     */
    public function transform(Feeling $feeling): array
    {
        return [
            'id' => $feeling->id,
            'name' => $feeling->name,
            'color' => $feeling->color,
            'emoji' => $feeling->emoji,
            'is_active' => $feeling->is_active,
            'created_at' => $feeling->created_at?->toIso8601String(),
            'updated_at' => $feeling->updated_at?->toIso8601String(),
        ];
    }
}
