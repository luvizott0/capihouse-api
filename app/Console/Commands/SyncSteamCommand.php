<?php

namespace App\Console\Commands;

use App\Jobs\SyncSteamJob;
use App\Models\User;
use Illuminate\Console\Command;

class SyncSteamCommand extends Command
{
    protected $signature = 'steam:sync {--user= : ID ou username de usuário específico} {--now : Executa a sincronização imediatamente de forma síncrona sem fila}';

    protected $description = 'Sincroniza biblioteca e conquistas de usuários conectados à Steam';

    public function handle(): int
    {
        $specificUser = $this->option('user');
        $runNow = (bool) $this->option('now');

        $query = User::where(function ($q) {
            $q->whereNotNull('steam_id')->where('steam_id', '!=', '')
                ->orWhere(function ($q2) {
                    $q2->whereNotNull('steam_username')->where('steam_username', '!=', '');
                });
        })->where('status', 'approved');

        if ($specificUser) {
            $query->where(function ($q) use ($specificUser) {
                $q->where('id', $specificUser)
                    ->orWhere('username', $specificUser);
            });
        }

        $users = $query->get();

        if ($users->isEmpty()) {
            $this->info('Nenhum usuário com Steam conectada encontrado.');

            return Command::SUCCESS;
        }

        $this->info(($runNow ? 'Iniciando sincronização imediata' : 'Enfileirando sincronização')." da Steam para {$users->count()} usuário(s)...");

        foreach ($users as $user) {
            $identifier = $user->steam_id ?: $user->steam_username;
            if ($runNow) {
                SyncSteamJob::dispatchSync($user);
                $this->line(" - Sincronizado agora: {$user->name} ({$identifier})");
            } else {
                SyncSteamJob::dispatch($user);
                $this->line(" - Agendado para: {$user->name} ({$identifier})");
            }
        }

        $this->info('Processo de sincronização da Steam concluído.');

        return Command::SUCCESS;
    }
}
