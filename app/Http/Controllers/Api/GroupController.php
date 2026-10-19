<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Group;
use Illuminate\Http\Request;

class GroupController extends Controller
{
    public function index(Request $request)
    {
        $userId = auth()->id();

        // Grupos são exclusivamente privados do usuário que os criou
        $query = Group::with('members:id,name,username,avatar_url')
            ->where('creator_id', $userId);

        if ($request->filled('q') || $request->filled('search')) {
            $search = $request->input('q', $request->input('search'));
            $query->where('name', 'like', "%{$search}%");
        }

        if ($request->filled('start_date') || $request->filled('end_date')) {
            $startDate = $request->input('start_date');
            $endDate = $request->input('end_date');
            if ($startDate && $endDate) {
                $query->whereBetween('created_at', [$startDate.' 00:00:00', $endDate.' 23:59:59']);
            } elseif ($startDate) {
                $query->where('created_at', '>=', $startDate.' 00:00:00');
            } elseif ($endDate) {
                $query->where('created_at', '<=', $endDate.' 23:59:59');
            }
        } elseif ($request->filled('date')) {
            $query->whereDate('created_at', $request->input('date'));
        }

        $groups = $query->latest()->paginate(50);

        return response()->json($groups);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:25',
            'color' => 'nullable|string|max:20',
            'description' => 'nullable|string|max:500',
            'members' => 'nullable|array',
            'members.*' => 'exists:users,id',
        ], [
            'name.required' => 'O nome do grupo é obrigatório.',
            'name.max' => 'O nome do grupo deve ter no máximo 25 caracteres.',
        ]);

        $group = Group::create([
            'name' => trim($request->input('name')),
            'color' => $request->input('color', '#a66130') ?: '#a66130',
            'description' => $request->input('description'),
            'creator_id' => auth()->id(),
        ]);

        if ($request->has('members')) {
            $memberIds = array_unique(array_filter((array) $request->input('members')));
            $syncData = [];
            foreach ($memberIds as $mId) {
                // Não adiciona o próprio criador como membro da lista de audiência
                if ((int) $mId === (int) auth()->id()) {
                    continue;
                }
                $syncData[$mId] = [
                    'role' => 'member',
                    'status' => 'accepted',
                ];
            }
            $group->members()->sync($syncData);
        }

        return response()->json($group->load('members:id,name,username,avatar_url'), 201);
    }

    public function show(Group $group)
    {
        $userId = auth()->id();

        if ($group->creator_id !== $userId) {
            return response()->json(['message' => 'Grupo não encontrado ou não autorizado.'], 403);
        }

        return response()->json($group->load('members:id,name,username,avatar_url'));
    }

    public function update(Request $request, Group $group)
    {
        $userId = auth()->id();

        if ($group->creator_id !== $userId) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        $request->validate([
            'name' => 'sometimes|required|string|max:25',
            'color' => 'nullable|string|max:20',
            'description' => 'nullable|string|max:500',
            'members' => 'nullable|array',
            'members.*' => 'exists:users,id',
        ], [
            'name.required' => 'O nome do grupo é obrigatório.',
            'name.max' => 'O nome do grupo deve ter no máximo 25 caracteres.',
        ]);

        $updateData = [];
        if ($request->has('name')) {
            $updateData['name'] = trim($request->input('name'));
        }
        if ($request->has('color')) {
            $updateData['color'] = $request->input('color') ?: '#a66130';
        }
        if ($request->has('description')) {
            $updateData['description'] = $request->input('description');
        }

        if (! empty($updateData)) {
            $group->update($updateData);
        }

        if ($request->has('members')) {
            $memberIds = array_unique(array_filter((array) $request->input('members')));
            $syncData = [];
            foreach ($memberIds as $mId) {
                if ((int) $mId === (int) auth()->id()) {
                    continue;
                }
                $syncData[$mId] = [
                    'role' => 'member',
                    'status' => 'accepted',
                ];
            }
            $group->members()->sync($syncData);
        }

        return response()->json($group->load('members:id,name,username,avatar_url'));
    }

    public function destroy(Group $group)
    {
        $userId = auth()->id();

        if ($group->creator_id !== $userId && ! auth()->user()->isAdmin()) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        $group->members()->detach();
        $group->delete();

        return response()->json(['message' => 'Grupo excluído com sucesso.']);
    }

    public function members(Group $group)
    {
        $userId = auth()->id();

        if ($group->creator_id !== $userId) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        return response()->json($group->members()->get(['users.id', 'users.name', 'users.username', 'users.avatar_url']));
    }
}
