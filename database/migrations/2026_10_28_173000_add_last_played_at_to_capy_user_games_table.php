<?php

use App\Models\CapyUserGame;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('capy_user_games', 'last_played_at')) {
            Schema::table('capy_user_games', function (Blueprint $table) {
                $table->timestamp('last_played_at')->nullable()->after('completed_at')->index();
            });
        }

        // Popula last_played_at e corrige timestamps artificiais
        $userGames = CapyUserGame::with('game')->get();
        foreach ($userGames as $ug) {
            $ug->timestamps = false;

            if ($ug->source === 'xbox') {
                // Jogos do Xbox utilizam a data real de quando foram jogados
                $ug->last_played_at = $ug->updated_at ?: $ug->created_at;
            } elseif ($ug->source === 'steam') {
                $raw = $ug->game?->raw_data ?? [];
                $rtime = (int) ($raw['rtime_last_played'] ?? 0);

                if ($rtime > 0) {
                    $playedDate = Carbon::createFromTimestamp($rtime);
                    $ug->last_played_at = $playedDate;
                    $ug->updated_at = $playedDate;
                } else {
                    // Jogo da Steam sem registro de data recente não deve simular que foi jogado hoje
                    $ug->last_played_at = null;
                    if ($ug->status === 'on_hold') {
                        $ug->updated_at = $ug->created_at ?: now()->subMonths(2);
                    }
                }
            } else {
                $ug->last_played_at = $ug->updated_at;
            }

            $ug->save();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('capy_user_games', 'last_played_at')) {
            Schema::table('capy_user_games', function (Blueprint $table) {
                $table->dropColumn('last_played_at');
            });
        }
    }
};
