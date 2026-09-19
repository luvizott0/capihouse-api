<?php

namespace App\Jobs;

use App\Enums\UserRoles;
use App\Enums\UserStatuses;
use App\Events\PostCreated;
use App\Models\BirthdayPost;
use App\Models\Hashtag;
use App\Models\Post;
use App\Models\User;
use App\Services\MentionService;
use App\Services\NotificationDispatcherService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateUserBirthdayPostJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public int $year
    ) {}

    public function handle(): void
    {
        // 1. Idempotência: verificar se já existe post de aniversário para este usuário neste ano
        if (BirthdayPost::where('user_id', $this->user->id)->where('year', $this->year)->exists()) {
            Log::info("Post de aniversário já gerado para {$this->user->username} em {$this->year}. Dispensando.");

            return;
        }

        // Se o usuário não possui data de nascimento, dispensa
        if (! $this->user->birth) {
            Log::info("Usuário {$this->user->username} não possui data de nascimento cadastrada. Dispensando.");

            return;
        }

        // 2. Identificar usuário da Capivara Rogéria
        $rogeria = User::where('username', 'capivara.rogeria')->first()
            ?? User::where('email', 'capivara@rogeria.com')->first()
            ?? User::where('role', UserRoles::Admin)->first();

        if (! $rogeria) {
            Log::error('Usuário da Capivara Rogéria ou Administrador não encontrado para criar post de aniversário.');

            return;
        }

        // 3. Calcular idade completada no aniversário
        $birthDate = Carbon::parse($this->user->birth);
        $age = (int) $birthDate->diffInYears(Carbon::create($this->year, $birthDate->month, $birthDate->day));

        // 4. Montar o texto da mensagem de parabéns
        $ageText = ($age > 0)
            ? "Completando {$age} anos de muita luz e histórias para contar! "
            : '';

        $content = "🎂 Hoje é um dia muito especial na nossa casa! Parabéns, @{$this->user->username}! 🎉🎈\n\n"
            ."{$ageText}Desejamos que o seu novo ciclo seja repleto de momentos acolhedores, alegrias e muito afeto aqui no CapiHouse! 🌿🛋️\n\n"
            ."Deixem aqui nos comentários seus votos de parabéns para celebrar esse dia tão lindo! ✨🐾\n"
            ."#AniversarioCapiHouse #Parabens #FestaNaToca";

        // 5. Criar publicação no feed
        $post = Post::create([
            'user_id' => $rogeria->id,
            'content' => $content,
        ]);

        // 6. Sentimento temático da postagem
        $post->feeling()->create([
            'name' => 'Celebrando',
            'emoji' => '🎂',
        ]);

        // 7. Sincronizar hashtags
        $hashtags = ['AniversarioCapiHouse', 'Parabens', 'FestaNaToca'];
        foreach ($hashtags as $tagName) {
            $hashtag = Hashtag::firstOrCreate(['name' => $tagName]);
            $post->hashtags()->syncWithoutDetaching([$hashtag->id]);
        }

        // 8. Sincronizar menções (vincula o aniversariante ao post, fazendo aparecer no perfil dele)
        MentionService::syncPostMentions($post, $rogeria);

        // 9. Registrar na tabela de idempotência
        BirthdayPost::create([
            'user_id' => $this->user->id,
            'post_id' => $post->id,
            'year' => $this->year,
            'age' => $age > 0 ? $age : null,
        ]);

        // 10. Transmitir evento em tempo real via WebSocket
        try {
            broadcast(new PostCreated($post))->toOthers();
        } catch (\Throwable $e) {
            Log::warning('Falha ao transmitir broadcast PostCreated para aniversário: '.$e->getMessage());
        }

        // 11. Notificar todos os moradores da casa
        $recipients = User::where('status', UserStatuses::APPROVED)
            ->where('id', '!=', $rogeria->id)
            ->where('id', '!=', $this->user->id)
            ->get();

        foreach ($recipients as $recipient) {
            NotificationDispatcherService::send(
                recipient: $recipient,
                type: 'birthday_post',
                title: "🎂 Hoje é aniversário de {$this->user->name}!",
                content: "Venha deixar seus parabéns para @{$this->user->username} na publicação da Rogéria! 🎉🎈",
                data: [
                    'post_id' => $post->id,
                    'type' => 'birthday_post',
                    'birthday_user_id' => $this->user->id,
                ],
                url: '/posts/'.$post->id
            );
        }

        Log::info("Post de aniversário criado com sucesso para {$this->user->username} (Ano: {$this->year}, Post ID: {$post->id}).");
    }
}
