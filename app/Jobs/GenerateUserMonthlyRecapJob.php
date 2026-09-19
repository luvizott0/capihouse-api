<?php

namespace App\Jobs;

use App\Enums\UserRoles;
use App\Events\PostCreated;
use App\Models\Hashtag;
use App\Models\MonthlyRecap;
use App\Models\Post;
use App\Models\User;
use App\Services\MentionService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateUserMonthlyRecapJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $yearMonth,
        public bool $force = false
    ) {}

    public function handle(): void
    {
        // Verificar idempotência: já gerou recap para este usuário neste mês?
        if (! $this->force) {
            $existing = MonthlyRecap::where('user_id', $this->user->id)
                ->where('year_month', $this->yearMonth)
                ->first();

            if ($existing) {
                Log::info("Recap para o usuário {$this->user->username} em {$this->yearMonth} já foi gerado anteriormente.");

                return;
            }
        }

        $date = Carbon::createFromFormat('Y-m', $this->yearMonth)->startOfMonth();
        $startOfMonth = $date->copy()->startOfMonth();
        $endOfMonth = $date->copy()->endOfMonth();

        // Buscar publicações do usuário que possuem sentimento no mês
        $posts = Post::where('user_id', $this->user->id)
            ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
            ->whereHas('feeling')
            ->with('feeling')
            ->orderBy('created_at', 'asc')
            ->get();

        if ($posts->isEmpty()) {
            // Usuário não possui sentimentos registrados neste mês. Registrar recap vazio para evitar reprocessamento desnecessário.
            MonthlyRecap::updateOrCreate(
                [
                    'user_id' => $this->user->id,
                    'year_month' => $this->yearMonth,
                ],
                [
                    'post_id' => null,
                    'total_feelings' => 0,
                    'emoji_summary' => null,
                    'top_emojis' => [],
                ]
            );

            Log::info("Usuário {$this->user->username} não registrou sentimentos em {$this->yearMonth}. Post dispensado.");

            return;
        }

        // Compilação cronológica dos emojis
        $allEmojis = $posts->map(fn ($p) => $p->feeling?->emoji)->filter()->values();
        $totalFeelings = $allEmojis->count();
        $emojiSequence = $allEmojis->join(' ');

        // Ranking com os 3 emojis mais frequentes
        $counts = $allEmojis->countBy()->sortDesc();
        $top3 = $counts->take(3);

        $monthNames = [
            1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
            5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
            9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro',
        ];
        $monthName = $monthNames[(int) $date->format('n')];
        $year = $date->format('Y');

        // Agrupar publicações por emoji para encontrar os nomes de sentimentos mais frequentes associados
        $feelingsByEmoji = $posts->groupBy(fn ($p) => $p->feeling?->emoji);

        $medals = ['🥇', '🥈', '🥉'];
        $podiumLines = [];
        $topEmojisData = [];
        $i = 0;
        foreach ($top3 as $emoji => $count) {
            $medal = $medals[$i++] ?? '⭐';

            // Identificar o sentimento mais usado em conjunto com este emoji (máximo 15 caracteres)
            $wordsForEmoji = $feelingsByEmoji->get($emoji, collect())
                ->map(fn ($p) => trim($p->feeling?->name ?? ''))
                ->filter()
                ->countBy()
                ->sortDesc();

            $topWord = $wordsForEmoji->keys()->first() ?? '';
            if (mb_strlen($topWord) > 15) {
                $topWord = mb_substr($topWord, 0, 15);
            }

            if ($topWord !== '') {
                $podiumLines[] = "{$medal} {$emoji} {$topWord} — {$count}x";
            } else {
                $podiumLines[] = "{$medal} {$emoji} — {$count}x";
            }

            $topEmojisData[] = [
                'emoji' => $emoji,
                'name' => $topWord !== '' ? $topWord : null,
                'count' => $count,
            ];
        }
        $podiumText = implode("\n", $podiumLines);

        // Montagem da mensagem calorosa da Capivara Rogéria
        $content = "✨ Olá, @{$this->user->username}! O mês de {$monthName} chegou ao fim e eu preparei o seu Recap de Sentimentos! 🐾\n\n"
                 ."📜 Sua jornada de sentimentos no mês:\n"
                 ."{$emojiSequence}\n\n"
                 ."🏆 Pódio dos sentimentos mais frequentes:\n"
                 ."{$podiumText}\n\n"
                 ."Que o próximo mês seja ainda mais acolhedor na nossa casa! 🌿🛋️\n"
                 ."#RecapRogeria #RecapSentimentos #{$monthName}{$year}";

        // Obter usuário da Capivara Rogéria
        $rogeria = User::where('username', 'capivara.rogeria')->first()
            ?? User::where('email', 'capivara@rogeria.com')->first()
            ?? User::where('role', UserRoles::Admin)->first();

        if (! $rogeria) {
            Log::error("Não foi possível encontrar a Capivara Rogéria para publicar o recap de {$this->user->username}.");

            return;
        }

        // Criar publicação da Rogéria
        $post = Post::create([
            'user_id' => $rogeria->id,
            'content' => $content,
        ]);

        // Atribuir sentimento temático ao post da Rogéria
        $post->feeling()->create([
            'name' => 'Nostálgica',
            'emoji' => '📜',
        ]);

        // Sincronizar hashtags
        $hashtags = ['RecapRogeria', 'RecapSentimentos', "{$monthName}{$year}"];
        $hashtagIds = [];
        foreach ($hashtags as $tag) {
            $ht = Hashtag::firstOrCreate(['name' => $tag]);
            $hashtagIds[] = $ht->id;
        }
        $post->hashtags()->sync($hashtagIds);

        // Notificar o usuário mencionado
        MentionService::syncPostMentions($post, $rogeria);

        // Salvar ou atualizar o registro de MonthlyRecap

        MonthlyRecap::updateOrCreate(
            [
                'user_id' => $this->user->id,
                'year_month' => $this->yearMonth,
            ],
            [
                'post_id' => $post->id,
                'total_feelings' => $totalFeelings,
                'emoji_summary' => $emojiSequence,
                'top_emojis' => $topEmojisData,
            ]
        );

        // Disparar broadcast em tempo real com segurança
        try {
            $post->load(['user', 'group:id,name', 'event:id,name', 'media', 'feeling', 'hashtags', 'mentions:id,name,username,avatar_url', 'comments.user', 'likes']);
            broadcast(new PostCreated($post));
        } catch (\Throwable $e) {
            report($e);
        }

        Log::info("Recap de sentimentos criado com sucesso para o usuário {$this->user->username} em {$this->yearMonth} (Post #{$post->id}).");
    }
}
