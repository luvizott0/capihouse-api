<?php

namespace App\Policies;

use App\Models\User;

class FeelingPolicy
{
    /**
     * Determine whether the user can manage feelings.
     */
    public function manage(User $user): bool
    {
        return $user->hasRole('admin');
    }
}
