<?php

namespace Database\Seeders;

use App\Enums\UserStatuses;
use App\Models\Post;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class FeelingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Popula pelo menos 1 post com sentimento por dia ao longo de todo o mês atual
     * para permitir testes consistentes do Recap de Sentimentos da Capivara Rogéria.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        // Buscar usuário Bento (ou o primeiro usuário comum aprovado)
        $user = User::where('username', 'bento')->first()
            ?? User::where('status', UserStatuses::APPROVED)
                ->where('username', '!=', 'capivara.rogeria')
                ->first();

        if (! $user) {
            $this->command?->warn('Nenhum usuário comum aprovado encontrado. Execute o UsersSeeder primeiro.');

            return;
        }

        // Lista de sentimentos temáticos da vida de uma capivara
        $feelingsPool = [
            ['name' => 'Feliz', 'emoji' => '😊', 'weight' => 8],
            ['name' => 'Zen', 'emoji' => '🌿', 'weight' => 7],
            ['name' => 'Aconchegado', 'emoji' => '☕', 'weight' => 5],
            ['name' => 'Empolgado', 'emoji' => '🚀', 'weight' => 4],
            ['name' => 'Sonolento', 'emoji' => '😴', 'weight' => 3],
            ['name' => 'Com fome', 'emoji' => '🍉', 'weight' => 3],
            ['name' => 'Refrescado', 'emoji' => '🌊', 'weight' => 2],
            ['name' => 'Grato', 'emoji' => '❤️', 'weight' => 2],
            ['name' => 'Aventureiro', 'emoji' => '🐾', 'weight' => 2],
            ['name' => 'Festeiro', 'emoji' => '🎉', 'weight' => 2],
        ];

        // Expandir a lista ponderada
        $weightedFeelings = [];
        foreach ($feelingsPool as $item) {
            for ($w = 0; $w < $item['weight']; $w++) {
                $weightedFeelings[] = ['name' => $item['name'], 'emoji' => $item['emoji']];
            }
        }

        // Banco de mensagens temáticas para os posts
        $postCaptions = [
            'Tomando um solzinho maravilhoso na beira do lago.',
            'A grama hoje estava especialmente crocante e fresca!',
            'Mergulho rápido nas corredeiras para espantar o calor.',
            'Uma soneca revigorante debaixo da sombra da jaqueira.',
            'Piquenique no gramado com os amigos capivaras.',
            'Observando as vitórias-régias flutuando suavemente.',
            'Café quentinho enquanto a neblina sobe das águas do lago.',
            'Fui cumprimentar as tartarugas na margem leste.',
            'Dia produtivo por aqui, com direito a banho de lama relaxante.',
            'Nada melhor do que sentir a brisa suave no fim de tarde.',
            'Encontrei um canteiro novo de trevos ao lado do bambuzal.',
            'Tarde tranquila meditando perto da cachoeira.',
            'Reunião informal com a turma para planejar o próximo luau.',
            'Mergulho sincronizado matinal com os patos selvagens.',
            'Apreciando as cores douradas de um pôr do sol espetacular.',
            'Mastigando com calma e ouvindo o canto dos passarinhos.',
            'Manhã inspiradora para caminhar pelas trilhas do bosque.',
            'Descansando as patinhas depois de nadar o lago de ponta a ponta.',
            'Dividindo uma melancia bem doce com quem passou por perto.',
            'Boas conversas e risadas à beira d\'água.',
        ];

        $now = Carbon::now();
        $daysInMonth = $now->daysInMonth;
        $totalCreated = 0;

        $this->command?->info("Gerando sentimentos diários para @{$user->username} ao longo de {$daysInMonth} dias de {$now->translatedFormat('F/Y')}...");

        $feelingIndex = 0;
        $captionIndex = 0;

        for ($day = 1; $day <= $daysInMonth; $day++) {
            // Data do post no dia específico do mês
            $postDate = $now->copy()
                ->startOfMonth()
                ->addDays($day - 1)
                ->setHour(rand(9, 18))
                ->setMinute(rand(0, 59))
                ->setSecond(rand(0, 59));

            // Pelo menos 1 post todo dia. Em alguns dias (fins de semana ou dias pares), gera 2 posts
            $postsForDay = ($day % 5 === 0 || $day % 7 === 0) ? 2 : 1;

            for ($p = 0; $p < $postsForDay; $p++) {
                $chosenFeeling = $weightedFeelings[$feelingIndex % count($weightedFeelings)];
                $chosenCaption = $postCaptions[$captionIndex % count($postCaptions)];

                $feelingIndex++;
                $captionIndex++;

                if ($p > 0) {
                    $postDate = $postDate->copy()->addHours(rand(2, 5));
                }

                $post = new Post([
                    'user_id' => $user->id,
                    'content' => $chosenCaption,
                ]);
                $post->created_at = $postDate;
                $post->updated_at = $postDate;
                $post->save();

                $post->feeling()->create([
                    'name' => $chosenFeeling['name'],
                    'emoji' => $chosenFeeling['emoji'],
                ]);

                $totalCreated++;
            }
        }

        $this->command?->info("✅ {$totalCreated} posts com sentimentos criados com sucesso para @{$user->username} cobrindo todos os dias do mês!");
    }
}
