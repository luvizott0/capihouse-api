<?php

namespace App\Http\Controllers\Api;

use App\Events\PostCreated;
use App\Http\Controllers\Controller;
use App\Models\Post;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class GameReviewController extends Controller
{
    /**
     * Cria uma publicação manual de análise ou registro de jogo.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'game_title' => 'required|string|max:200',
            'platform' => 'nullable|string|max:100',
            'game_status' => 'nullable|string|in:playing,completed,mastered,dropped,wishlist',
            'rating' => 'nullable|numeric|min:0|max:5',
            'content' => 'nullable|string|max:5000',
            'box_art_url' => 'nullable|string|url|max:1000',
            'box_art' => 'nullable|image|max:10240',
            'hours_played' => 'nullable|numeric|min:0|max:10000',
        ], [
            'game_title.required' => 'O título do jogo é obrigatório.',
            'game_title.max' => 'O título pode ter no máximo 200 caracteres.',
            'rating.numeric' => 'A nota deve ser um valor numérico entre 0 e 5.',
            'box_art.image' => 'A capa deve ser uma imagem válida.',
        ]);

        $boxArtUrl = $validated['box_art_url'] ?? null;

        // Se o usuário fez upload direto do arquivo de capa
        if ($request->hasFile('box_art')) {
            $path = $request->file('box_art')->store('game-covers', 'public');
            $boxArtUrl = Storage::disk('public')->url($path);
        }

        $content = ! empty($validated['content']) ? trim($validated['content']) : null;
        $rating = isset($validated['rating']) && $validated['rating'] !== '' ? (float) $validated['rating'] : null;
        $gameStatus = $validated['game_status'] ?? 'playing';
        $platform = ! empty($validated['platform']) ? trim($validated['platform']) : 'Geral';
        $hoursPlayed = isset($validated['hours_played']) && $validated['hours_played'] !== '' ? (float) $validated['hours_played'] : null;

        $post = Post::create([
            'user_id' => $request->user()->id,
            'category' => 'entertainment',
            'entertainment_type' => 'game',
            'external_source' => 'manual',
            'external_id' => 'manual-game-'.Str::uuid(),
            'watched_at' => now(),
            'content' => $content,
            'metadata' => [
                'game_title' => trim($validated['game_title']),
                'platform' => $platform,
                'game_status' => $gameStatus,
                'rating' => $rating,
                'hours_played' => $hoursPlayed,
                'box_art_url' => $boxArtUrl,
                'review_text' => $content,
            ],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $post->load([
                'user',
                'media',
                'comments.user',
                'comments.parent.user',
                'comments.mentions',
                'mentions',
            ]);
            $post->is_liked = false;
            $post->likes_count = 0;
            $post->comments_count = 0;
            broadcast(new PostCreated($post));
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json([
            'message' => 'Análise de jogo publicada com sucesso!',
            'post' => $post,
        ], 201);
    }
}
