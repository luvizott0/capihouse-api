<?php

namespace App\Http\Controllers\Api;

use App\Enums\MediaType;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class EventController extends Controller
{
    public function index(Request $request)
    {
        $userId = auth()->id();
        $isAdmin = auth()->user()->isAdmin();

        $query = Event::with(['owner', 'guests', 'media'])
            ->withCount('guests');

        if (!$isAdmin) {
            $query->where(function ($q) use ($userId) {
                $q->where('user_id', $userId)
                  ->orWhereHas('guests', function ($g) use ($userId) {
                      $g->where('users.id', $userId);
                  });
            });
        }

        $events = $query->orderBy('date', 'asc')
            ->paginate(15);

        return response()->json($events);
    }

    public function upcoming()
    {
        $userId = auth()->id();
        $isAdmin = auth()->user()->isAdmin();

        $query = Event::with(['owner', 'media'])
            ->where('date', '>=', now()->startOfDay());

        if (!$isAdmin) {
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
        if ($event->user_id !== auth()->id() && !auth()->user()->isAdmin()) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'required|string|max:5000',
            'date' => 'required|date',
            'image' => 'nullable|image|max:10240',
            'guests' => 'nullable|array',
            'guests.*' => 'exists:users,id',
        ]);

        $event->update([
            'name' => $request->input('name'),
            'description' => $request->input('description'),
            'date' => $request->input('date'),
        ]);

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

        // Only invited guests or admins can RSVP
        $isInvited = $event->guests()->where('users.id', $userId)->exists();
        if (!$isInvited && !auth()->user()->isAdmin()) {
            return response()->json(['message' => 'Você não foi convidado para este evento.'], 403);
        }

        $request->validate([
            'status' => 'required|in:confirmed,declined,invited',
        ]);

        $event->guests()->syncWithoutDetaching([
            $userId => ['status' => $request->input('status')],
        ]);

        return response()->json(['message' => 'Presença atualizada com sucesso.']);
    }

    public function destroy(Event $event)
    {
        if ($event->user_id !== auth()->id() && !auth()->user()->isAdmin()) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        foreach ($event->media as $media) {
            $media->delete();
        }

        $event->delete();

        return response()->json(['message' => 'Evento excluído com sucesso.']);
    }
}
