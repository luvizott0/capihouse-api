<?php

namespace App\Transformers;

use App\Models\User;
use League\Fractal\TransformerAbstract;

class UserTransformer extends TransformerAbstract
{
    /**
     * Transform a User model into an array.
     *
     * @return array<string, mixed>
     */
    public function transform(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'status' => $user->status,
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }
}
