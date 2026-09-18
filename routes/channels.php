<?php

use App\Models\Event;
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

/**
 * Canal privado de evento — organizador, convidados ou admin
 * Usado para posts de eventos, likes e comentários exclusivos.
 */
Broadcast::channel('event.{eventId}', function ($user, $eventId) {
    if ($user->isAdmin()) {
        return true;
    }
    $event = Event::find($eventId);
    if (! $event) {
        return false;
    }

    return $event->user_id === $user->id || $event->guests()->where('users.id', $user->id)->exists();
});
