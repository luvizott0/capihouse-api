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
