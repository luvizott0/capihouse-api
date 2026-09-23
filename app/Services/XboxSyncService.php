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
            Log::warning("OpenXBL API Key não configurada. Não foi possível sincronizar Xbox Live para o usuário #{$user->id} ({$gamertag}).");

            throw new Exception('A chave OPENXBL_API_KEY não está configurada no servidor (.env). Obtenha sua chave gratuita em https://xbl.io para sincronizar os dados da Xbox Live.');
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
                    $rawProfile = $profileRes->json();
                    if (($rawProfile['code'] ?? null) !== 404 && ($rawProfile['content']['StatusCode'] ?? null) !== 404) {
                        $profileData = $rawProfile['content'] ?? $rawProfile;
                        $xuid = $profileData['profileUsers'][0]['id']
                            ?? $profileData['xuid']
                            ?? $profileData['id']
                            ?? null;
                    }
                }

                // Fallback para /v2/account SOMENTE se a conta autenticada for do próprio usuário (mesma gamertag)
                if (empty($xuid)) {
                    $accRes = Http::withHeaders([
                        'X-Authorization' => $apiKey,
                        'Accept' => 'application/json',
                    ])->get('https://api.xbl.io/v2/account');

                    if ($accRes->successful()) {
                        $rawAcc = $accRes->json();
                        $accData = $rawAcc['content'] ?? $rawAcc;
                        $accUser = $accData['profileUsers'][0] ?? null;

                        if ($accUser) {
                            $accGamertag = null;
                            foreach ($accUser['settings'] ?? [] as $setting) {
                                if (in_array($setting['id'] ?? '', ['Gamertag', 'ModernGamertag'], true)) {
                                    $accGamertag = $setting['value'] ?? null;
                                    break;
                                }
                            }
                            if ($accGamertag && strcasecmp(trim($accGamertag), $gamertag) === 0) {
                                $xuid = $accUser['id'] ?? null;
                            }
                        }
                    }
                }

                if ($xuid) {
                    $user->update(['xbox_xuid' => (string) $xuid]);
                } else {
                    throw new Exception("Não foi possível localizar o identificador da Gamertag '{$gamertag}' na Xbox Live. Verifique se o nome está correto e se o perfil e histórico de jogos estão públicos.");
                }
            }

            // 2. Buscar títulos do jogador via OpenXBL v2
            $titlesEndpoint = "https://api.xbl.io/v2/titles/{$xuid}";

            $response = Http::withHeaders([
                'X-Authorization' => $apiKey,
                'Accept' => 'application/json',
            ])->get($titlesEndpoint);

            if (! $response->successful() && $xuid) {
                // Fallback para /v2/achievements/player/{xuid}
                $response = Http::withHeaders([
                    'X-Authorization' => $apiKey,
                    'Accept' => 'application/json',
                ])->get("https://api.xbl.io/v2/achievements/player/{$xuid}");
            }

            if (! $response->successful()) {
                Log::warning("Erro ao buscar jogos do Xbox para {$gamertag}: HTTP {$response->status()} - {$response->body()}");

                return 0;
            }

            $rawTitles = $response->json();
            $content = $rawTitles['content'] ?? $rawTitles;
            $allTitles = $content['titles'] ?? $content['xbl_titles'] ?? [];

            if (empty($allTitles) || ! is_array($allTitles)) {
                $user->update(['xbox_last_synced_at' => now()]);

                return 0;
            }

            // Filtrar apenas jogos válidos
            $gameTitles = array_filter($allTitles, function ($item) {
                $titleName = trim((string) ($item['name'] ?? $item['titleName'] ?? ''));
                $titleId = (string) ($item['titleId'] ?? $item['id'] ?? '');
                $type = $item['type'] ?? '';

                return ! empty($titleId) && ! empty($titleName) && ($type === 'Game' || ! empty($item['achievement']));
            });

            // Ordenar por data da última jogatina decrescente
            usort($gameTitles, function ($a, $b) {
                $dateA = $a['titleHistory']['lastTimePlayed'] ?? $a['lastUnlock'] ?? $a['lastPlayed'] ?? '';
                $dateB = $b['titleHistory']['lastTimePlayed'] ?? $b['lastUnlock'] ?? $b['lastPlayed'] ?? '';

                return strcmp($dateB, $dateA);
            });

            // Selecionar os 25 jogos mais recentes + todos os jogos 100% miletados
            $recentTitles = array_slice($gameTitles, 0, 25);
            $masteredTitles = array_filter($gameTitles, function ($item) {
                $achievementData = $item['achievement'] ?? $item['achievements'] ?? [];
                $currentGamerscore = (int) ($achievementData['currentGamerscore'] ?? $item['currentGamerscore'] ?? 0);
                $totalGamerscore = (int) ($achievementData['totalGamerscore'] ?? $item['totalGamerscore'] ?? 0);
                $progressPct = (float) ($achievementData['progressPercentage'] ?? $item['progressPercentage'] ?? 0);

                return $progressPct >= 100 || ($currentGamerscore >= $totalGamerscore && $totalGamerscore > 0);
            });

            // Unir sem duplicatas por titleId
            $selectedTitlesMap = [];
            foreach (array_merge($recentTitles, $masteredTitles) as $t) {
                $tId = (string) ($t['titleId'] ?? $t['id'] ?? '');
                if ($tId && ! isset($selectedTitlesMap[$tId])) {
                    $selectedTitlesMap[$tId] = $t;
                }
            }

            $importedCount = 0;

            foreach ($selectedTitlesMap as $item) {
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

                // Plataforma
                $platform = 'Xbox';
                if (! empty($item['devices']) && is_array($item['devices'])) {
                    $deviceLabels = array_map(function ($d) {
                        return match ($d) {
                            'XboxSeries' => 'Xbox Series X|S',
                            'XboxOne' => 'Xbox One',
                            'Xbox360' => 'Xbox 360',
                            'PC' => 'PC',
                            default => (string) $d,
                        };
                    }, $item['devices']);
                    $platform = implode(', ', $deviceLabels);
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
                $lastUnlockedStr = $item['titleHistory']['lastTimePlayed']
                    ?? $item['lastUnlock']
                    ?? $item['lastPlayed']
                    ?? $item['lastModified']
                    ?? null;
                $playedAt = $lastUnlockedStr ? Carbon::parse($lastUnlockedStr) : now();

                $externalId = "xbox-{$user->id}-{$titleId}";
                $existingPost = Post::where('user_id', $user->id)
                    ->where('external_source', 'xbox')
                    ->where('external_id', $externalId)
                    ->first();

                $metadata = [
                    'game_title' => $titleName,
                    'platform' => $platform,
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
                    // Posts sincronizados do Xbox não geram texto pré-fabricado
                    $celebrationContent = null;

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
