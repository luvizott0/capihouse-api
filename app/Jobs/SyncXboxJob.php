<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\XboxSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SyncXboxJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(
        public User $user
    ) {}

    public function handle(XboxSyncService $syncService): void
    {
        if (empty($this->user->xbox_gamertag)) {
            Cache::forget("xbox_syncing_{$this->user->id}");

            return;
        }

        try {
            $imported = $syncService->sync($this->user);
            Log::info("Sincronização Xbox concluída para {$this->user->username}: {$imported} jogos atualizados/adicionados.");
        } catch (\Throwable $e) {
            Log::error("Falha no Job de sincronização Xbox para {$this->user->username}: {$e->getMessage()}");
        } finally {
            Cache::forget("xbox_syncing_{$this->user->id}");
        }
    }
}
