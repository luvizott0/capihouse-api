<?php

namespace App\Http\Controllers\Api;

use App\Enums\MediaType;
use App\Http\Controllers\Controller;
use App\Models\CapsuleMemory;
use App\Models\CapsuleTag;
use App\Models\Media;
use App\Services\ImageOptimizerService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CapsuleController extends Controller
{
    /**
     * Lista memórias com filtros avançados
     */
    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();
        $query = CapsuleMemory::visibleTo($user)
            ->with([
                'user:id,name,username,avatar_url',
                'tags',
                'participants.user:id,name,username,avatar_url',
                'media',
            ]);

        // 1. Busca textual
        if ($search = $request->input('q') ?? $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('content', 'like', "%{$search}%")
                    ->orWhere('location_name', 'like', "%{$search}%")
                    ->orWhereHas('participants', function ($pQuery) use ($search) {
                        $pQuery->where('name', 'like', "%{$search}%")
                            ->orWhereHas('user', function ($uQuery) use ($search) {
                                $uQuery->where('name', 'like', "%{$search}%")
                                    ->orWhere('username', 'like', "%{$search}%");
                            });
                    })
                    ->orWhereHas('tags', function ($tQuery) use ($search) {
                        $tQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('slug', 'like', "%{$search}%");
                    });
            });
        }

        // 2. Filtro por Ano
        if ($year = $request->input('year')) {
            $query->whereYear('occurred_at', (int) $year);
        }

        // 3. Filtro por Mês
        if ($month = $request->input('month')) {
            $query->whereMonth('occurred_at', (int) $month);
        }

        // 4. Filtro por Tag
        if ($tag = $request->input('tag')) {
            $query->whereHas('tags', function ($tQuery) use ($tag) {
                $tQuery->where('slug', $tag)->orWhere('name', $tag);
            });
        }

        // 5. Filtro por Participante
        if ($participantId = $request->input('participant_id')) {
            $query->whereHas('participants', function ($pQuery) use ($participantId) {
                $pQuery->where('user_id', $participantId);
            });
        }

        // 6. Apenas Favoritos
        if ($request->boolean('is_favorite')) {
            $query->where('is_favorite', true);
        }

        // 7. Apenas com Áudio
        if ($request->boolean('has_audio')) {
            $query->whereHas('media', function ($mQuery) {
                $mQuery->where('type', MediaType::AUDIO);
            });
        }

        // 8. Apenas com Fotos/Imagens
        if ($request->boolean('has_photos')) {
            $query->whereHas('media', function ($mQuery) {
                $mQuery->where('type', MediaType::IMAGE);
            });
        }

        // 9. Ordenação
        $direction = strtolower($request->input('direction', 'desc')) === 'asc' ? 'asc' : 'desc';
        $query->orderBy('occurred_at', $direction)->orderBy('id', $direction);

        $perPage = min((int) $request->input('per_page', 20), 100);
        $memories = $query->paginate($perPage);

        // Mascarar conteúdo de cápsulas do tempo que ainda estão trancadas
        $memories->getCollection()->transform(function (CapsuleMemory $memory) {
            return $this->transformMemory($memory);
        });

        return response()->json($memories);
    }

    /**
     * Retorna uma memória específica
     */
    public function show(int $id): JsonResponse
    {
        $user = auth()->user();
        $memory = CapsuleMemory::with([
            'user:id,name,username,avatar_url',
            'tags',
            'participants.user:id,name,username,avatar_url',
            'media',
        ])->findOrFail($id);

        if ($memory->visibility === 'private' && $memory->user_id !== $user->id && ! $user->isAdmin()) {
            $isParticipant = $memory->participants()->where('user_id', $user->id)->exists();
            if (! $isParticipant) {
                return response()->json(['message' => 'Esta memória é privada.'], 403);
            }
        }

        return response()->json([
            'data' => $this->transformMemory($memory, fullDetail: true),
        ]);
    }

    /**
     * Cria uma nova memória com suporte a fotos, áudios e vídeos
     */
    public function store(Request $request): JsonResponse
    {
        $this->parseJsonInputs($request);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'nullable|string',
            'occurred_at' => 'nullable|date',
            'occurred_at_precision' => 'nullable|string|in:exact,month,year,approximate',
            'approximate_date' => 'nullable|string|max:100',
            'location_name' => 'nullable|string|max:255',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'mood' => 'nullable|string|max:50',
            'mood_emoji' => 'nullable|string|max:32',
            'visibility' => 'nullable|string|in:private,shared,public',
            'is_favorite' => 'nullable|boolean',
            'locked_until' => 'nullable|date',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:50',
            'participants' => 'nullable|array',
            'participants.*.user_id' => 'nullable|exists:users,id',
            'participants.*.name' => 'nullable|string|max:100',
            'participants.*.role' => 'nullable|string|max:50',
            'media' => 'nullable|array|max:20',
            'media.*' => 'file|max:51200', // 50MB por arquivo
        ]);

        return DB::transaction(function () use ($request, $validated) {
            $user = auth()->user();

            $memory = CapsuleMemory::create([
                'user_id' => $user->id,
                'title' => $validated['title'],
                'content' => $validated['content'] ?? null,
                'occurred_at' => $validated['occurred_at'] ?? Carbon::today(),
                'occurred_at_precision' => $validated['occurred_at_precision'] ?? 'exact',
                'approximate_date' => $validated['approximate_date'] ?? null,
                'location_name' => $validated['location_name'] ?? null,
                'latitude' => $validated['latitude'] ?? null,
                'longitude' => $validated['longitude'] ?? null,
                'mood' => $validated['mood'] ?? null,
                'mood_emoji' => $validated['mood_emoji'] ?? null,
                'visibility' => $validated['visibility'] ?? 'shared',
                'is_favorite' => $request->boolean('is_favorite', false),
                'locked_until' => $validated['locked_until'] ?? null,
            ]);

            // 1. Sincronizar Tags
            if (! empty($validated['tags'])) {
                $tagIds = [];
                foreach ($validated['tags'] as $tagName) {
                    $cleanName = trim($tagName);
                    if ($cleanName !== '') {
                        $tag = CapsuleTag::firstOrCreate(
                            ['slug' => Str::slug($cleanName)],
                            ['name' => $cleanName]
                        );
                        $tagIds[] = $tag->id;
                    }
                }
                $memory->tags()->sync($tagIds);
            }

            // 2. Adicionar Participantes
            if (! empty($validated['participants'])) {
                foreach ($validated['participants'] as $partData) {
                    if (! empty($partData['user_id']) || ! empty($partData['name'])) {
                        $memory->participants()->create([
                            'user_id' => $partData['user_id'] ?? null,
                            'name' => $partData['name'] ?? null,
                            'role' => $partData['role'] ?? 'presente',
                        ]);
                    }
                }
            }

            // 3. Processar Mídias (Fotos, Áudios, Vídeos)
            if ($request->hasFile('media')) {
                $this->saveUploadedMedia($request->file('media'), $memory);
            }

            $memory->load([
                'user:id,name,username,avatar_url',
                'tags',
                'participants.user:id,name,username,avatar_url',
                'media',
            ]);

            return response()->json([
                'message' => 'Memória guardada com sucesso na cápsula!',
                'data' => $this->transformMemory($memory, fullDetail: true),
            ], 201);
        });
    }

    /**
     * Atualiza uma memória existente
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $this->parseJsonInputs($request);
        $user = auth()->user();
        $memory = CapsuleMemory::findOrFail($id);

        if ($memory->user_id !== $user->id && ! $user->isAdmin()) {
            return response()->json(['message' => 'Você não tem permissão para editar esta memória.'], 403);
        }

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'content' => 'nullable|string',
            'occurred_at' => 'nullable|date',
            'occurred_at_precision' => 'nullable|string|in:exact,month,year,approximate',
            'approximate_date' => 'nullable|string|max:100',
            'location_name' => 'nullable|string|max:255',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'mood' => 'nullable|string|max:50',
            'mood_emoji' => 'nullable|string|max:32',
            'visibility' => 'nullable|string|in:private,shared,public',
            'is_favorite' => 'nullable|boolean',
            'locked_until' => 'nullable|date',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:50',
            'participants' => 'nullable|array',
            'deleted_media_ids' => 'nullable|array',
            'deleted_media_ids.*' => 'integer',
            'media' => 'nullable|array|max:20',
            'media.*' => 'file|max:51200',
        ]);

        return DB::transaction(function () use ($request, $validated, $memory) {
            $memory->fill(array_filter([
                'title' => $validated['title'] ?? null,
                'content' => array_key_exists('content', $validated) ? $validated['content'] : null,
                'occurred_at' => $validated['occurred_at'] ?? null,
                'occurred_at_precision' => $validated['occurred_at_precision'] ?? null,
                'approximate_date' => array_key_exists('approximate_date', $validated) ? $validated['approximate_date'] : null,
                'location_name' => array_key_exists('location_name', $validated) ? $validated['location_name'] : null,
                'latitude' => array_key_exists('latitude', $validated) ? $validated['latitude'] : null,
                'longitude' => array_key_exists('longitude', $validated) ? $validated['longitude'] : null,
                'mood' => array_key_exists('mood', $validated) ? $validated['mood'] : null,
                'mood_emoji' => array_key_exists('mood_emoji', $validated) ? $validated['mood_emoji'] : null,
                'visibility' => $validated['visibility'] ?? null,
                'locked_until' => array_key_exists('locked_until', $validated) ? $validated['locked_until'] : null,
            ], fn ($val) => ! is_null($val)));

            if ($request->has('is_favorite')) {
                $memory->is_favorite = $request->boolean('is_favorite');
            }

            $memory->save();

            // Sincronizar Tags
            if ($request->has('tags')) {
                $tagIds = [];
                foreach ($request->input('tags', []) as $tagName) {
                    $cleanName = trim($tagName);
                    if ($cleanName !== '') {
                        $tag = CapsuleTag::firstOrCreate(
                            ['slug' => Str::slug($cleanName)],
                            ['name' => $cleanName]
                        );
                        $tagIds[] = $tag->id;
                    }
                }
                $memory->tags()->sync($tagIds);
            }

            // Atualizar Participantes
            if ($request->has('participants')) {
                $memory->participants()->delete();
                foreach ($request->input('participants', []) as $partData) {
                    if (! empty($partData['user_id']) || ! empty($partData['name'])) {
                        $memory->participants()->create([
                            'user_id' => $partData['user_id'] ?? null,
                            'name' => $partData['name'] ?? null,
                            'role' => $partData['role'] ?? 'presente',
                        ]);
                    }
                }
            }

            // Excluir mídias selecionadas
            if (! empty($validated['deleted_media_ids'])) {
                $mediasToDelete = $memory->media()->whereIn('id', $validated['deleted_media_ids'])->get();
                foreach ($mediasToDelete as $mediaItem) {
                    $mediaItem->delete();
                }
            }

            // Adicionar novas mídias
            if ($request->hasFile('media')) {
                $this->saveUploadedMedia($request->file('media'), $memory);
            }

            $memory->load([
                'user:id,name,username,avatar_url',
                'tags',
                'participants.user:id,name,username,avatar_url',
                'media',
            ]);

            return response()->json([
                'message' => 'Memória atualizada com sucesso!',
                'data' => $this->transformMemory($memory, fullDetail: true),
            ]);
        });
    }

    /**
     * Remove uma memória e suas mídias associadas
     */
    public function destroy(int $id): JsonResponse
    {
        $user = auth()->user();
        $memory = CapsuleMemory::findOrFail($id);

        if ($memory->user_id !== $user->id && ! $user->isAdmin()) {
            return response()->json(['message' => 'Você não tem permissão para excluir esta memória.'], 403);
        }

        // Deletar cada mídia dispara o hook do model Media para apagar do storage
        foreach ($memory->media as $media) {
            $media->delete();
        }

        $memory->delete();

        return response()->json([
            'message' => 'Memória excluída com sucesso.',
        ]);
    }

    /**
     * Alterna status de favorito
     */
    public function toggleFavorite(int $id): JsonResponse
    {
        $memory = CapsuleMemory::findOrFail($id);
        $memory->is_favorite = ! $memory->is_favorite;
        $memory->save();

        return response()->json([
            'is_favorite' => $memory->is_favorite,
            'message' => $memory->is_favorite ? 'Marcada como favorita!' : 'Removida dos favoritos.',
        ]);
    }

    /**
     * "Neste Dia" (On This Day): Lembranças que ocorreram nesta mesma data em anos anteriores
     */
    public function onThisDay(Request $request): JsonResponse
    {
        $user = auth()->user();
        $month = $request->input('month') ? (int) $request->input('month') : Carbon::now()->month;
        $day = $request->input('day') ? (int) $request->input('day') : Carbon::now()->day;
        $currentYear = Carbon::now()->year;

        $memories = CapsuleMemory::visibleTo($user)
            ->onThisDay($month, $day, $currentYear)
            ->with([
                'user:id,name,username,avatar_url',
                'tags',
                'participants.user:id,name,username,avatar_url',
                'media',
            ])
            ->orderBy('occurred_at', 'desc')
            ->get();

        // Agrupar por ano
        $grouped = [];
        foreach ($memories as $memory) {
            $year = Carbon::parse($memory->occurred_at)->year;
            $diffYears = $currentYear - $year;

            if (! isset($grouped[$year])) {
                $grouped[$year] = [
                    'year' => $year,
                    'years_ago' => $diffYears,
                    'label' => $diffYears === 1 ? 'Há 1 ano' : "Há {$diffYears} anos",
                    'memories' => [],
                ];
            }

            $grouped[$year]['memories'][] = $this->transformMemory($memory);
        }

        return response()->json([
            'date' => sprintf('%02d/%02d', $day, $month),
            'total_memories' => $memories->count(),
            'timeline' => array_values($grouped),
        ]);
    }

    /**
     * Estatísticas e estrutura cronológica para o navegador de linha do tempo
     */
    public function timelineStats(Request $request): JsonResponse
    {
        $user = auth()->user();
        $baseQuery = CapsuleMemory::visibleTo($user)->whereNotNull('occurred_at');

        $totalMemories = (clone $baseQuery)->count();
        $totalAudios = Media::where('mediable_type', CapsuleMemory::class)
            ->where('type', MediaType::AUDIO)
            ->count();
        $totalPhotos = Media::where('mediable_type', CapsuleMemory::class)
            ->where('type', MediaType::IMAGE)
            ->count();

        // Buscar agrupamento por ano e mês
        $memories = (clone $baseQuery)
            ->selectRaw('occurred_at, id')
            ->orderBy('occurred_at', 'desc')
            ->get();

        $yearsMap = [];
        foreach ($memories as $item) {
            $dt = Carbon::parse($item->occurred_at);
            $year = $dt->year;
            $month = $dt->month;

            if (! isset($yearsMap[$year])) {
                $yearsMap[$year] = [
                    'year' => $year,
                    'count' => 0,
                    'months' => [],
                ];
            }

            $yearsMap[$year]['count']++;

            if (! isset($yearsMap[$year]['months'][$month])) {
                $yearsMap[$year]['months'][$month] = [
                    'month' => $month,
                    'count' => 0,
                ];
            }
            $yearsMap[$year]['months'][$month]['count']++;
        }

        $timeline = array_map(function ($yearData) {
            $yearData['months'] = array_values($yearData['months']);

            return $yearData;
        }, array_values($yearsMap));

        return response()->json([
            'total_memories' => $totalMemories,
            'total_audios' => $totalAudios,
            'total_photos' => $totalPhotos,
            'timeline' => $timeline,
        ]);
    }

    /**
     * Lista tags mais frequentes com contagem
     */
    public function tags(): JsonResponse
    {
        $tags = CapsuleTag::withCount('memories')
            ->orderBy('memories_count', 'desc')
            ->get();

        return response()->json($tags);
    }

    /**
     * Salva arquivos enviados (detecta se é imagem, áudio ou vídeo)
     */
    protected function saveUploadedMedia(array $files, CapsuleMemory $memory): void
    {
        $disk = config('filesystems.default', 'public');

        foreach ($files as $file) {
            $mime = $file->getMimeType() ?? '';
            $ext = strtolower($file->getClientOriginalExtension());

            if (str_starts_with($mime, 'audio/') || in_array($ext, ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'webm'])) {
                // Áudio / Nota de voz
                $path = $file->store("capsules/{$memory->id}/audio", $disk);
                $type = MediaType::AUDIO;
                $collection = 'audio_notes';
            } elseif (str_starts_with($mime, 'video/') || in_array($ext, ['mp4', 'mov', 'webm', 'mkv'])) {
                // Vídeo
                $path = $file->store("capsules/{$memory->id}/video", $disk);
                $type = MediaType::VIDEO;
                $collection = 'videos';
            } else {
                // Foto / Imagem (otimiza para WebP mantendo fidelidade)
                $path = ImageOptimizerService::storeOptimized($file, "capsules/{$memory->id}", $disk);
                $type = MediaType::IMAGE;
                $collection = 'photos';
            }

            $memory->media()->create([
                'path' => $path,
                'type' => $type,
                'collection_name' => $collection,
            ]);
        }
    }

    /**
     * Transforma os dados da memória, mascarando detalhes caso ainda esteja lacrada
     */
    protected function transformMemory(CapsuleMemory $memory, bool $fullDetail = false): array
    {
        $isLocked = $memory->is_locked;

        if ($isLocked) {
            return [
                'id' => $memory->id,
                'user_id' => $memory->user_id,
                'user' => $memory->user,
                'title' => $memory->title,
                'content' => '🔒 Esta cápsula está lacrada no tempo e só se abrirá em '.Carbon::parse($memory->locked_until)->translatedFormat('d/m/Y \à\s H:i'),
                'is_locked' => true,
                'locked_until' => $memory->locked_until,
                'occurred_at' => $memory->occurred_at ? $memory->occurred_at->format('Y-m-d') : null,
                'occurred_at_precision' => $memory->occurred_at_precision,
                'approximate_date' => $memory->approximate_date,
                'mood' => $memory->mood,
                'mood_emoji' => $memory->mood_emoji ?? '⏳',
                'visibility' => $memory->visibility,
                'is_favorite' => $memory->is_favorite,
                'tags' => $memory->tags,
                'media' => [],
                'participants' => [],
                'created_at' => $memory->created_at,
            ];
        }

        return [
            'id' => $memory->id,
            'user_id' => $memory->user_id,
            'user' => $memory->user,
            'title' => $memory->title,
            'content' => $memory->content,
            'is_locked' => false,
            'locked_until' => $memory->locked_until,
            'occurred_at' => $memory->occurred_at ? $memory->occurred_at->format('Y-m-d') : null,
            'occurred_at_precision' => $memory->occurred_at_precision,
            'approximate_date' => $memory->approximate_date,
            'location_name' => $memory->location_name,
            'latitude' => $memory->latitude,
            'longitude' => $memory->longitude,
            'mood' => $memory->mood,
            'mood_emoji' => $memory->mood_emoji,
            'visibility' => $memory->visibility,
            'is_favorite' => $memory->is_favorite,
            'tags' => $memory->tags,
            'participants' => $memory->participants,
            'media' => $memory->media,
            'created_at' => $memory->created_at,
            'updated_at' => $memory->updated_at,
        ];
    }

    /**
     * Decodifica inputs JSON enviados como string dentro de FormData multipart
     */
    protected function parseJsonInputs(Request $request): void
    {
        foreach (['tags', 'participants', 'deleted_media_ids'] as $key) {
            $val = $request->input($key);
            if (is_string($val)) {
                $decoded = json_decode($val, true);
                if (is_array($decoded)) {
                    $request->merge([$key => $decoded]);
                }
            }
        }
    }
}
