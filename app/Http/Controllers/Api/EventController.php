<?php

namespace App\Http\Controllers\Api;

use App\Enums\MediaType;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\User;
use App\Services\NotificationDispatcherService;
use Illuminate\Http\Request;

class EventController extends Controller
{
    public function index(Request $request)
    {
        $userId = auth()->id();
        $isAdmin = auth()->user()->isAdmin();

        $query = Event::with(['owner', 'guests', 'media'])
            ->withCount('guests');

        if (! $isAdmin) {
            $query->where(function ($q) use ($userId) {
                $q->where('user_id', $userId)
                    ->orWhereHas('guests', function ($g) use ($userId) {
                        $g->where('users.id', $userId);
                    });
            });
        }

        if ($request->filled('q') || $request->filled('search')) {
            $search = $request->input('q', $request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('owner', function ($oq) use ($search) {
                        $oq->where('name', 'like', "%{$search}%")
                            ->orWhere('username', 'like', "%{$search}%");
                    });
            });
        }

        if ($request->filled('date')) {
            $query->whereDate('date', $request->input('date'));
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->input('user_id'));
        }

        $events = $query->orderBy('date', 'asc')
            ->paginate(15);

        return response()->json($events);
    }

    public function show(Event $event)
    {
        $userId = auth()->id();
        $isAdmin = auth()->user()->isAdmin();

        $isInvitedOrOwner = $event->user_id === $userId || $event->guests()->where('users.id', $userId)->exists();
        if (! $isAdmin && ! $isInvitedOrOwner) {
            return response()->json(['message' => 'Você não tem permissão para visualizar este evento.'], 403);
        }

        return response()->json($event->load(['owner', 'guests', 'media'])->loadCount('guests'));
    }

    public function inviteGuests(Request $request, Event $event)
    {
        if ($event->user_id !== auth()->id() && ! auth()->user()->isAdmin()) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        $request->validate([
            'guests' => 'required|array|min:1',
            'guests.*' => 'exists:users,id',
        ]);

        $newGuestIds = $request->input('guests');
        $event->guests()->syncWithoutDetaching($newGuestIds);

        return response()->json([
            'message' => 'Convidados adicionados com sucesso.',
            'event' => $event->fresh()->load(['owner', 'guests', 'media'])->loadCount('guests'),
        ]);
    }

    public function upcoming()
    {
        $userId = auth()->id();
        $isAdmin = auth()->user()->isAdmin();

        $query = Event::with(['owner', 'media'])
            ->where('date', '>=', now()->startOfDay());

        if (! $isAdmin) {
            $query->where(function ($q) use ($userId) {
                $q->where('user_id', $userId)
                    ->orWhereHas('guests', function ($g) use ($userId) {
                        $g->where('users.id', $userId);
                    });
            });
        }

        $events = $query->orderBy('date', 'asc')
            ->take(5)
            ->get();

        return response()->json($events);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'required|string|max:5000',
            'date' => 'required|date',
            'image' => 'nullable|image|max:10240',
            'guests' => 'nullable|array',
            'guests.*' => 'exists:users,id',
        ]);

        $event = Event::create([
            'name' => $request->input('name'),
            'description' => $request->input('description'),
            'date' => $request->input('date'),
            'user_id' => auth()->id(),
        ]);

        // Image
        if ($request->hasFile('image')) {
            $disk = config('filesystems.default', 'public');
            $path = $request->file('image')->store("events/{$event->id}", $disk);
            $event->media()->create([
                'path' => $path,
                'type' => MediaType::IMAGE,
                'collection_name' => 'event_image',
            ]);
        }

        // Guests
        if ($request->has('guests') && is_array($request->input('guests'))) {
            $event->guests()->sync($request->input('guests'));
        }

        return response()->json($event->load(['owner', 'guests', 'media']), 201);
    }

    public function update(Request $request, Event $event)
    {
        if ($event->user_id !== auth()->id() && ! auth()->user()->isAdmin()) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|required|string|max:5000',
            'date' => 'sometimes|required|date',
            'image' => 'nullable|image|max:10240',
            'guests' => 'nullable|array',
            'guests.*' => 'exists:users,id',
        ]);

        $updateData = [];
        if ($request->has('name')) {
            $updateData['name'] = $request->input('name');
        }
        if ($request->has('description')) {
            $updateData['description'] = $request->input('description');
        }
        if ($request->has('date')) {
            $updateData['date'] = $request->input('date');
        }

        if (! empty($updateData)) {
            $event->update($updateData);
        }

        // Image replacement if provided
        if ($request->hasFile('image')) {
            foreach ($event->media as $media) {
                $media->delete();
            }

            $disk = config('filesystems.default', 'public');
            $path = $request->file('image')->store("events/{$event->id}", $disk);
            $event->media()->create([
                'path' => $path,
                'type' => MediaType::IMAGE,
                'collection_name' => 'event_image',
            ]);
        }

        // Guests sync
        if ($request->boolean('clear_guests')) {
            $event->guests()->sync([]);
        } elseif ($request->has('guests')) {
            $guests = is_array($request->input('guests')) ? $request->input('guests') : [];
            $event->guests()->sync($guests);
        }

        return response()->json($event->load(['owner', 'guests', 'media']));
    }

    public function rsvp(Request $request, Event $event)
    {
        $userId = auth()->id();

        // If user is owner, they don't need to RSVP
        if ($event->user_id === $userId) {
            return response()->json(['message' => 'O organizador do evento tem presença garantida.'], 200);
        }

        $request->validate([
            'status' => 'required|in:confirmed,declined,invited',
        ]);

        $status = $request->input('status');

        $event->guests()->syncWithoutDetaching([
            $userId => ['status' => $status],
        ]);

        // Dispara notificação ao organizador do evento
        $user = auth()->user();
        $statusText = $status === 'confirmed' ? 'confirmou presença no' : 'informou que não vai ao';

        NotificationDispatcherService::send(
            recipient: $event->user_id,
            type: 'event_rsvp',
            title: 'Confirmação de Presença',
            content: "{$user->name} {$statusText} seu evento \"{$event->name}\".",
            data: [
                'event_id' => $event->id,
                'user_id' => $user->id,
                'user_name' => $user->name,
                'user_username' => $user->username,
                'user_avatar' => $user->avatar_url,
                'status' => $status,
            ],
            url: '/events/'.$event->id
        );

        return response()->json([
            'message' => 'Presença atualizada com sucesso.',
            'event' => $event->fresh()->load(['owner', 'guests', 'media']),
            'status' => $status,
        ]);
    }

    public function destroy(Event $event)
    {
        if ($event->user_id !== auth()->id() && ! auth()->user()->isAdmin()) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        foreach ($event->media as $media) {
            $media->delete();
        }

        $event->delete();

        return response()->json(['message' => 'Evento excluído com sucesso.']);
    }
}
