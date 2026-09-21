<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\LetterboxdSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SyncLetterboxdJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(
        public User $user
    ) {}

    public function handle(LetterboxdSyncService $syncService): void
    {
        if (empty($this->user->letterboxd_username)) {
            Cache::forget("letterboxd_syncing_{$this->user->id}");

            return;
        }

        try {
            $imported = $syncService->syncUser($this->user);
            Log::info("Sincronização Letterboxd concluída para {$this->user->username}: {$imported} novos itens.");
        } catch (\Throwable $e) {
            Log::error("Falha no Job de sincronização Letterboxd para {$this->user->username}: {$e->getMessage()}");
        } finally {
            Cache::forget("letterboxd_syncing_{$this->user->id}");
        }
    }
}
