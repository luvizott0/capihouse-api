<?php

namespace Database\Seeders;

use App\Enums\MediaType;
use App\Models\Feeling;
use App\Models\Hashtag;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\PostLike;
use App\Models\User;
use Illuminate\Database\Seeder;

class FeedPostsSeeder extends Seeder
{
    public function run(): void
    {
        $rogeria = User::where('username', 'capivara.rogeria')->first() ?? User::first();
        $bento = User::where('username', 'bento')->first() ?? $rogeria;
        $pipoca = User::where('username', 'pipoca')->first() ?? $rogeria;

        if (! $rogeria) {
            return;
        }

        // Limpar posts antigos deste seeder para permitir re-execução limpa
        Post::whereIn('external_id', [
            'letterboxd-seed-interstellar',
            'letterboxd-seed-dune2',
            'capyshowtime:ep:seed:breakingbad',
            'capyshowtime:comment:seed:thelastofus',
            'xbox:seed:halo',
            'manual:seed:witcher3',
            'xbox-seed-halo-infinite',
            'game-review-seed-elden-ring',
        ])->delete();

        // Hashtags
        $tagCapivara = Hashtag::firstOrCreate(['name' => 'capivara']);
        $tagCinema = Hashtag::firstOrCreate(['name' => 'cinema']);
        $tagSeries = Hashtag::firstOrCreate(['name' => 'series']);
        $tagGames = Hashtag::firstOrCreate(['name' => 'games']);

        // 1. Post Tradicional (Texto simples com sentimento e hashtags)
        $traditionalPost = Post::create([
            'user_id' => $rogeria->id,
            'category' => 'feed',
            'content' => 'Bom dia, família CapiHouse! O sol está lindo hoje na beira da lagoa. Quem vem tomar um tereré mais tarde? #capivara',
            'created_at' => now()->subHours(8),
            'updated_at' => now()->subHours(8),
        ]);
        $traditionalPost->hashtags()->sync([$tagCapivara->id]);

        $happyFeeling = Feeling::where('name', 'Feliz')->first();
        if ($happyFeeling) {
            $traditionalPost->feeling()->create([
                'name' => $happyFeeling->name,
                'emoji' => $happyFeeling->emoji,
            ]);
        }

        // 2. Post com Mídias Anexadas (Fotos)
        $mediaPost = Post::create([
            'user_id' => $bento->id,
            'category' => 'feed',
            'content' => 'Flagra de hoje cedo: um momento de paz absoluto contemplando as águas calmas. Nada melhor do que a natureza! 🌿🌊',
            'created_at' => now()->subHours(6),
            'updated_at' => now()->subHours(6),
        ]);
        $mediaPost->hashtags()->sync([$tagCapivara->id]);

        $mediaPost->media()->create([
            'path' => 'https://images.unsplash.com/photo-1590422157053-d144c979d380?auto=format&fit=crop&w=1200&q=80',
            'type' => MediaType::IMAGE,
            'collection_name' => 'posts',
        ]);
        $mediaPost->media()->create([
            'path' => 'https://images.unsplash.com/photo-1548767797-d8c844163c4c?auto=format&fit=crop&w=1200&q=80',
            'type' => MediaType::IMAGE,
            'collection_name' => 'posts',
        ]);

        // 3. Post de Filme (Letterboxd)
        $moviePost1 = Post::create([
            'user_id' => $pipoca->id,
            'category' => 'entertainment',
            'entertainment_type' => 'movie',
            'external_source' => 'letterboxd',
            'external_id' => 'letterboxd-seed-interstellar',
            'watched_at' => now()->subHours(5),
            'content' => 'Uma experiência sensorial cinematográfica incomparável. A sequência do buraco negro Gargantua e a trilha sonora épica do Hans Zimmer continuam me deixando arrepiada a cada reassistida!',
            'metadata' => [
                'film_title' => 'Interestelar',
                'film_year' => '2014',
                'rating' => 5.0,
                'rewatch' => true,
                'watched_date' => now()->subHours(5)->format('Y-m-d'),
                'poster_url' => 'https://image.tmdb.org/t/p/w500/gEU2QniE6E77NI6lCU6MxlNBvIx.jpg',
                'letterboxd_url' => 'https://letterboxd.com/film/interstellar/',
                'review_text' => 'Uma experiência sensorial cinematográfica incomparável. A sequência do buraco negro Gargantua e a trilha sonora épica do Hans Zimmer continuam me deixando arrepiada a cada reassistida!',
            ],
            'created_at' => now()->subHours(5),
            'updated_at' => now()->subHours(5),
        ]);
        $moviePost1->hashtags()->sync([$tagCinema->id]);

        // 4. Segundo Post de Filme (Duna: Parte 2)
        $moviePost2 = Post::create([
            'user_id' => $rogeria->id,
            'category' => 'entertainment',
            'entertainment_type' => 'movie',
            'external_source' => 'letterboxd',
            'external_id' => 'letterboxd-seed-dune2',
            'watched_at' => now()->subHours(4),
            'content' => 'Denis Villeneuve entregou tudo e mais um pouco. Fotografia e design de som em escala monumental. Cinema de altíssimo calibre!',
            'metadata' => [
                'film_title' => 'Duna: Parte 2',
                'film_year' => '2024',
                'rating' => 4.5,
                'rewatch' => false,
                'watched_date' => now()->subHours(4)->format('Y-m-d'),
                'poster_url' => 'https://image.tmdb.org/t/p/w500/8b8R8l88Qje9dn9OE8PY05Nxl1X.jpg',
                'letterboxd_url' => 'https://letterboxd.com/film/dune-part-two/',
                'review_text' => 'Denis Villeneuve entregou tudo e mais um pouco. Fotografia e design de som em escala monumental. Cinema de altíssimo calibre!',
            ],
            'created_at' => now()->subHours(4),
            'updated_at' => now()->subHours(4),
        ]);
        $moviePost2->hashtags()->sync([$tagCinema->id]);

        // 5. Post de Série (CapyShowTime - Avaliação de episódio)
        $seriesPost1 = Post::create([
            'user_id' => $bento->id,
            'category' => 'entertainment',
            'entertainment_type' => 'series',
            'external_source' => 'capyshowtime',
            'external_id' => 'capyshowtime:ep:seed:breakingbad',
            'watched_at' => now()->subHours(3),
            'content' => 'Provavelmente o melhor episódio de televisão de todos os tempos. Atuações viscerais e direção impecável.',
            'metadata' => [
                'type' => 'rating',
                'series_id' => 1,
                'tmdb_id' => 1396,
                'series_name' => 'Breaking Bad',
                'season_number' => 5,
                'episode_number' => 14,
                'episode_name' => 'Ozymandias',
                'poster_url' => 'https://image.tmdb.org/t/p/w500/ggFHVNu6YYI5L9pCfOacjizRGt.jpg',
                'rating' => 5.0,
                'watched_date' => now()->subHours(3)->format('Y-m-d'),
            ],
            'created_at' => now()->subHours(3),
            'updated_at' => now()->subHours(3),
        ]);
        $seriesPost1->hashtags()->sync([$tagSeries->id]);

        // 6. Post de Série (CapyShowTime - Comentário)
        $seriesPost2 = Post::create([
            'user_id' => $pipoca->id,
            'category' => 'entertainment',
            'entertainment_type' => 'series',
            'external_source' => 'capyshowtime',
            'external_id' => 'capyshowtime:comment:seed:thelastofus',
            'watched_at' => now()->subHours(2),
            'content' => 'Comentou no episódio S01E03 (Long, Long Time) de The Last of Us: "Chorei do início ao fim com a história do Bill e do Frank. Que episódio emocionante!"',
            'metadata' => [
                'type' => 'comment',
                'series_id' => 2,
                'tmdb_id' => 100088,
                'series_name' => 'The Last of Us',
                'season_number' => 1,
                'episode_number' => 3,
                'episode_name' => 'Long, Long Time',
                'poster_url' => 'https://image.tmdb.org/t/p/w500/uKvVjHNqB5VmOrdxqAt2V7JMrRI.jpg',
                'rating' => 5.0,
                'watched_date' => now()->subHours(2)->format('Y-m-d'),
                'is_spoiler' => false,
            ],
            'created_at' => now()->subHours(2),
            'updated_at' => now()->subHours(2),
        ]);
        $seriesPost2->hashtags()->sync([$tagSeries->id]);

        // 7. Post de Jogo (Xbox Live - 100% Miletado)
        $gamePost1 = Post::create([
            'user_id' => $rogeria->id,
            'category' => 'entertainment',
            'entertainment_type' => 'game',
            'external_source' => 'xbox',
            'external_id' => 'xbox:seed:halo',
            'watched_at' => now()->subHours(1),
            'content' => 'Conquistei todas as 50 conquistas e fechei os 1000G! O gancho retrátil mudou totalmente a dinâmica do combate. Miletado com muito orgulho capivara!',
            'metadata' => [
                'game_title' => 'Halo Infinite',
                'platform' => 'Xbox Series X|S',
                'game_status' => 'mastered',
                'rating' => 4.5,
                'hours_played' => 62.0,
                'gamerscore' => 1000,
                'gamerscore_total' => 1000,
                'achievements_current' => 50,
                'achievements_total' => 50,
                'progress_percentage' => 100,
                'box_art_url' => 'https://images.igdb.com/igdb/image/upload/t_cover_big/co2dto.jpg',
                'review_text' => 'Conquistei todas as 50 conquistas e fechei os 1000G! O gancho retrátil mudou totalmente a dinâmica do combate. Miletado com muito orgulho capivara!',
            ],
            'created_at' => now()->subHours(1),
            'updated_at' => now()->subHours(1),
        ]);
        $gamePost1->hashtags()->sync([$tagGames->id]);

        // 8. Post de Jogo (Análise Manual na Casa)
        $gamePost2 = Post::create([
            'user_id' => $bento->id,
            'category' => 'entertainment',
            'entertainment_type' => 'game',
            'external_source' => 'manual',
            'external_id' => 'manual:seed:witcher3',
            'watched_at' => now()->subMinutes(30),
            'content' => 'Depois de mais de 120 horas desbravando Velen, Novigrad e Skellige, posso dizer com certeza que é um dos melhores RPGs já criados. A trilha sonora e os diálogos são inesquecíveis.',
            'metadata' => [
                'game_title' => 'The Witcher 3: Wild Hunt',
                'platform' => 'PC',
                'game_status' => 'completed',
                'rating' => 5.0,
                'hours_played' => 128.5,
                'box_art_url' => 'https://images.igdb.com/igdb/image/upload/t_cover_big/co1wyy.jpg',
                'review_text' => 'Depois de mais de 120 horas desbravando Velen, Novigrad e Skellige, posso dizer com certeza que é um dos melhores RPGs já criados. A trilha sonora e os diálogos são inesquecíveis.',
            ],
            'created_at' => now()->subMinutes(30),
            'updated_at' => now()->subMinutes(30),
        ]);
        $gamePost2->hashtags()->sync([$tagGames->id]);

        // Interações: Curtidas e Comentários para testes
        $allPosts = [$traditionalPost, $mediaPost, $moviePost1, $moviePost2, $seriesPost1, $seriesPost2, $gamePost1, $gamePost2];
        $allUsers = [$rogeria, $bento, $pipoca];

        foreach ($allPosts as $post) {
            // Adiciona curtida de pelo menos 1 outro usuário
            $liker = collect($allUsers)->first(fn ($u) => $u->id !== $post->user_id) ?? $rogeria;
            PostLike::firstOrCreate([
                'post_id' => $post->id,
                'user_id' => $liker->id,
            ]);
            $post->update(['likes_count' => 1]);

            // Adiciona comentário de teste
            $commenter = collect($allUsers)->reverse()->first(fn ($u) => $u->id !== $post->user_id) ?? $pipoca;
            PostComment::create([
                'post_id' => $post->id,
                'user_id' => $commenter->id,
                'content' => 'Sensacional essa publicação! Muito bom ver isso aqui na CapiHouse 👏🐾',
            ]);
            $post->update(['comments_count' => 1]);
        }
    }
}
