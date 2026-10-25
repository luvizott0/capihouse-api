<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\SteamSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SyncSteamJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(
        public User $user
    ) {}

    public function handle(SteamSyncService $syncService): void
    {
        if (empty($this->user->steam_id) && empty($this->user->steam_username)) {
            Cache::forget("steam_syncing_{$this->user->id}");

            return;
        }

        try {
            $imported = $syncService->sync($this->user);
            Log::info("Sincronização Steam concluída para {$this->user->username}: {$imported} jogos atualizados/adicionados.");
        } catch (\Throwable $e) {
            Log::error("Falha no Job de sincronização Steam para {$this->user->username}: {$e->getMessage()}");
        } finally {
            Cache::forget("steam_syncing_{$this->user->id}");
        }
    }
}
