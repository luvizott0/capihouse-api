<?php

namespace App\Http\Controllers\Api;

use App\Enums\MediaType;
use App\Events\NotificationSent;
use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\Group;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class GroupController extends Controller
{
    public function index(Request $request)
    {
        $userId = auth()->id();
        $isAdmin = auth()->user()->isAdmin();
        $query = Group::with(['creator', 'media']);

        // Groups are only by invite/membership
        if (!$isAdmin) {
            $query->whereHas('members', function ($q) use ($userId) {
                $q->where('users.id', $userId)
                  ->where('group_users.status', 'accepted');
            });
        }

        $groups = $query->latest()->paginate(20);

        return response()->json($groups);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'photo' => 'nullable|image|max:10240',
            'invites' => 'nullable|array',
            'invites.*' => 'exists:users,id',
        ]);

        $group = Group::create([
            'name' => $request->input('name'),
            'description' => $request->input('description'),
            'creator_id' => auth()->id(),
        ]);

        // Attach creator as owner and accepted
        $group->members()->attach(auth()->id(), [
            'role' => 'owner',
            'status' => 'accepted',
        ]);

        // Photo upload
        if ($request->hasFile('photo')) {
            $disk = config('filesystems.default', 'public');
            $path = $request->file('photo')->store("groups/{$group->id}", $disk);
            $group->media()->create([
                'path' => $path,
                'type' => MediaType::IMAGE,
                'collection_name' => 'group_photo',
            ]);
        }

        // Send invites if provided
        if ($request->has('invites') && is_array($request->input('invites'))) {
            $this->sendInvites($group, $request->input('invites'));
        }

        return response()->json($group->load(['creator', 'media']), 201);
    }

    public function show(Group $group)
    {
        $userId = auth()->id();
        $isMemberOrInvited = $group->members()->where('users.id', $userId)->exists();

        if (!$isMemberOrInvited && !auth()->user()->isAdmin()) {
            return response()->json(['message' => 'Grupo disponível apenas por convite.'], 403);
        }

        return response()->json($group->load(['creator', 'media', 'acceptedMembers']));
    }

    public function update(Request $request, Group $group)
    {
        $userId = auth()->id();
        $isOwnerOrAdmin = $group->creator_id === $userId ||
            $group->members()->where('users.id', $userId)->wherePivot('role', 'owner')->exists() ||
            auth()->user()->isAdmin();

        if (!$isOwnerOrAdmin) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'photo' => 'nullable|image|max:10240',
        ]);

        $group->update([
            'name' => $request->input('name'),
            'description' => $request->input('description'),
        ]);

        if ($request->hasFile('photo')) {
            foreach ($group->media as $media) {
                $media->delete();
            }

            $disk = config('filesystems.default', 'public');
            $path = $request->file('photo')->store("groups/{$group->id}", $disk);
            $group->media()->create([
                'path' => $path,
                'type' => MediaType::IMAGE,
                'collection_name' => 'group_photo',
            ]);
        }

        return response()->json($group->load(['creator', 'media']));
    }

    public function destroy(Group $group)
    {
        $userId = auth()->id();
        $canDelete = $group->creator_id === $userId || auth()->user()->isAdmin();

        if (!$canDelete) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        foreach ($group->media as $media) {
            $media->delete();
        }

        $group->delete();

        return response()->json(['message' => 'Grupo excluído com sucesso.']);
    }

    public function invite(Request $request, Group $group)
    {
        $userId = auth()->id();
        $isMember = $group->members()->where('users.id', $userId)->wherePivot('status', 'accepted')->exists();

        if (!$isMember && !auth()->user()->isAdmin()) {
            return response()->json(['message' => 'Apenas membros podem convidar outros usuários.'], 403);
        }

        $request->validate([
            'user_ids' => 'required|array|min:1',
            'user_ids.*' => 'exists:users,id',
        ]);

        $this->sendInvites($group, $request->input('user_ids'));

        return response()->json(['message' => 'Convites enviados com sucesso.']);
    }

    public function acceptInvite(Group $group)
    {
        $userId = auth()->id();
        $memberRecord = $group->members()->where('users.id', $userId)->first();

        if (!$memberRecord) {
            return response()->json(['message' => 'Você não possui convite para este grupo.'], 404);
        }

        if ($memberRecord->pivot->status === 'accepted') {
            return response()->json(['message' => 'Você já faz parte deste grupo.', 'group' => $group]);
        }

        $group->members()->updateExistingPivot($userId, [
            'status' => 'accepted',
        ]);

        // Mark corresponding invite notifications as read and accepted
        $notifs = AppNotification::where('user_id', $userId)
            ->where('type', 'group_invite')
            ->where('data->group_id', $group->id)
            ->get();

        foreach ($notifs as $n) {
            $data = $n->data ?? [];
            $data['status'] = 'accepted';
            $n->update([
                'data' => $data,
                'read_at' => now(),
            ]);
        }

        return response()->json([
            'message' => 'Você entrou no grupo!',
            'group' => $group->fresh(['creator', 'media', 'acceptedMembers']),
        ]);
    }

    public function declineInvite(Group $group)
    {
        $userId = auth()->id();
        $memberRecord = $group->members()->where('users.id', $userId)->first();

        if ($memberRecord && $memberRecord->pivot->status === 'pending') {
            $group->members()->detach($userId);

            $notifs = AppNotification::where('user_id', $userId)
                ->where('type', 'group_invite')
                ->where('data->group_id', $group->id)
                ->get();

            foreach ($notifs as $n) {
                $data = $n->data ?? [];
                $data['status'] = 'declined';
                $n->update([
                    'data' => $data,
                    'read_at' => now(),
                ]);
            }
        }

        return response()->json(['message' => 'Convite recusado.']);
    }

    public function leave(Group $group)
    {
        $userId = auth()->id();
        $memberRecord = $group->members()->where('users.id', $userId)->first();

        if (!$memberRecord) {
            return response()->json(['message' => 'Você não é membro deste grupo.'], 400);
        }

        $group->members()->detach($userId);

        // If user was owner and other members exist, transfer ownership to next accepted member
        if ($memberRecord->pivot->role === 'owner') {
            $nextMember = $group->members()->wherePivot('status', 'accepted')->first();
            if ($nextMember) {
                $group->members()->updateExistingPivot($nextMember->id, ['role' => 'owner']);
            }
        }

        return response()->json(['message' => 'Você saiu do grupo com sucesso.']);
    }

    public function members(Group $group)
    {
        $userId = auth()->id();
        $isMember = $group->members()->where('users.id', $userId)->wherePivot('status', 'accepted')->exists();

        if (!$isMember && !auth()->user()->isAdmin()) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        $members = $group->members()
            ->wherePivot('status', 'accepted')
            ->get(['users.id', 'users.name', 'users.username', 'users.avatar_url']);

        return response()->json($members);
    }

    private function sendInvites(Group $group, array $userIds): void
    {
        $sender = auth()->user();
        foreach ($userIds as $targetId) {
            if ($targetId == $sender->id) continue;

            $alreadyMember = $group->members()->where('users.id', $targetId)->exists();
            if (!$alreadyMember) {
                $group->members()->attach($targetId, [
                    'role' => 'member',
                    'status' => 'pending',
                ]);

                $notification = AppNotification::create([
                    'user_id' => $targetId,
                    'type' => 'group_invite',
                    'title' => 'Convite para grupo',
                    'content' => "{$sender->name} convidou você para participar do grupo {$group->name}.",
                    'data' => [
                        'group_id' => $group->id,
                        'group_name' => $group->name,
                        'inviter_id' => $sender->id,
                        'inviter_name' => $sender->name,
                        'status' => 'pending',
                    ],
                ]);

                $unreadCount = AppNotification::where('user_id', $targetId)
                    ->whereNull('read_at')
                    ->count();

                try {
                    broadcast(new NotificationSent($notification, $unreadCount));
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }
    }
}
