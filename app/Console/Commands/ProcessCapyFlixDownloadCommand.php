<?php

namespace App\Console\Commands;

use App\Services\CapyFlixDownloadService;
use Illuminate\Console\Command;

class ProcessCapyFlixDownloadCommand extends Command
{
    /**
     * O nome e a assinatura do comando.
     */
    protected $signature = 'capyflix:process-download {id : ID da tarefa de download}';

    /**
     * A descrição do comando.
     */
    protected $description = 'Processa uma tarefa de download de mídia para o NAS em segundo plano';

    /**
     * Executa o comando em background sem limite de tempo de execução.
     */
    public function handle(CapyFlixDownloadService $downloadService): int
    {
        @ini_set('max_execution_time', '0');
        @set_time_limit(0);
        @ignore_user_abort(true);

        $id = $this->argument('id');
        $this->info("Iniciando processamento assíncrono do download {$id}...");

        $downloadService->processDownload($id);

        $this->info("Download {$id} finalizado.");

        return Command::SUCCESS;
    }
}
