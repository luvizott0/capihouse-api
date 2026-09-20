<?php

namespace App\Console\Commands;

use App\Jobs\SyncLetterboxdJob;
use App\Models\User;
use Illuminate\Console\Command;

class SyncLetterboxdCommand extends Command
{
    protected $signature = 'letterboxd:sync {--user= : ID ou username de usuário específico}';

    protected $description = 'Sincroniza avaliações e atividades de usuários conectados ao Letterboxd';

    public function handle(): int
    {
        $specificUser = $this->option('user');

        $query = User::whereNotNull('letterboxd_username')
            ->where('letterboxd_username', '!=', '')
            ->where('status', 'approved');

        if ($specificUser) {
            $query->where(function ($q) use ($specificUser) {
                $q->where('id', $specificUser)
                    ->orWhere('username', $specificUser);
            });
        }

        $users = $query->get();

        if ($users->isEmpty()) {
            $this->info('Nenhum usuário com Letterboxd conectado encontrado.');

            return Command::SUCCESS;
        }

        $this->info("Enfileirando sincronização do Letterboxd para {$users->count()} usuário(s)...");

        foreach ($users as $user) {
            SyncLetterboxdJob::dispatch($user);
            $this->line(" - Agendado para: {$user->name} (@{$user->letterboxd_username})");
        }

        $this->info('Todos os jobs de sincronização foram enfileirados com sucesso.');

        return Command::SUCCESS;
    }
}
