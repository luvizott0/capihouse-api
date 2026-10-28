<?php

use App\Models\CapyGame;
use App\Models\CapyUserGame;
use App\Services\CapyGamesService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $service = app(CapyGamesService::class);

        // 1. Normalizar plataformas no catálogo de jogos
        $games = CapyGame::all();
        foreach ($games as $game) {
            $platforms = $game->platforms ?: [];
            $normalized = $service->normalizePlatformsArray($platforms);

            // Se tiver apenas 1 plataforma ou se for Tails of Iron / multiplataforma conhecido
            if (count($normalized) <= 1 || str_contains(mb_strtolower($game->title), 'tails of iron')) {
                $known = $service->getKnownPlatformsForGame($game->title);
                if (! empty($known)) {
                    $normalized = $service->normalizePlatformsArray(array_merge($normalized, $known));
                }
            }

            if ($game->platforms !== $normalized) {
                $game->platforms = $normalized;
                $game->save();
            }
        }

        // 2. Normalizar plataformas e histórico de horas nos registros de usuários
        $userGames = CapyUserGame::all();
        foreach ($userGames as $ug) {
            $changed = false;

            if (! empty($ug->platform)) {
                $normPlat = $service->normalizePlatformName($ug->platform);
                if ($ug->platform !== $normPlat) {
                    $ug->platform = $normPlat;
                    $changed = true;
                }
            }

            if (! empty($ug->platform_hours) && is_array($ug->platform_hours)) {
                $normalizedHours = [];
                foreach ($ug->platform_hours as $plat => $hours) {
                    $normKey = $service->normalizePlatformName((string) $plat);
                    $normalizedHours[$normKey] = round(($normalizedHours[$normKey] ?? 0) + (float) $hours, 1);
                }

                if ($ug->platform_hours !== $normalizedHours) {
                    $ug->platform_hours = $normalizedHours;
                    $ug->hours_played = round(array_sum($normalizedHours), 1);
                    $changed = true;
                }
            }

            if ($changed) {
                $ug->save();
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Operação idempotente e não-destrutiva de normalização de dados
    }
};
