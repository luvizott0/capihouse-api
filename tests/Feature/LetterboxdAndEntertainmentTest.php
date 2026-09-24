<?php

namespace Tests\Feature;

use App\Enums\UserRoles;
use App\Enums\UserStatuses;
use App\Jobs\SyncLetterboxdJob;
use App\Models\Post;
use App\Models\User;
use App\Services\LetterboxdSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LetterboxdAndEntertainmentTest extends TestCase
{
    use RefreshDatabase;

    private function createApprovedUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'status' => UserStatuses::APPROVED,
            'role' => UserRoles::User,
        ], $attributes));
    }

    public function test_user_can_connect_letterboxd_account_and_dispatches_sync_job()
    {
        Queue::fake();

        $user = $this->createApprovedUser();

        $response = $this->actingAs($user)->postJson('/api/profile/letterboxd/connect', [
            'username' => '@cinemafan',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'letterboxd_username' => 'cinemafan',
        ]);

        Queue::assertPushed(SyncLetterboxdJob::class, function ($job) use ($user) {
            return $job->user->id === $user->id;
        });
    }

    public function test_user_can_disconnect_letterboxd_account()
    {
        $user = $this->createApprovedUser([
            'letterboxd_username' => 'cinemafan',
            'letterboxd_last_synced_at' => now(),
        ]);

        $response = $this->actingAs($user)->postJson('/api/profile/letterboxd/disconnect');

        $response->assertStatus(200);
        $user->refresh();
        $this->assertNull($user->letterboxd_username);
        $this->assertNull($user->letterboxd_last_synced_at);
    }

    public function test_user_can_manually_trigger_sync()
    {
        Queue::fake();

        $user = $this->createApprovedUser([
            'letterboxd_username' => 'cinemafan',
        ]);

        $response = $this->actingAs($user)->postJson('/api/profile/letterboxd/sync');

        $response->assertStatus(200);
        Queue::assertPushed(SyncLetterboxdJob::class);
    }

    public function test_letterboxd_sync_service_imports_reviews_and_avoids_duplicates()
    {
        $fakeRss = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:letterboxd="https://letterboxd.com" xmlns:tmdb="https://themoviedb.org" xmlns:dc="http://purl.org/dc/elements/1.1/">
  <channel>
    <title>Letterboxd - cinemafan</title>
    <link>https://letterboxd.com/cinemafan/</link>
    <item>
      <title>Dune: Part Two, 2024 - ★★★★½</title>
      <link>https://letterboxd.com/cinemafan/film/dune-part-two/</link>
      <guid isPermaLink="false">letterboxd-watch-999999</guid>
      <pubDate>Fri, 20 Sep 2026 15:00:00 +0000</pubDate>
      <letterboxd:watchedDate>2026-09-20</letterboxd:watchedDate>
      <letterboxd:rewatch>No</letterboxd:rewatch>
      <letterboxd:filmTitle>Dune: Part Two</letterboxd:filmTitle>
      <letterboxd:filmYear>2024</letterboxd:filmYear>
      <letterboxd:memberRating>4.5</letterboxd:memberRating>
      <description><![CDATA[ <p><img src="https://a.ltrbxd.com/resized/film-poster/dune-2.jpg"/></p> <p>Obra prima do sci-fi moderno!</p> ]]></description>
      <dc:creator>cinemafan</dc:creator>
    </item>
    <item>
      <title>Ranked: Melhores Sci-Fi</title>
      <link>https://letterboxd.com/cinemafan/list/ranked-scifi/</link>
      <guid isPermaLink="false">letterboxd-list-12345</guid>
      <pubDate>Mon, 01 Jan 2024 10:00:00 +0000</pubDate>
      <description><![CDATA[ <p>Minha lista de filmes favoritos.</p> ]]></description>
      <dc:creator>cinemafan</dc:creator>
    </item>
  </channel>
</rss>
XML;

        Http::fake([
            'https://letterboxd.com/cinemafan/rss/' => Http::response($fakeRss, 200),
        ]);

        $user = $this->createApprovedUser([
            'letterboxd_username' => 'cinemafan',
        ]);

        $service = new LetterboxdSyncService;

        // 1ª execução: deve importar 1 item (ignorando a lista)
        $importedFirst = $service->syncUser($user);
        $this->assertEquals(1, $importedFirst);

        $this->assertDatabaseHas('posts', [
            'user_id' => $user->id,
            'category' => 'entertainment',
            'entertainment_type' => 'movie',
            'external_source' => 'letterboxd',
            'external_id' => 'letterboxd-watch-999999',
            'content' => 'Obra prima do sci-fi moderno!',
        ]);

        $post = Post::where('external_id', 'letterboxd-watch-999999')->first();
        $this->assertNotNull($post);
        $this->assertEquals('Dune: Part Two', $post->metadata['film_title']);
        $this->assertEquals('2024', $post->metadata['film_year']);
        $this->assertEquals(4.5, $post->metadata['rating']);
        $this->assertEquals('https://a.ltrbxd.com/resized/film-poster/dune-2.jpg', $post->metadata['poster_url']);

        // 2ª execução: não deve duplicar o post existente!
        $importedSecond = $service->syncUser($user);
        $this->assertEquals(0, $importedSecond);
        $this->assertEquals(1, Post::where('external_id', 'letterboxd-watch-999999')->count());
    }

    public function test_entertainment_posts_are_filtered_from_general_feed()
    {
        $user = $this->createApprovedUser();

        // Cria 1 post normal de feed
        $feedPost = Post::create([
            'user_id' => $user->id,
            'category' => 'feed',
            'content' => 'Post normal do feed',
        ]);

        // Cria 1 post de entretenimento
        $entertainmentPost = Post::create([
            'user_id' => $user->id,
            'category' => 'entertainment',
            'entertainment_type' => 'movie',
            'external_source' => 'letterboxd',
            'external_id' => 'guid-123',
            'content' => 'Review de filme',
            'metadata' => [
                'film_title' => 'The Matrix',
                'film_year' => '1999',
                'rating' => 5.0,
            ],
        ]);

        // Feed geral (sem parâmetros)
        $generalFeed = $this->actingAs($user)->getJson('/api/posts');
        $generalFeed->assertStatus(200);
        $generalFeedIds = collect($generalFeed->json('data'))->pluck('id');
        $this->assertTrue($generalFeedIds->contains($feedPost->id));
        $this->assertFalse($generalFeedIds->contains($entertainmentPost->id));

        // Feed de entretenimento (category=entertainment)
        $entertainmentFeed = $this->actingAs($user)->getJson('/api/posts?category=entertainment');
        $entertainmentFeed->assertStatus(200);
        $entFeedIds = collect($entertainmentFeed->json('data'))->pluck('id');
        $this->assertFalse($entFeedIds->contains($feedPost->id));
        $this->assertTrue($entFeedIds->contains($entertainmentPost->id));
    }

    public function test_user_can_repost_their_own_entertainment_activity_to_the_feed()
    {
        $author = $this->createApprovedUser();

        $entertainmentPost = Post::create([
            'user_id' => $author->id,
            'category' => 'entertainment',
            'entertainment_type' => 'movie',
            'external_source' => 'letterboxd',
            'external_id' => 'guid-xyz',
            'content' => 'Filme sensacional!',
            'metadata' => [
                'film_title' => 'Interestelar',
                'film_year' => '2014',
                'rating' => 5.0,
            ],
        ]);

        // O próprio autor reposta no feed
        $repostRes = $this->actingAs($author)->postJson('/api/posts', [
            'content' => 'Todo mundo deveria assistir isso de novo',
            'repost_of_id' => $entertainmentPost->id,
        ]);

        $repostRes->assertStatus(201);
        $this->assertDatabaseHas('posts', [
            'user_id' => $author->id,
            'category' => 'feed',
            'repost_of_id' => $entertainmentPost->id,
            'content' => 'Todo mundo deveria assistir isso de novo',
        ]);

        // O post aparece no feed geral com o repostedPost carregado
        $feed = $this->actingAs($author)->getJson('/api/posts');
        $feed->assertStatus(200);
        $repostedInFeed = collect($feed->json('data'))->firstWhere('repost_of_id', $entertainmentPost->id);
        $this->assertNotNull($repostedInFeed);
        $this->assertNotNull($repostedInFeed['reposted_post']);
        $this->assertEquals('Interestelar', $repostedInFeed['reposted_post']['metadata']['film_title']);
    }

    public function test_other_users_can_repost_someone_elses_entertainment_activity_or_post()
    {
        $author = $this->createApprovedUser();
        $otherUser = $this->createApprovedUser();

        $entertainmentPost = Post::create([
            'user_id' => $author->id,
            'category' => 'entertainment',
            'entertainment_type' => 'movie',
            'external_source' => 'letterboxd',
            'external_id' => 'guid-abc',
            'content' => 'Review legal',
        ]);

        $response = $this->actingAs($otherUser)->postJson('/api/posts', [
            'content' => 'Repostando o filme do amigo no meu feed',
            'repost_of_id' => $entertainmentPost->id,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('posts', [
            'id' => $response->json('data.id') ?? $response->json('id'),
            'user_id' => $otherUser->id,
            'repost_of_id' => $entertainmentPost->id,
            'content' => 'Repostando o filme do amigo no meu feed',
        ]);
    }

    public function test_entertainment_posts_are_ordered_by_watched_date_descending()
    {
        $user1 = $this->createApprovedUser(['name' => 'Alice']);
        $user2 = $this->createApprovedUser(['name' => 'Bob']);

        // Bob sincronizou mais recentemente (created_at recente), mas assistiu em 2024
        $bobMovie = Post::create([
            'user_id' => $user2->id,
            'category' => 'entertainment',
            'entertainment_type' => 'movie',
            'external_source' => 'letterboxd',
            'external_id' => 'bob-movie-1',
            'watched_at' => '2024-01-10 00:00:00',
            'metadata' => ['film_title' => 'Old Bob Movie', 'watched_date' => '2024-01-10'],
            'created_at' => '2026-09-20 20:00:00',
        ]);

        // Alice sincronizou antes, mas assistiu recentemente em 2026
        $aliceMovie = Post::create([
            'user_id' => $user1->id,
            'category' => 'entertainment',
            'entertainment_type' => 'movie',
            'external_source' => 'letterboxd',
            'external_id' => 'alice-movie-1',
            'watched_at' => '2026-09-18 00:00:00',
            'metadata' => ['film_title' => 'Recent Alice Movie', 'watched_date' => '2026-09-18'],
            'created_at' => '2026-09-19 10:00:00',
        ]);

        $response = $this->actingAs($user1)->getJson('/api/posts?category=entertainment');
        $response->assertStatus(200);

        $postsData = $response->json('data');
        $this->assertCount(2, $postsData);
        // Alice assistiu em 2026-09-18, logo deve vir PRIMEIRO que o de Bob que assistiu em 2024-01-10
        $this->assertEquals($aliceMovie->id, $postsData[0]['id']);
        $this->assertEquals($bobMovie->id, $postsData[1]['id']);
    }

    public function test_entertainment_posts_can_be_filtered_by_search_query_matching_film_title()
    {
        $user = $this->createApprovedUser();

        Post::create([
            'user_id' => $user->id,
            'category' => 'entertainment',
            'entertainment_type' => 'movie',
            'external_source' => 'letterboxd',
            'external_id' => 'dune-1',
            'metadata' => ['film_title' => 'Dune: Part Two'],
        ]);

        Post::create([
            'user_id' => $user->id,
            'category' => 'entertainment',
            'entertainment_type' => 'movie',
            'external_source' => 'letterboxd',
            'external_id' => 'matrix-1',
            'metadata' => ['film_title' => 'The Matrix'],
        ]);

        $response = $this->actingAs($user)->getJson('/api/posts?category=entertainment&q=Dune');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('Dune: Part Two', $response->json('data.0.metadata.film_title'));
    }

    public function test_entertainment_posts_can_be_filtered_by_watched_date_and_user_id()
    {
        $user1 = $this->createApprovedUser();
        $user2 = $this->createApprovedUser();

        Post::create([
            'user_id' => $user1->id,
            'category' => 'entertainment',
            'entertainment_type' => 'movie',
            'external_source' => 'letterboxd',
            'external_id' => 'watch-today',
            'watched_at' => '2026-09-20 00:00:00',
            'metadata' => ['film_title' => 'Filme Hoje'],
        ]);

        Post::create([
            'user_id' => $user2->id,
            'category' => 'entertainment',
            'entertainment_type' => 'movie',
            'external_source' => 'letterboxd',
            'external_id' => 'watch-yesterday',
            'watched_at' => '2026-09-19 00:00:00',
            'metadata' => ['film_title' => 'Filme Ontem'],
        ]);

        // Filtrar por data
        $resDate = $this->actingAs($user1)->getJson('/api/posts?category=entertainment&date=2026-09-20');
        $resDate->assertStatus(200);
        $this->assertCount(1, $resDate->json('data'));
        $this->assertEquals('Filme Hoje', $resDate->json('data.0.metadata.film_title'));

        // Filtrar por usuário
        $resUser = $this->actingAs($user1)->getJson("/api/posts?category=entertainment&user_id={$user2->id}");
        $resUser->assertStatus(200);
        $this->assertCount(1, $resUser->json('data'));
        $this->assertEquals('Filme Ontem', $resUser->json('data.0.metadata.film_title'));
    }
}
