<?php

namespace App\Console\Commands;

use App\Enums\UserStatuses;
use App\Jobs\GenerateUserBirthdayPostJob;
use App\Models\BirthdayPost;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;

class GenerateBirthdayPostsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'capihouse:generate-birthday-posts 
                            {--user= : Filtrar por ID ou username de usuário específico}
                            {--date= : Data de referência no formato YYYY-MM-DD (padrão: hoje em America/Sao_Paulo)}
                            {--force : Forçar geração mesmo se já existir registro para este ano}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verifica os aniversariantes do dia e enfileira a publicação comemorativa da Capivara Rogéria';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dateInput = $this->option('date');
        $force = (bool) $this->option('force');
        $userFilter = $this->option('user');

        try {
            $refDate = $dateInput
                ? Carbon::createFromFormat('Y-m-d', $dateInput, 'America/Sao_Paulo')->startOfDay()
                : now('America/Sao_Paulo')->startOfDay();
        } catch (\Throwable $e) {
            $this->error("Data inválida: '{$dateInput}'. Utilize o formato YYYY-MM-DD (ex: 2026-09-19).");

            return self::FAILURE;
        }

        $month = $refDate->month;
        $day = $refDate->day;
        $year = $refDate->year;

        $this->info("🌿 Verificando aniversariantes para a data {$refDate->format('d/m/Y')}...");

        $query = User::where('status', UserStatuses::APPROVED)
            ->where('username', '!=', 'capivara.rogeria')
            ->whereNotNull('birth');

        if ($userFilter) {
            $query->where(function ($q) use ($userFilter) {
                if (is_numeric($userFilter)) {
                    $q->where('id', (int) $userFilter);
                } else {
                    $q->where('username', $userFilter);
                }
            });
        }

        $candidates = $query->get();
        $birthdayUsers = $candidates->filter(function (User $user) use ($month, $day, $refDate) {
            if (! $user->birth) {
                return false;
            }
            $birthDate = Carbon::parse($user->birth);

            // Aniversário no mesmo dia e mês
            if ($birthDate->month === $month && $birthDate->day === $day) {
                return true;
            }

            // Tratamento de anos bissextos: nascidos em 29/02 comemoram em 28/02 em anos não bissextos
            if ($month === 2 && $day === 28 && ! $refDate->isLeapYear()) {
                if ($birthDate->month === 2 && $birthDate->day === 29) {
                    return true;
                }
            }

            return false;
        });

        if ($birthdayUsers->isEmpty()) {
            $this->info('Nenhum aniversariante encontrado para hoje.');

            return self::SUCCESS;
        }

        $this->info("🎂 Aniversariante(s) encontrado(s): {$birthdayUsers->count()}");

        $dispatched = 0;
        foreach ($birthdayUsers as $user) {
            $alreadyGenerated = BirthdayPost::where('user_id', $user->id)
                ->where('year', $year)
                ->exists();

            if ($alreadyGenerated) {
                if ($force) {
                    $this->warn("⚠️ Removendo registro anterior de aniversário para {$user->name} (--force ativo)...");
                    BirthdayPost::where('user_id', $user->id)->where('year', $year)->delete();
                } else {
                    $this->line("ℹ️ Post de aniversário já gerado para {$user->name} (@{$user->username}) em {$year}. Pulando.");
                    continue;
                }
            }

            $this->line("🎉 Enfileirando post de parabéns para: {$user->name} (@{$user->username})...");
            GenerateUserBirthdayPostJob::dispatch($user, $year);
            $dispatched++;
        }

        $this->info("✅ {$dispatched} job(s) de aniversário enfileirado(s) com sucesso!");

        return self::SUCCESS;
    }
}
