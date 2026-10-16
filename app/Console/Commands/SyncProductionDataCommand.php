<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class SyncProductionDataCommand extends Command
{
    protected $signature = 'db:sync-prod 
                            {--force : Executa sem pedir confirmação}
                            {--backup : Cria uma cópia de segurança do banco local antes de sincronizar}';

    protected $description = 'Sincroniza os dados do banco de dados de produção (Homelab MySQL) para o banco local';

    /**
     * Tabelas para sincronizar em ordem lógica de dependência
     */
    protected array $syncTables = [
        // Usuários & Autenticação
        'users',
        'interests',
        'interest_user',
        'personal_access_tokens',

        // Social / Posts / Grupos / Eventos (CapiHouse)
        'feelings',
        'posts',
        'post_likes',
        'post_comments',
        'post_comment_likes',
        'post_mentions',
        'post_comment_mentions',
        'polls',
        'poll_options',
        'poll_votes',
        'hashtags',
        'hashtag_post',
        'groups',
        'group_users',
        'group_messages',
        'events',
        'event_users',
        'media',
        'birthday_posts',
        'monthly_recaps',
        'app_notifications',

        // Séries & Filmes (CapyShowTime / CapyFlix)
        'capy_series',
        'capy_seasons',
        'capy_episodes',
        'capy_episode_comments',
        'capy_episode_comment_likes',
        'capy_user_series',
        'capy_user_episodes',
        'capy_user_watch_progress',

        // Jogos (CapyGames)
        'capy_games',
        'capy_user_games',
        'capy_game_activities',

        // Cápsula do Tempo & Memórias (CapyCaps)
        'capsule_tags',
        'capsule_memories',
        'capsule_participants',
        'capsule_memory_tag',
    ];

    public function handle(): int
    {
        $this->info('===================================================');
        $this->info('  🔄 CapiHouse — Sincronizador de Dados de Produção');
        $this->info('===================================================');

        // Testar conexão de produção
        try {
            $prodPdo = DB::connection('mysql_prod')->getPdo();
            $this->line('  ✅ Conectado ao MySQL de produção no Homelab (192.168.5.254)');
        } catch (\Throwable $e) {
            $this->error('  ❌ Falha ao conectar ao MySQL de produção: '.$e->getMessage());
            $this->warn('  Certifique-se de que está conectado à rede local ou VPN do Homelab.');

            return Command::FAILURE;
        }

        $localConnection = config('database.default');
        $this->line("  📍 Banco de destino local: [{$localConnection}]");

        if (! $this->option('force')) {
            if (! $this->confirm('Isso irá substituir os dados das tabelas locais pelos dados mais recentes de produção. Deseja continuar?', true)) {
                $this->warn('Operação cancelada.');

                return Command::SUCCESS;
            }
        }

        // Fazer backup do banco SQLite atual caso solicitado
        if ($localConnection === 'sqlite' && $this->option('backup')) {
            $sqlitePath = config('database.connections.sqlite.database');
            if (File::exists($sqlitePath)) {
                $backupPath = $sqlitePath.'.backup_'.date('Ymd_His');
                File::copy($sqlitePath, $backupPath);
                $this->info("  💾 Backup local criado em: {$backupPath}");
            }
        }

        $this->newLine();
        $this->info('Iniciando transferência de tabelas...');

        $isSqlite = $localConnection === 'sqlite';

        // Desativar restrições de chaves estrangeiras temporariamente
        if ($isSqlite) {
            DB::statement('PRAGMA foreign_keys = OFF;');
        } else {
            DB::statement('SET FOREIGN_KEY_CHECKS = 0;');
        }

        $totalSyncedRecords = 0;
        $bar = $this->output->createProgressBar(count($this->syncTables));
        $bar->start();

        foreach ($this->syncTables as $table) {
            try {
                // Verificar se a tabela existe na produção
                $tableExists = DB::connection('mysql_prod')->getSchemaBuilder()->hasTable($table);
                if (! $tableExists) {
                    $bar->advance();

                    continue;
                }

                // Verificar se a tabela existe localmente
                if (! DB::connection($localConnection)->getSchemaBuilder()->hasTable($table)) {
                    $bar->advance();

                    continue;
                }

                // Limpar tabela local
                DB::connection($localConnection)->table($table)->delete();

                // Puxar dados em blocos
                $count = 0;
                DB::connection('mysql_prod')->table($table)->orderBy(
                    DB::connection('mysql_prod')->getSchemaBuilder()->hasColumn($table, 'id') ? 'id' : array_keys((array) DB::connection('mysql_prod')->table($table)->first())[0] ?? 'id'
                )->chunk(250, function ($rows) use ($localConnection, $table, &$count) {
                    $records = array_map(function ($row) {
                        return (array) $row;
                    }, $rows->toArray());

                    if (! empty($records)) {
                        DB::connection($localConnection)->table($table)->insert($records);
                        $count += count($records);
                    }
                });

                $totalSyncedRecords += $count;
            } catch (\Throwable $e) {
                $this->error("\nErro ao sincronizar tabela [{$table}]: ".$e->getMessage());
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        // Reativar restrições de chaves estrangeiras
        if ($isSqlite) {
            DB::statement('PRAGMA foreign_keys = ON;');
        } else {
            DB::statement('SET FOREIGN_KEY_CHECKS = 1;');
        }

        $this->info("🎉 Sincronização concluída com sucesso! {$totalSyncedRecords} registros sincronizados.");
        $this->info('Seu ambiente local agora possui dados reais e idênticos aos de produção.');

        return Command::SUCCESS;
    }
}
