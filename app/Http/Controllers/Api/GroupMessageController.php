<?php

namespace App\Http\Controllers\Api;

use App\Events\GroupMessageDeleted;
use App\Events\GroupMessageSent;
use App\Events\GroupMessageUpdated;
use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\GroupMessage;
use Illuminate\Http\Request;

class GroupMessageController extends Controller
{
    public function index(Request $request, Group $group)
    {
        $userId = auth()->id();
        $isMember = $group->members()->where('users.id', $userId)->wherePivot('status', 'accepted')->exists();

        if (! $isMember && ! auth()->user()->isAdmin()) {
            return response()->json(['message' => 'Você precisa ser membro do grupo para ver o chat.'], 403);
        }

        $query = $group->messages()->with('user:id,name,username,avatar_url');

        if ($request->filled('since_id')) {
            $query->where('id', '>', $request->input('since_id'));
        }

        $messages = $query->orderBy('id', 'asc')->take(100)->get();

        $group->members()->updateExistingPivot($userId, [
            'last_read_at' => now(),
        ]);

        return response()->json($messages);
    }

    public function store(Request $request, Group $group)
    {
        $userId = auth()->id();
        $isMember = $group->members()->where('users.id', $userId)->wherePivot('status', 'accepted')->exists();

        if (! $isMember && ! auth()->user()->isAdmin()) {
            return response()->json(['message' => 'Você precisa ser membro do grupo para enviar mensagens.'], 403);
        }

        $request->validate([
            'content' => 'required|string|max:2000',
        ]);

        $message = $group->messages()->create([
            'user_id' => $userId,
            'content' => $request->input('content'),
        ]);

        $message->load('user:id,name,username,avatar_url');

        // Broadcast message to group channel safely to others
        try {
            broadcast(new GroupMessageSent($message))->toOthers();
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json($message, 201);
    }

    public function update(Request $request, Group $group, GroupMessage $message)
    {
        $userId = auth()->id();
        $isMember = $group->members()->where('users.id', $userId)->wherePivot('status', 'accepted')->exists();

        if (! $isMember && ! auth()->user()->isAdmin()) {
            return response()->json(['message' => 'Você precisa ser membro do grupo para editar mensagens.'], 403);
        }

        if ($message->group_id !== $group->id) {
            return response()->json(['message' => 'Mensagem não encontrada neste grupo.'], 404);
        }

        if (! is_null($message->deleted_at)) {
            return response()->json(['message' => 'Não é possível editar uma mensagem deletada.'], 422);
        }

        if ($message->user_id !== $userId && ! auth()->user()->isAdmin()) {
            return response()->json(['message' => 'Você não tem permissão para editar esta mensagem.'], 403);
        }

        $request->validate([
            'content' => 'required|string|max:2000',
        ]);

        $message->update([
            'content' => $request->input('content'),
            'edited_at' => now(),
        ]);

        $message->load('user:id,name,username,avatar_url');

        try {
            broadcast(new GroupMessageUpdated($message))->toOthers();
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json($message);
    }

    public function destroy(Group $group, GroupMessage $message)
    {
        $userId = auth()->id();
        $isMember = $group->members()->where('users.id', $userId)->wherePivot('status', 'accepted')->exists();

        if (! $isMember && ! auth()->user()->isAdmin()) {
            return response()->json(['message' => 'Você precisa ser membro do grupo para excluir mensagens.'], 403);
        }

        if ($message->group_id !== $group->id) {
            return response()->json(['message' => 'Mensagem não encontrada neste grupo.'], 404);
        }

        $isAuthor = $message->user_id === $userId;
        $isGroupOwner = $group->creator_id === $userId || $group->members()->where('users.id', $userId)->wherePivot('role', 'owner')->exists();
        $isAdmin = auth()->user()->isAdmin();

        if (! $isAuthor && ! $isGroupOwner && ! $isAdmin) {
            return response()->json(['message' => 'Você não tem permissão para excluir esta mensagem.'], 403);
        }

        $message->update([
            'content' => 'mensagem deletada',
            'deleted_at' => now(),
        ]);

        $message->load('user:id,name,username,avatar_url');

        try {
            broadcast(new GroupMessageDeleted($message))->toOthers();
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json($message);
    }
}
