<?php

use App\Models\Group;
use Illuminate\Support\Facades\Broadcast;

/**
 * Canal privado do usuário — autenticação por ID
 * Usado para notificações pessoais.
 */
Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

/**
 * Canal privado de grupo — apenas membros aceitos (ou admin)
 * Usado para posts de grupos, likes, comentários e chat.
 */
Broadcast::channel('group.{groupId}', function ($user, $groupId) {
    if ($user->isAdmin()) {
        return true;
    }
    return Group::find($groupId)
        ?->members()
        ->where('users.id', $user->id)
        ->wherePivot('status', 'accepted')
        ->exists() ?? false;
});
