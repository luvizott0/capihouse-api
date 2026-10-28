<?php

namespace App\Services;

use App\Models\CapyGame;
use App\Models\CapyUserGame;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SteamSyncService
{
    /**
     * Sincroniza a biblioteca de jogos da Steam de um usuário.
     *
     * @return int Quantidade de jogos importados/atualizados
     */
    public function sync(User $user): int
    {
        if (empty($user->steam_id) && empty($user->steam_username)) {
            return 0;
        }

        $steamIdentifier = trim((string) ($user->steam_id ?: $user->steam_username));
        $apiKey = config('services.steam.api_key') ?: env('STEAM_API_KEY');

        if (! $apiKey) {
            throw new \Exception('Chave da API da Steam (STEAM_API_KEY) não configurada no servidor. Gere uma chave em steamcommunity.com/dev/apikey e configure no arquivo .env.');
        }

        // 1. Resolver Vanity URL / apelido personalizado caso não seja SteamID64 numérico (17 dígitos)
        if (! is_numeric($steamIdentifier) || strlen($steamIdentifier) < 16) {
            try {
                $vanityRes = Http::withHeaders(['User-Agent' => 'CapyHouse/1.0'])->timeout(6)->get('https://api.steampowered.com/ISteamUser/ResolveVanityURL/v0001/', [
                    'key' => $apiKey,
                    'vanityurl' => $steamIdentifier,
                ]);

                if ($vanityRes->successful() && $vanityRes->json('response.success') === 1) {
                    $steamIdentifier = (string) $vanityRes->json('response.steamid');
                    $user->update(['steam_id' => $steamIdentifier]);
                } else {
                    throw new \Exception("Conta Steam com usuário '{$steamIdentifier}' não encontrada. Verifique se o apelido é exatamente o do link do seu perfil (ou informe o SteamID64 numérico).");
                }
            } catch (\Throwable $e) {
                if ($e instanceof \Exception && str_contains($e->getMessage(), 'não encontrada')) {
                    throw $e;
                }
                throw new \Exception("Erro ao resolver usuário Steam: {$e->getMessage()}");
            }
        }

        $importedCount = 0;
        $games = [];

        // 2. Buscar biblioteca de jogos via Steam Web API oficial
        try {
            $response = Http::withHeaders(['User-Agent' => 'CapyHouse/1.0'])->timeout(12)->get('https://api.steampowered.com/IPlayerService/GetOwnedGames/v0001/', [
                'key' => $apiKey,
                'steamid' => $steamIdentifier,
                'format' => 'json',
                'include_appinfo' => 1,
                'include_played_free_games' => 1,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $gamesList = $data['response']['games'] ?? [];
                foreach ($gamesList as $g) {
                    $appId = $g['appid'] ?? null;
                    if (! $appId) {
                        continue;
                    }
                    $playtimeMinutes = $g['playtime_forever'] ?? 0;
                    $hours = round($playtimeMinutes / 60, 1);

                    $games[] = [
                        'app_id' => $appId,
                        'name' => $g['name'] ?? "Steam App {$appId}",
                        'hours' => $hours,
                        'cover_url' => "https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/{$appId}/library_600x900.jpg",
                        'banner_url' => "https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/{$appId}/header.jpg",
                        'recent_playtime' => $g['playtime_2weeks'] ?? 0,
                        'rtime_last_played' => $g['rtime_last_played'] ?? 0,
                    ];
                }
            } else {
                throw new \Exception("Steam API retornou erro HTTP {$response->status()}.");
            }
        } catch (\Throwable $e) {
            Log::warning("Falha ao sincronizar Steam via Web API para {$user->username}: {$e->getMessage()}");
            throw $e;
        }

        if (empty($games)) {
            throw new \Exception('Nenhum jogo público encontrado na sua conta Steam. Verifique se o seu perfil Steam e a opção "Detalhes dos jogos" estão marcados como PÚBLICOS nas Configurações de Privacidade da Steam.');
        }

        // 3. Buscar conquistas da Steam concorrentemente para jogos com horas jogadas
        $achievementsMap = [];
        $playedGames = array_filter($games, fn ($g) => ($g['hours'] ?? 0) > 0);

        if (! empty($playedGames)) {
            // Prioriza os jogos jogados mais recentemente
            usort($playedGames, function ($a, $b) {
                $lastA = (int) ($a['rtime_last_played'] ?? 0);
                $lastB = (int) ($b['rtime_last_played'] ?? 0);
                if ($lastA !== $lastB) {
                    return $lastB <=> $lastA;
                }

                return ($b['hours'] ?? 0) <=> ($a['hours'] ?? 0);
            });

            // Limita a busca a até 60 jogos com tempo jogado para garantir sincronização rápida
            $gamesToFetch = array_slice($playedGames, 0, 60);
            $chunks = array_chunk($gamesToFetch, 12);

            foreach ($chunks as $chunk) {
                try {
                    $poolResponses = Http::pool(function (Pool $pool) use ($chunk, $apiKey, $steamIdentifier) {
                        $requests = [];
                        foreach ($chunk as $item) {
                            $appId = (string) $item['app_id'];
                            $requests[$appId] = $pool->as($appId)
                                ->withHeaders(['User-Agent' => 'CapyHouse/1.0'])
                                ->timeout(6)
                                ->get('https://api.steampowered.com/ISteamUserStats/GetPlayerAchievements/v0001/', [
                                    'key' => $apiKey,
                                    'steamid' => $steamIdentifier,
                                    'appid' => $appId,
                                    'l' => 'brazilian',
                                ]);
                        }

                        return $requests;
                    });

                    foreach ($poolResponses as $appId => $res) {
                        if ($res instanceof Response && $res->successful()) {
                            $playerstats = $res->json('playerstats');
                            if (! empty($playerstats['success']) && isset($playerstats['achievements']) && is_array($playerstats['achievements'])) {
                                $achievements = $playerstats['achievements'];
                                $total = count($achievements);
                                $earned = 0;
                                foreach ($achievements as $ach) {
                                    if (! empty($ach['achieved'])) {
                                        $earned++;
                                    }
                                }

                                $achievementsMap[(int) $appId] = [
                                    'total' => $total,
                                    'earned' => $earned,
                                ];
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning("Falha ao buscar lote de conquistas da Steam: {$e->getMessage()}");
                }
            }
        }

        // 4. Processar jogos e salvar na biblioteca CapyGames
        foreach ($games as $item) {
            $appId = (int) $item['app_id'];
            $name = $item['name'];
            $hours = $item['hours'];
            $rtimeLastPlayed = (int) ($item['rtime_last_played'] ?? 0);
            $recentPlaytime = (int) ($item['recent_playtime'] ?? 0);
            $externalId = "steam:{$appId}";

            $game = CapyGame::firstOrCreate(
                [
                    'external_source' => 'steam',
                    'external_id' => $externalId,
                ],
                [
                    'title' => $name,
                    'slug' => Str::slug($name),
                    'cover_url' => $item['cover_url'],
                    'banner_url' => $item['banner_url'],
                    'platforms' => ['PC'],
                    'developer' => null,
                    'publisher' => null,
                ]
            );

            $userGame = CapyUserGame::firstOrNew([
                'user_id' => $user->id,
                'game_id' => $game->id,
            ]);

            // Atualizar contagem de conquistas se obtidas da Steam
            if (isset($achievementsMap[$appId])) {
                $userGame->achievements_total = $achievementsMap[$appId]['total'];
                $userGame->achievements_earned = $achievementsMap[$appId]['earned'];

                // Atualizar metadados do jogo se não possuía a quantidade de conquistas
                if ($userGame->achievements_total > 0 && empty($game->raw_data['achievements_count'])) {
                    $raw = $game->raw_data ?? [];
                    $raw['achievements_count'] = $userGame->achievements_total;
                    $game->raw_data = $raw;
                    $game->save();
                }
            } elseif ($hours == 0) {
                if (! $userGame->exists || $userGame->achievements_total === null) {
                    $userGame->achievements_total = 0;
                    $userGame->achievements_earned = 0;
                }
            }

            $totalAch = $userGame->achievements_total ?? 0;
            $earnedAch = $userGame->achievements_earned ?? 0;
            $isMastered = ($totalAch > 0 && $earnedAch >= $totalAch);
            $isRecent = ($recentPlaytime > 0)
                || ($rtimeLastPlayed > 0 && $rtimeLastPlayed >= now()->subDays(30)->timestamp);

            if (! $userGame->exists) {
                $userGame->source = 'steam';
                $userGame->platform = 'Steam';

                if ($hours == 0) {
                    $userGame->status = 'plan_to_play';
                } elseif ($isMastered) {
                    $userGame->status = 'mastered';
                } elseif ($isRecent) {
                    $userGame->status = 'playing';
                } else {
                    $userGame->status = 'on_hold';
                }
            } else {
                // Registro existente: verificar se o usuário fez personalizações manuais
                $hasManualIntervention = ! empty($userGame->review)
                    || ! empty($userGame->rating)
                    || ! empty($userGame->completed_at)
                    || $userGame->status === 'dropped';

                if ($isMastered) {
                    $userGame->status = 'mastered';
                } elseif ($hasManualIntervention) {
                    // Mantém status manual customizado pelo usuário
                } elseif ($userGame->status === 'completed') {
                    // Corrige o bug anterior em que todo jogo com horas ficava 'completed'
                    $userGame->status = $isRecent ? 'playing' : 'on_hold';
                } elseif ($isRecent) {
                    $userGame->status = 'playing';
                } elseif ($userGame->status === 'playing' && ! $isRecent) {
                    // Se não é jogado há mais de 30 dias, move para 'on_hold'
                    $userGame->status = 'on_hold';
                }
            }

            if ($hours > 0) {
                $userGame->setPlatformHours('Steam', $hours);
            }

            if (! empty($item['rtime_last_played'])) {
                $userGame->last_played_at = Carbon::createFromTimestamp($item['rtime_last_played']);
                $userGame->timestamps = false;
                $userGame->updated_at = $userGame->last_played_at;
                $userGame->save();
                $userGame->timestamps = true;
            } else {
                $userGame->timestamps = false;
                $userGame->save();
                $userGame->timestamps = true;
            }

            $importedCount++;
        }

        $user->update(['steam_last_synced_at' => now()]);

        return $importedCount;
    }
}
