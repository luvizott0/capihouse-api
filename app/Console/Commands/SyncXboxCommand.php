<?php

namespace App\Console\Commands;

use App\Jobs\SyncXboxJob;
use App\Models\User;
use Illuminate\Console\Command;

class SyncXboxCommand extends Command
{
    protected $signature = 'xbox:sync {--user= : ID ou username de usuário específico} {--now : Executa a sincronização imediatamente de forma síncrona sem fila}';

    protected $description = 'Sincroniza atividades e conquistas de usuários conectados ao Xbox Live';

    public function handle(): int
    {
        $specificUser = $this->option('user');
        $runNow = (bool) $this->option('now');

        $query = User::whereNotNull('xbox_gamertag')
            ->where('xbox_gamertag', '!=', '')
            ->where('status', 'approved');

        if ($specificUser) {
            $query->where(function ($q) use ($specificUser) {
                $q->where('id', $specificUser)
                    ->orWhere('username', $specificUser);
            });
        }

        $users = $query->get();

        if ($users->isEmpty()) {
            $this->info('Nenhum usuário com Xbox conectado encontrado.');

            return Command::SUCCESS;
        }

        $this->info(($runNow ? 'Iniciando sincronização imediata' : 'Enfileirando sincronização')." do Xbox para {$users->count()} usuário(s)...");

        foreach ($users as $user) {
            if ($runNow) {
                SyncXboxJob::dispatchSync($user);
                $this->line(" - Sincronizado agora: {$user->name} ({$user->xbox_gamertag})");
            } else {
                SyncXboxJob::dispatch($user);
                $this->line(" - Agendado para: {$user->name} ({$user->xbox_gamertag})");
            }
        }

        $this->info('Processo de sincronização do Xbox concluído.');

        return Command::SUCCESS;
    }
}
