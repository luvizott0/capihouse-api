<?php

namespace App\Console\Commands;

use App\Enums\UserStatuses;
use App\Jobs\GenerateUserMonthlyRecapJob;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;

class GenerateMonthlyRecapCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'capihouse:generate-monthly-recap
                            {--month= : O mês no formato YYYY-MM (padrão: mês atual)}
                            {--user= : ID ou username de um usuário específico para testar/regerar}
                            {--force : Forçar regeração ignorando se já existe recap gerado}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Gera o post de Recap de Sentimentos mensal da Capivara Rogéria para cada usuário';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $yearMonth = $this->option('month') ?: now('America/Sao_Paulo')->format('Y-m');
        $force = (bool) $this->option('force');
        $userFilter = $this->option('user');

        // Validar formato YYYY-MM
        try {
            Carbon::createFromFormat('Y-m', $yearMonth);
        } catch (\Throwable $e) {
            $this->error("Formato de mês inválido: '{$yearMonth}'. Utilize o formato YYYY-MM (ex: 2026-09).");

            return self::FAILURE;
        }

        $this->info("🌿 Iniciando geração de Recap de Sentimentos para {$yearMonth}...");

        $query = User::where('status', UserStatuses::APPROVED)
            ->where('username', '!=', 'capivara.rogeria');

        if ($userFilter) {
            $query->where(function ($q) use ($userFilter) {
                if (is_numeric($userFilter)) {
                    $q->where('id', (int) $userFilter);
                } else {
                    $q->where('username', $userFilter);
                }
            });
        }

        $usersCount = $query->count();

        if ($usersCount === 0) {
            $this->warn('Nenhum usuário elegível encontrado.');

            return self::SUCCESS;
        }

        $this->info("Despachando jobs para {$usersCount} usuário(s)...");

        $query->chunk(100, function ($users) use ($yearMonth, $force) {
            foreach ($users as $user) {
                GenerateUserMonthlyRecapJob::dispatch($user, $yearMonth, $force);
            }
        });

        $this->info("✅ Todos os jobs de recap para {$yearMonth} foram enfileirados com sucesso!");

        return self::SUCCESS;
    }
}
