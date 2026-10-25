<?php

namespace App\Console\Commands;

use App\Models\CapyUserGame;
use Illuminate\Console\Command;

class CapyGamesFixSteamStatusCommand extends Command
{
    protected $signature = 'capygames:fix-steam-status';

    protected $description = 'Corrige o status de jogos importados da Steam que ficaram marcados erroneamente como Zerados (100%)';

    public function handle(): int
    {
        $games = CapyUserGame::where('source', 'steam')
            ->where('status', 'completed')
            ->whereNull('completed_at')
            ->whereNull('rating')
            ->whereNull('review')
            ->get();

        if ($games->isEmpty()) {
            $this->info('Nenhum jogo Steam com status incorreto encontrado.');

            return Command::SUCCESS;
        }

        $this->info("Corrigindo status de {$games->count()} jogo(s) da Steam...");

        $fixed = 0;
        foreach ($games as $userGame) {
            $totalAch = $userGame->achievements_total ?? 0;
            $earnedAch = $userGame->achievements_earned ?? 0;
            $isMastered = ($totalAch > 0 && $earnedAch >= $totalAch);

            $isRecent = $userGame->updated_at && $userGame->updated_at->gte(now()->subDays(30));

            if ($isMastered) {
                $userGame->status = 'mastered';
            } elseif ($isRecent) {
                $userGame->status = 'playing';
            } else {
                $userGame->status = 'on_hold';
            }

            $userGame->save();
            $fixed++;
        }

        $this->info("Sucesso: {$fixed} jogo(s) corrigidos para status adequado ('on_hold', 'playing' ou 'mastered').");

        return Command::SUCCESS;
    }
}
