<?php

namespace App\Services;

use App\Models\CapyGame;
use App\Models\CapyUserGame;
use App\Models\User;
use Carbon\Carbon;
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

        $importedCount = 0;
        $games = [];

        // 1. Tentar buscar via Steam Web API oficial se houver STEAM_API_KEY e steam_id numérico (17 dígitos)
        if ($apiKey && is_numeric($steamIdentifier) && strlen($steamIdentifier) >= 16) {
            try {
                $response = Http::timeout(8)->get('https://api.steampowered.com/IPlayerService/GetOwnedGames/v0001/', [
                    'key' => $apiKey,
                    'steamid' => $steamIdentifier,
                    'format' => 'json',
                    'include_appinfo' => true,
                    'include_played_free_games' => true,
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
                }
            } catch (\Throwable $e) {
                Log::warning("Falha ao sincronizar Steam via Web API para {$user->username}: {$e->getMessage()}");
            }
        }

        // 2. Fallback: Buscar via Community XML público (funciona com vanity URL ou steamid64 se o perfil for público)
        if (empty($games)) {
            try {
                $url = is_numeric($steamIdentifier) && strlen($steamIdentifier) >= 16
                    ? "https://steamcommunity.com/profiles/{$steamIdentifier}/games?xml=1"
                    : "https://steamcommunity.com/id/{$steamIdentifier}/games?xml=1";

                $res = Http::withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) CapyHouse/1.0',
                ])->timeout(8)->get($url);

                if ($res->successful() && str_contains($res->body(), '<games>')) {
                    $xml = @simplexml_load_string($res->body());
                    if ($xml && isset($xml->games->game)) {
                        foreach ($xml->games->game as $g) {
                            $appId = (int) $g->appID;
                            if (! $appId) {
                                continue;
                            }
                            $hours = (float) str_replace(',', '.', (string) ($g->hoursOnRecord ?? '0'));
                            $recentHours = (float) str_replace(',', '.', (string) ($g->hoursLast2Weeks ?? '0'));

                            $games[] = [
                                'app_id' => $appId,
                                'name' => (string) $g->name,
                                'hours' => $hours,
                                'cover_url' => "https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/{$appId}/library_600x900.jpg",
                                'banner_url' => "https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/{$appId}/header.jpg",
                                'recent_playtime' => $recentHours > 0 ? (int) ($recentHours * 60) : 0,
                            ];
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::warning("Falha ao buscar XML Steam da comunidade para {$user->username}: {$e->getMessage()}");
            }
        }

        if (empty($games)) {
            Log::info("Nenhum jogo público encontrado na conta Steam informada ({$steamIdentifier}).");

            return 0;
        }

        // 3. Processar jogos e salvar na biblioteca CapyGames
        foreach ($games as $item) {
            $appId = $item['app_id'];
            $name = $item['name'];
            $hours = $item['hours'];
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

            // Se for jogo jogado recentemente nas últimas 2 semanas, marca como playing
            if (($item['recent_playtime'] ?? 0) > 0) {
                $userGame->status = 'playing';
            } elseif (! $userGame->exists) {
                $status = ($hours > 0 ? 'completed' : 'plan_to_play');
                $userGame->status = $status;
            }

            if (! $userGame->exists) {
                $userGame->source = 'steam';
                $userGame->platform = 'PC (Steam)';
            }

            if ($hours > 0) {
                $userGame->setPlatformHours('PC (Steam)', $hours);
            }

            if (! empty($item['rtime_last_played'])) {
                $userGame->timestamps = false;
                $userGame->updated_at = Carbon::createFromTimestamp($item['rtime_last_played']);
                $userGame->save();
                $userGame->timestamps = true;
            } else {
                $userGame->save();
            }

            $importedCount++;
        }

        $user->update(['steam_last_synced_at' => now()]);

        return $importedCount;
    }
}
