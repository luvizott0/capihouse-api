<?php

namespace App\Services;

use App\Events\PostCreated;
use App\Models\Post;
use App\Models\User;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class XboxSyncService
{
    /**
     * Sincroniza atividades, jogos recentes e conquistas do Xbox para um usuário.
     *
     * @return int Quantidade de posts criados/atualizados
     */
    public function sync(User $user): int
    {
        if (empty($user->xbox_gamertag)) {
            return 0;
        }

        $gamertag = trim($user->xbox_gamertag);
        $apiKey = config('services.openxbl.api_key');

        if (empty($apiKey)) {
            Log::info("OpenXBL API Key não configurada. Pulei sincronização automática da Xbox Live para o usuário #{$user->id} ({$gamertag}).");
            $user->update(['xbox_last_synced_at' => now()]);

            return 0;
        }

        try {
            // 1. Obter ou verificar XUID se ainda não salvo
            $xuid = $user->xbox_xuid;
            if (empty($xuid)) {
                $profileRes = Http::withHeaders([
                    'X-Authorization' => $apiKey,
                    'Accept' => 'application/json',
                ])->get('https://api.xbl.io/v2/friends/search', [
                    'gt' => $gamertag,
                ]);

                if ($profileRes->successful()) {
                    $profileData = $profileRes->json();
                    $xuid = $profileData['profileUsers'][0]['id']
                        ?? $profileData['xuid']
                        ?? $profileData['id']
                        ?? null;

                    if ($xuid) {
                        $user->update(['xbox_xuid' => (string) $xuid]);
                    }
                } elseif ($profileRes->status() === 404) {
                    throw new Exception("Gamertag '{$gamertag}' não foi encontrada na Xbox Live.");
                }
            }

            // 2. Buscar títulos recentes / conquistas do jogador
            $titlesEndpoint = $xuid
                ? "https://api.xbl.io/v2/achievements/player/{$xuid}"
                : 'https://api.xbl.io/v2/player/titleHub';

            $response = Http::withHeaders([
                'X-Authorization' => $apiKey,
                'Accept' => 'application/json',
            ])->get($titlesEndpoint);

            if (! $response->successful()) {
                // Fallback para titleHub se o endpoint de achievements falhar
                if ($xuid) {
                    $response = Http::withHeaders([
                        'X-Authorization' => $apiKey,
                        'Accept' => 'application/json',
                    ])->get('https://api.xbl.io/v2/player/titleHub');
                }
            }

            if (! $response->successful()) {
                Log::warning("Erro ao buscar jogos do Xbox para {$gamertag}: HTTP {$response->status()} - {$response->body()}");

                return 0;
            }

            $data = $response->json();
            $titles = $data['titles'] ?? $data['xbl_titles'] ?? [];

            if (empty($titles) || ! is_array($titles)) {
                $user->update(['xbox_last_synced_at' => now()]);

                return 0;
            }

            $importedCount = 0;

            foreach ($titles as $item) {
                $titleId = (string) ($item['titleId'] ?? $item['id'] ?? '');
                $titleName = (string) ($item['name'] ?? $item['titleName'] ?? '');

                if (empty($titleId) || empty($titleName)) {
                    continue;
                }

                // Extrair imagem de capa
                $boxArtUrl = $item['displayImage']
                    ?? $item['boxArt']
                    ?? $item['image']
                    ?? null;

                if (! $boxArtUrl && ! empty($item['images']) && is_array($item['images'])) {
                    foreach ($item['images'] as $img) {
                        if (($img['type'] ?? '') === 'BoxArt' || ($img['type'] ?? '') === 'Poster') {
                            $boxArtUrl = $img['url'];
                            break;
                        }
                    }
                    if (! $boxArtUrl && isset($item['images'][0]['url'])) {
                        $boxArtUrl = $item['images'][0]['url'];
                    }
                }

                // Estatísticas de conquistas e Gamerscore
                $achievementData = $item['achievement'] ?? $item['achievements'] ?? [];
                $currentAchievements = (int) ($achievementData['currentAchievements'] ?? $achievementData['earned'] ?? $item['earnedAchievements'] ?? 0);
                $totalAchievements = (int) ($achievementData['totalAchievements'] ?? $achievementData['total'] ?? $item['totalAchievements'] ?? 0);
                $currentGamerscore = (int) ($achievementData['currentGamerscore'] ?? $achievementData['currentGamerscore'] ?? $item['currentGamerscore'] ?? 0);
                $totalGamerscore = (int) ($achievementData['totalGamerscore'] ?? $achievementData['totalGamerscore'] ?? $item['totalGamerscore'] ?? 0);

                $progressPct = (float) ($achievementData['progressPercentage'] ?? $item['progressPercentage'] ?? 0);
                if ($progressPct <= 0 && $totalGamerscore > 0) {
                    $progressPct = round(($currentGamerscore / $totalGamerscore) * 100, 1);
                }

                $isMastered = ($progressPct >= 100) || ($currentGamerscore >= $totalGamerscore && $totalGamerscore > 0);
                $gameStatus = $isMastered ? 'mastered' : 'playing';

                // Data da última atividade
                $lastUnlockedStr = $item['lastUnlock'] ?? $item['lastPlayed'] ?? $item['lastModified'] ?? null;
                $playedAt = $lastUnlockedStr ? Carbon::parse($lastUnlockedStr) : now();

                $externalId = "xbox-{$user->id}-{$titleId}";
                $existingPost = Post::where('user_id', $user->id)
                    ->where('external_source', 'xbox')
                    ->where('external_id', $externalId)
                    ->first();

                $metadata = [
                    'game_title' => $titleName,
                    'platform' => 'Xbox',
                    'box_art_url' => $boxArtUrl,
                    'game_status' => $gameStatus,
                    'gamerscore' => $currentGamerscore,
                    'gamerscore_total' => $totalGamerscore,
                    'achievements_count' => $currentAchievements,
                    'achievements_total' => $totalAchievements,
                    'progress_percentage' => $progressPct,
                    'xbox_title_id' => $titleId,
                ];

                if ($existingPost) {
                    // Atualiza post se houve evolução no progresso ou Gamerscore
                    $prevScore = $existingPost->metadata['gamerscore'] ?? 0;
                    if ($currentGamerscore > $prevScore || $isMastered) {
                        $existingPost->update([
                            'metadata' => array_merge($existingPost->metadata ?? [], $metadata),
                            'watched_at' => $playedAt,
                            'updated_at' => now(),
                        ]);
                        $importedCount++;
                    }
                } else {
                    // Criação de novo post
                    $celebrationContent = $isMastered
                        ? "🏆 100% Miletado! Conquistei todos os {$totalGamerscore}G e completei todas as conquistas em {$titleName}!"
                        : null;

                    $newPost = Post::create([
                        'user_id' => $user->id,
                        'category' => 'entertainment',
                        'entertainment_type' => 'game',
                        'external_source' => 'xbox',
                        'external_id' => $externalId,
                        'watched_at' => $playedAt,
                        'content' => $celebrationContent,
                        'metadata' => $metadata,
                        'created_at' => $playedAt,
                        'updated_at' => now(),
                    ]);

                    try {
                        $newPost->load([
                            'user',
                            'media',
                            'comments.user',
                            'comments.parent.user',
                            'comments.mentions',
                            'mentions',
                        ]);
                        $newPost->is_liked = false;
                        $newPost->likes_count = 0;
                        $newPost->comments_count = 0;
                        broadcast(new PostCreated($newPost));
                    } catch (\Throwable $e) {
                        report($e);
                    }

                    $importedCount++;
                }
            }

            $user->update(['xbox_last_synced_at' => now()]);

            return $importedCount;
        } catch (Exception $e) {
            Log::error("Exceção ao sincronizar Xbox para {$gamertag}: {$e->getMessage()}");
            throw $e;
        }
    }
}
