<?php

namespace Tests\Feature;

use App\Enums\MediaType;
use App\Enums\UserRoles;
use App\Enums\UserStatuses;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnifiedFeedTest extends TestCase
{
    use RefreshDatabase;

    private function createApprovedUser(): User
    {
        return User::factory()->create([
            'status' => UserStatuses::APPROVED,
            'role' => UserRoles::User,
        ]);
    }

    public function test_feed_returns_all_merged_posts_by_default(): void
    {
        $user = $this->createApprovedUser();

        $textPost = Post::create([
            'user_id' => $user->id,
            'category' => 'feed',
            'content' => 'Post normal de texto',
        ]);

        $moviePost = Post::create([
            'user_id' => $user->id,
            'category' => 'entertainment',
            'entertainment_type' => 'movie',
            'external_source' => 'letterboxd',
            'external_id' => 'guid-movie-1',
            'content' => 'Review de Interestelar',
            'metadata' => ['film_title' => 'Interestelar', 'rating' => 5],
        ]);

        $seriesPost = Post::create([
            'user_id' => $user->id,
            'category' => 'entertainment',
            'entertainment_type' => 'series',
            'external_source' => 'capyshowtime',
            'external_id' => 'guid-series-1',
            'content' => 'Review de Breaking Bad S05E14',
            'metadata' => ['series_name' => 'Breaking Bad', 'rating' => 5],
        ]);

        $gamePost = Post::create([
            'user_id' => $user->id,
            'category' => 'entertainment',
            'entertainment_type' => 'game',
            'external_source' => 'xbox',
            'external_id' => 'guid-game-1',
            'content' => 'Miletado Halo Infinite',
            'metadata' => ['game_title' => 'Halo Infinite', 'rating' => 4.5],
        ]);

        // Feed geral (todas)
        $response = $this->actingAs($user)->getJson('/api/posts');
        $response->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($textPost->id));
        $this->assertTrue($ids->contains($moviePost->id));
        $this->assertTrue($ids->contains($seriesPost->id));
        $this->assertTrue($ids->contains($gamePost->id));
    }

    public function test_feed_can_be_filtered_by_media_tab(): void
    {
        $user = $this->createApprovedUser();

        $textPost = Post::create([
            'user_id' => $user->id,
            'category' => 'feed',
            'content' => 'Post sem mídia',
        ]);

        $mediaPost = Post::create([
            'user_id' => $user->id,
            'category' => 'feed',
            'content' => 'Post com foto',
        ]);
        $mediaPost->media()->create([
            'path' => 'https://example.com/photo.jpg',
            'type' => MediaType::IMAGE,
            'collection_name' => 'posts',
        ]);

        $response = $this->actingAs($user)->getJson('/api/posts?tab=media');
        $response->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($textPost->id));
        $this->assertTrue($ids->contains($mediaPost->id));
    }

    public function test_feed_can_be_filtered_by_text_tab(): void
    {
        $user = $this->createApprovedUser();

        $textPost = Post::create([
            'user_id' => $user->id,
            'category' => 'feed',
            'content' => 'Pensamento do dia: capivaras são seres pacíficos.',
        ]);

        $mediaPost = Post::create([
            'user_id' => $user->id,
            'category' => 'feed',
            'content' => 'Foto das capivaras',
        ]);
        $mediaPost->media()->create([
            'path' => 'https://example.com/capivara.jpg',
            'type' => MediaType::IMAGE,
            'collection_name' => 'posts',
        ]);

        $moviePost = Post::create([
            'user_id' => $user->id,
            'category' => 'entertainment',
            'entertainment_type' => 'movie',
            'external_source' => 'letterboxd',
            'external_id' => 'guid-movie-text-test',
            'content' => 'Review de filme',
            'metadata' => ['film_title' => 'Duna 2'],
        ]);

        $response = $this->actingAs($user)->getJson('/api/posts?tab=text');
        $response->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($textPost->id));
        $this->assertFalse($ids->contains($mediaPost->id));
        $this->assertFalse($ids->contains($moviePost->id));
    }

    public function test_feed_can_be_filtered_by_movies_series_and_games_tabs(): void
    {
        $user = $this->createApprovedUser();

        $moviePost = Post::create([
            'user_id' => $user->id,
            'category' => 'entertainment',
            'entertainment_type' => 'movie',
            'external_source' => 'letterboxd',
            'external_id' => 'guid-movie-2',
            'content' => 'Review de filme',
            'metadata' => ['film_title' => 'Duna 2'],
        ]);

        $seriesPost = Post::create([
            'user_id' => $user->id,
            'category' => 'entertainment',
            'entertainment_type' => 'series',
            'external_source' => 'capyshowtime',
            'external_id' => 'guid-series-2',
            'content' => 'Review de série',
            'metadata' => ['series_name' => 'The Last of Us', 'type' => 'rating', 'rating' => 4.5],
        ]);

        $gamePost = Post::create([
            'user_id' => $user->id,
            'category' => 'entertainment',
            'entertainment_type' => 'game',
            'external_source' => 'xbox',
            'external_id' => 'guid-game-2',
            'content' => 'Análise de jogo',
            'metadata' => ['game_title' => 'The Witcher 3'],
        ]);

        // Movies tab
        $moviesRes = $this->actingAs($user)->getJson('/api/posts?tab=movies');
        $moviesRes->assertStatus(200);
        $movieIds = collect($moviesRes->json('data'))->pluck('id');
        $this->assertTrue($movieIds->contains($moviePost->id));
        $this->assertFalse($movieIds->contains($seriesPost->id));
        $this->assertFalse($movieIds->contains($gamePost->id));

        // Series tab
        $seriesRes = $this->actingAs($user)->getJson('/api/posts?tab=series');
        $seriesRes->assertStatus(200);
        $seriesIds = collect($seriesRes->json('data'))->pluck('id');
        $this->assertFalse($seriesIds->contains($moviePost->id));
        $this->assertTrue($seriesIds->contains($seriesPost->id));
        $this->assertFalse($seriesIds->contains($gamePost->id));

        // Games tab
        $gamesRes = $this->actingAs($user)->getJson('/api/posts?tab=games');
        $gamesRes->assertStatus(200);
        $gameIds = collect($gamesRes->json('data'))->pluck('id');
        $this->assertFalse($gameIds->contains($moviePost->id));
        $this->assertFalse($gameIds->contains($seriesPost->id));
        $this->assertTrue($gameIds->contains($gamePost->id));
    }

    public function test_feed_excludes_unrated_watched_series_episodes(): void
    {
        $user = $this->createApprovedUser();

        // 1. Post de série apenas assistida sem nota (NÃO deve aparecer no feed)
        $unratedWatchedPost = Post::create([
            'user_id' => $user->id,
            'category' => 'entertainment',
            'entertainment_type' => 'series',
            'external_source' => 'capyshowtime',
            'external_id' => 'capyshowtime:ep:unrated',
            'content' => 'Assistiu o episódio S01E01 de Succession.',
            'metadata' => [
                'type' => 'watched',
                'series_name' => 'Succession',
                'rating' => null,
            ],
        ]);

        // 2. Post com nota avaliada (DEVE aparecer)
        $ratedPost = Post::create([
            'user_id' => $user->id,
            'category' => 'entertainment',
            'entertainment_type' => 'series',
            'external_source' => 'capyshowtime',
            'external_id' => 'capyshowtime:ep:rated',
            'content' => 'Assistiu e avaliou o episódio S01E02 de Succession com nota 9 ★.',
            'metadata' => [
                'type' => 'rating',
                'series_name' => 'Succession',
                'rating' => 9.0,
            ],
        ]);

        // 3. Post de comentário (DEVE aparecer)
        $commentPost = Post::create([
            'user_id' => $user->id,
            'category' => 'entertainment',
            'entertainment_type' => 'series',
            'external_source' => 'capyshowtime',
            'external_id' => 'capyshowtime:comment:123',
            'content' => 'Comentou no episódio S01E03: "Que episódio incrível!"',
            'metadata' => [
                'type' => 'comment',
                'series_name' => 'Succession',
            ],
        ]);

        // 4. Post de conclusão de série (DEVE aparecer)
        $completedPost = Post::create([
            'user_id' => $user->id,
            'category' => 'entertainment',
            'entertainment_type' => 'series',
            'external_source' => 'capyshowtime',
            'external_id' => 'capyshowtime:series_completed:1',
            'content' => 'Concluiu a série Succession! 🏆',
            'metadata' => [
                'type' => 'completed',
                'series_name' => 'Succession',
            ],
        ]);

        // Verifica no feed geral
        $resAll = $this->actingAs($user)->getJson('/api/posts');
        $resAll->assertStatus(200);
        $allIds = collect($resAll->json('data'))->pluck('id');

        $this->assertFalse($allIds->contains($unratedWatchedPost->id));
        $this->assertTrue($allIds->contains($ratedPost->id));
        $this->assertTrue($allIds->contains($commentPost->id));
        $this->assertTrue($allIds->contains($completedPost->id));

        // Verifica na aba de séries
        $resSeries = $this->actingAs($user)->getJson('/api/posts?tab=series');
        $resSeries->assertStatus(200);
        $seriesIds = collect($resSeries->json('data'))->pluck('id');

        $this->assertFalse($seriesIds->contains($unratedWatchedPost->id));
        $this->assertTrue($seriesIds->contains($ratedPost->id));
        $this->assertTrue($seriesIds->contains($commentPost->id));
        $this->assertTrue($seriesIds->contains($completedPost->id));
    }
}
