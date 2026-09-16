<?php

namespace App\Http\Controllers\Api;

use App\Events\GroupMessageSent;
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

        if (!$isMember && !auth()->user()->isAdmin()) {
            return response()->json(['message' => 'Você precisa ser membro do grupo para ver o chat.'], 403);
        }

        $query = $group->messages()->with('user:id,name,username,avatar_url');

        if ($request->filled('since_id')) {
            $query->where('id', '>', $request->input('since_id'));
        }

        $messages = $query->orderBy('id', 'asc')->take(100)->get();

        return response()->json($messages);
    }

    public function store(Request $request, Group $group)
    {
        $userId = auth()->id();
        $isMember = $group->members()->where('users.id', $userId)->wherePivot('status', 'accepted')->exists();

        if (!$isMember && !auth()->user()->isAdmin()) {
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

        // Broadcast message to group channel safely
        try {
            broadcast(new GroupMessageSent($message));
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json($message, 201);
    }
}
