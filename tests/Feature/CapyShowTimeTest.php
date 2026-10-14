<?php

namespace Tests\Feature;

use App\Models\CapyEpisode;
use App\Models\CapyEpisodeComment;
use App\Models\CapySeason;
use App\Models\CapySeries;
use App\Models\CapyUserSeries;
use App\Models\User;
use App\Services\CapyShowTimeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CapyShowTimeTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected CapyShowTimeService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'status' => 'approved',
        ]);

        $this->service = app(CapyShowTimeService::class);
    }

    public function test_rating_calculation_rule(): void
    {
        // 1. Criar uma série de teste
        $series = CapySeries::create([
            'tmdb_id' => 99999,
            'name' => 'Capy Show Test',
            'number_of_seasons' => 2,
        ]);

        // 2. Temporada 1 com 2 episódios
        $season1 = CapySeason::create([
            'series_id' => $series->id,
            'season_number' => 1,
            'name' => 'Temporada 1',
        ]);

        $ep1_1 = CapyEpisode::create([
            'series_id' => $series->id,
            'season_id' => $season1->id,
            'season_number' => 1,
            'episode_number' => 1,
            'name' => 'Episódio 1x1',
        ]);

        $ep1_2 = CapyEpisode::create([
            'series_id' => $series->id,
            'season_id' => $season1->id,
            'season_number' => 1,
            'episode_number' => 2,
            'name' => 'Episódio 1x2',
        ]);

        // Temporada 2 com 1 episódio
        $season2 = CapySeason::create([
            'series_id' => $series->id,
            'season_number' => 2,
            'name' => 'Temporada 2',
        ]);

        $ep2_1 = CapyEpisode::create([
            'series_id' => $series->id,
            'season_id' => $season2->id,
            'season_number' => 2,
            'episode_number' => 1,
            'name' => 'Episódio 2x1',
        ]);

        // Avaliar ep 1_1 com 8.0 e ep 1_2 com 10.0
        // Média da Temporada 1 esperada: (8.0 + 10.0) / 2 = 9.0
        $this->service->rateOrWatchEpisode($this->user, $ep1_1, 8.0);
        $this->service->rateOrWatchEpisode($this->user, $ep1_2, 10.0);

        // Avaliar ep 2_1 com 7.0
        // Média da Temporada 2 esperada: 7.0
        $this->service->rateOrWatchEpisode($this->user, $ep2_1, 7.0);

        // Média da Série esperada = média das médias das temporadas = (9.0 + 7.0) / 2 = 8.0
        $ratings = $this->service->getUserSeriesRatings($this->user->id, $series->id);

        $this->assertEquals(9.0, $ratings['seasons'][1]['average']);
        $this->assertEquals(7.0, $ratings['seasons'][2]['average']);
        $this->assertEquals(8.0, $ratings['series_average']);
    }

    public function test_user_can_comment_and_like(): void
    {
        $series = CapySeries::create([
            'tmdb_id' => 88888,
            'name' => 'Series Debate Test',
        ]);

        $season = CapySeason::create([
            'series_id' => $series->id,
            'season_number' => 1,
        ]);

        $episode = CapyEpisode::create([
            'series_id' => $series->id,
            'season_id' => $season->id,
            'season_number' => 1,
            'episode_number' => 1,
            'name' => 'Episódio do Spoiler',
        ]);

        // Postar comentário
        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/capyshowtime/episodes/comments', [
            'episode_id' => $episode->id,
            'content' => 'Que reviravolta impressionante!',
            'is_spoiler' => true,
        ]);

        $response->assertStatus(201);
        $commentId = $response->json('id');
        $this->assertTrue($response->json('is_spoiler'));

        // Curtir comentário
        $likeResponse = $this->actingAs($this->user, 'sanctum')->postJson("/api/capyshowtime/comments/{$commentId}/like");
        $likeResponse->assertStatus(200);
        $this->assertTrue($likeResponse->json('liked'));
        $this->assertEquals(1, $likeResponse->json('likes_count'));
    }

    public function test_user_can_mark_watched_without_rating(): void
    {
        $series = CapySeries::create([
            'tmdb_id' => 77777,
            'name' => 'Watch Without Rating Test',
        ]);

        $season = CapySeason::create([
            'series_id' => $series->id,
            'season_number' => 1,
        ]);

        $episode = CapyEpisode::create([
            'series_id' => $series->id,
            'season_id' => $season->id,
            'season_number' => 1,
            'episode_number' => 1,
            'name' => 'Episódio sem nota',
        ]);

        // Marcar como visto sem nota (rating omitido ou null)
        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/capyshowtime/episodes/rate', [
            'episode_id' => $episode->id,
            'is_watched' => true,
        ]);

        $response->assertStatus(200);
        $this->assertTrue($response->json('progress.is_watched'));
        $this->assertNull($response->json('progress.rating'));

        $this->assertDatabaseHas('capy_user_episodes', [
            'user_id' => $this->user->id,
            'episode_id' => $episode->id,
            'is_watched' => 1,
            'rating' => null,
        ]);
    }

    public function test_user_can_mark_entire_season_as_watched(): void
    {
        $series = CapySeries::create([
            'tmdb_id' => 66666,
            'name' => 'Season Watch All Test',
            'number_of_seasons' => 1,
            'synced_at' => now(),
        ]);

        $season = CapySeason::create([
            'series_id' => $series->id,
            'season_number' => 1,
            'name' => 'Temporada 1',
        ]);

        $ep1 = CapyEpisode::create([
            'series_id' => $series->id,
            'season_id' => $season->id,
            'season_number' => 1,
            'episode_number' => 1,
            'name' => 'Episódio 1',
        ]);

        $ep2 = CapyEpisode::create([
            'series_id' => $series->id,
            'season_id' => $season->id,
            'season_number' => 1,
            'episode_number' => 2,
            'name' => 'Episódio 2',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->postJson("/api/capyshowtime/series/{$series->tmdb_id}/season/1/watch-all");

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'Temporada marcada como assistida',
            'season_number' => 1,
        ]);

        $this->assertDatabaseHas('capy_user_episodes', [
            'user_id' => $this->user->id,
            'episode_id' => $ep1->id,
            'is_watched' => 1,
        ]);

        $this->assertDatabaseHas('capy_user_episodes', [
            'user_id' => $this->user->id,
            'episode_id' => $ep2->id,
            'is_watched' => 1,
        ]);
    }

    public function test_user_can_mark_previous_episodes_as_watched(): void
    {
        $series = CapySeries::create([
            'tmdb_id' => 55555,
            'name' => 'Previous Episodes Watch Test',
            'number_of_seasons' => 1,
            'synced_at' => now(),
        ]);

        $season = CapySeason::create([
            'series_id' => $series->id,
            'season_number' => 1,
            'name' => 'Temporada 1',
        ]);

        $ep1 = CapyEpisode::create([
            'series_id' => $series->id,
            'season_id' => $season->id,
            'season_number' => 1,
            'episode_number' => 1,
            'name' => 'Ep 1',
        ]);

        $ep2 = CapyEpisode::create([
            'series_id' => $series->id,
            'season_id' => $season->id,
            'season_number' => 1,
            'episode_number' => 2,
            'name' => 'Ep 2',
        ]);

        $ep3 = CapyEpisode::create([
            'series_id' => $series->id,
            'season_id' => $season->id,
            'season_number' => 1,
            'episode_number' => 3,
            'name' => 'Ep 3',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/capyshowtime/episodes/watch-previous', [
            'episode_id' => $ep2->id,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'Episódios anteriores marcados como assistidos',
            'target_episode_id' => $ep2->id,
        ]);

        // Ep 1 e Ep 2 devem estar assistidos
        $this->assertDatabaseHas('capy_user_episodes', [
            'user_id' => $this->user->id,
            'episode_id' => $ep1->id,
            'is_watched' => 1,
        ]);

        $this->assertDatabaseHas('capy_user_episodes', [
            'user_id' => $this->user->id,
            'episode_id' => $ep2->id,
            'is_watched' => 1,
        ]);

        // Ep 3 NÃO deve ter sido marcado
        $this->assertDatabaseMissing('capy_user_episodes', [
            'user_id' => $this->user->id,
            'episode_id' => $ep3->id,
            'is_watched' => 1,
        ]);
    }

    public function test_community_activity_only_shows_relevant_content(): void
    {
        $series = CapySeries::create([
            'tmdb_id' => 77777,
            'name' => 'Feed Test Series',
            'number_of_seasons' => 1,
            'synced_at' => now(),
        ]);

        $season = CapySeason::create([
            'series_id' => $series->id,
            'season_number' => 1,
            'name' => 'Temporada 1',
        ]);

        $epUnrated = CapyEpisode::create([
            'series_id' => $series->id,
            'season_id' => $season->id,
            'season_number' => 1,
            'episode_number' => 1,
            'name' => 'Episódio Sem Nota',
        ]);

        $epRated = CapyEpisode::create([
            'series_id' => $series->id,
            'season_id' => $season->id,
            'season_number' => 1,
            'episode_number' => 2,
            'name' => 'Episódio Com Nota',
        ]);

        $epCommented = CapyEpisode::create([
            'series_id' => $series->id,
            'season_id' => $season->id,
            'season_number' => 1,
            'episode_number' => 3,
            'name' => 'Episódio Comentado',
        ]);

        // 1. Episódio marcado como assistido SEM nota (não deve ir pro feed)
        $this->service->rateOrWatchEpisode($this->user, $epUnrated, null, true);

        // 2. Episódio avaliado COM nota (deve ir pro feed)
        $this->service->rateOrWatchEpisode($this->user, $epRated, 9.0, true);

        // 3. Comentário em episódio (deve ir pro feed)
        CapyEpisodeComment::create([
            'episode_id' => $epCommented->id,
            'user_id' => $this->user->id,
            'content' => 'Comentário muito relevante!',
            'is_spoiler' => false,
        ]);

        // 4. Conclusão de série (deve ir pro feed)
        CapyUserSeries::updateOrCreate(
            ['user_id' => $this->user->id, 'series_id' => $series->id],
            ['status' => 'completed']
        );

        $response = $this->actingAs($this->user, 'sanctum')->getJson('/api/capyshowtime/community-activity');

        $response->assertStatus(200);
        $data = $response->json();

        // Deve conter exatamente 3 itens relevantes (rating, comment, completed), e NÃO o episódio sem nota
        $this->assertCount(3, $data);

        $types = array_column($data, 'type');
        $this->assertContains('rating', $types);
        $this->assertContains('comment', $types);
        $this->assertContains('completed', $types);

        // Verifica que o episódio sem nota não está presente nos itens
        foreach ($data as $item) {
            if ($item['type'] === 'rating') {
                $this->assertEquals($epRated->id, $item['episode']['id']);
                $this->assertEquals(9.0, $item['rating']);
            }
        }
    }

    public function test_marking_episode_watched_without_rating_does_not_create_feed_post(): void
    {
        $series = CapySeries::create([
            'tmdb_id' => 88881,
            'name' => 'No Rating Series',
            'number_of_seasons' => 1,
            'synced_at' => now(),
        ]);

        $season = CapySeason::create([
            'series_id' => $series->id,
            'season_number' => 1,
            'name' => 'Temporada 1',
        ]);

        $ep = CapyEpisode::create([
            'series_id' => $series->id,
            'season_id' => $season->id,
            'season_number' => 1,
            'episode_number' => 1,
            'name' => 'Episódio Apenas Assistido',
        ]);

        // Marca como assistido sem nota via endpoint
        $res = $this->actingAs($this->user, 'sanctum')->postJson('/api/capyshowtime/episodes/rate', [
            'episode_id' => $ep->id,
            'is_watched' => true,
            'rating' => null,
        ]);

        $res->assertStatus(200);

        // Não deve ter criado nenhum Post no feed
        $this->assertDatabaseMissing('posts', [
            'external_source' => 'capyshowtime',
            'external_id' => "capyshowtime:ep:{$ep->id}:user:{$this->user->id}",
        ]);
    }

    public function test_marking_episode_watched_with_rating_creates_feed_post_and_removing_rating_deletes_it(): void
    {
        $series = CapySeries::create([
            'tmdb_id' => 88882,
            'name' => 'Rating Series',
            'number_of_seasons' => 1,
            'synced_at' => now(),
        ]);

        $season = CapySeason::create([
            'series_id' => $series->id,
            'season_number' => 1,
            'name' => 'Temporada 1',
        ]);

        $ep = CapyEpisode::create([
            'series_id' => $series->id,
            'season_id' => $season->id,
            'season_number' => 1,
            'episode_number' => 1,
            'name' => 'Episódio Avaliado',
        ]);

        // 1. Marca com nota 8.5
        $res = $this->actingAs($this->user, 'sanctum')->postJson('/api/capyshowtime/episodes/rate', [
            'episode_id' => $ep->id,
            'is_watched' => true,
            'rating' => 8.5,
        ]);

        $res->assertStatus(200);

        // Deve existir post no feed
        $this->assertDatabaseHas('posts', [
            'external_source' => 'capyshowtime',
            'external_id' => "capyshowtime:ep:{$ep->id}:user:{$this->user->id}",
            'entertainment_type' => 'series',
        ]);

        // 2. Remove a nota
        $resRemove = $this->actingAs($this->user, 'sanctum')->postJson('/api/capyshowtime/episodes/rate', [
            'episode_id' => $ep->id,
            'is_watched' => true,
            'remove_rating' => true,
        ]);

        $resRemove->assertStatus(200);

        // O post deve ter sido excluído do feed
        $this->assertDatabaseMissing('posts', [
            'external_source' => 'capyshowtime',
            'external_id' => "capyshowtime:ep:{$ep->id}:user:{$this->user->id}",
        ]);
    }

    public function test_completing_series_creates_completed_feed_post_and_removing_status_deletes_it(): void
    {
        $series = CapySeries::create([
            'tmdb_id' => 88883,
            'name' => 'Completed Series Test',
            'number_of_seasons' => 1,
            'synced_at' => now(),
        ]);

        // 1. Define status como completed
        $res = $this->actingAs($this->user, 'sanctum')->postJson('/api/capyshowtime/series/status', [
            'tmdb_id' => $series->tmdb_id,
            'status' => 'completed',
        ]);

        $res->assertStatus(200);

        $this->assertDatabaseHas('posts', [
            'external_source' => 'capyshowtime',
            'external_id' => "capyshowtime:series_completed:{$series->id}:user:{$this->user->id}",
            'entertainment_type' => 'series',
        ]);

        // 2. Muda status para watching
        $resWatching = $this->actingAs($this->user, 'sanctum')->postJson('/api/capyshowtime/series/status', [
            'tmdb_id' => $series->tmdb_id,
            'status' => 'watching',
        ]);

        $resWatching->assertStatus(200);

        // O post de conclusão deve ter sido excluído
        $this->assertDatabaseMissing('posts', [
            'external_source' => 'capyshowtime',
            'external_id' => "capyshowtime:series_completed:{$series->id}:user:{$this->user->id}",
        ]);
    }

    public function test_marking_one_season_of_multi_season_series_keeps_watching_status(): void
    {
        $series = CapySeries::create([
            'tmdb_id' => 88884,
            'name' => 'Multi-Season Series Test',
            'number_of_seasons' => 3,
            'number_of_episodes' => 30,
            'synced_at' => now(),
        ]);

        $season1 = CapySeason::create([
            'series_id' => $series->id,
            'season_number' => 1,
            'episode_count' => 10,
            'name' => 'Temporada 1',
        ]);

        CapySeason::create([
            'series_id' => $series->id,
            'season_number' => 2,
            'episode_count' => 10,
            'name' => 'Temporada 2',
        ]);

        CapySeason::create([
            'series_id' => $series->id,
            'season_number' => 3,
            'episode_count' => 10,
            'name' => 'Temporada 3',
        ]);

        for ($i = 1; $i <= 10; $i++) {
            CapyEpisode::create([
                'series_id' => $series->id,
                'season_id' => $season1->id,
                'season_number' => 1,
                'episode_number' => $i,
                'name' => "Episódio {$i}",
            ]);
        }

        $res = $this->actingAs($this->user, 'sanctum')->postJson("/api/capyshowtime/series/{$series->tmdb_id}/season/1/watch-all");
        $res->assertStatus(200);
        $res->assertJson([
            'user_status' => 'watching',
        ]);

        $this->assertDatabaseHas('capy_user_series', [
            'user_id' => $this->user->id,
            'series_id' => $series->id,
            'status' => 'watching',
        ]);

        // NÃO deve criar post de conclusão
        $this->assertDatabaseMissing('posts', [
            'external_source' => 'capyshowtime',
            'external_id' => "capyshowtime:series_completed:{$series->id}:user:{$this->user->id}",
        ]);
    }

    public function test_dropping_series_creates_dropped_feed_post_and_changes_status(): void
    {
        $series = CapySeries::create([
            'tmdb_id' => 88885,
            'name' => 'Dropped Series Test',
            'number_of_seasons' => 2,
            'synced_at' => now(),
        ]);

        $res = $this->actingAs($this->user, 'sanctum')->postJson('/api/capyshowtime/series/status', [
            'tmdb_id' => $series->tmdb_id,
            'status' => 'dropped',
        ]);

        $res->assertStatus(200);

        $this->assertDatabaseHas('posts', [
            'external_source' => 'capyshowtime',
            'external_id' => "capyshowtime:series_dropped:{$series->id}:user:{$this->user->id}",
            'entertainment_type' => 'series',
        ]);
    }

    public function test_episode_comment_creates_clean_post_content(): void
    {
        $series = CapySeries::create([
            'tmdb_id' => 88886,
            'name' => 'Clean Comment Test',
            'number_of_seasons' => 1,
            'synced_at' => now(),
        ]);

        $season = CapySeason::create([
            'series_id' => $series->id,
            'season_number' => 1,
            'name' => 'Temporada 1',
        ]);

        $episode = CapyEpisode::create([
            'series_id' => $series->id,
            'season_id' => $season->id,
            'season_number' => 1,
            'episode_number' => 1,
            'name' => 'Pilot',
        ]);

        $res = $this->actingAs($this->user, 'sanctum')->postJson('/api/capyshowtime/episodes/comments', [
            'episode_id' => $episode->id,
            'content' => 'Esse episódio foi sensacional!',
            'is_spoiler' => false,
        ]);

        $res->assertStatus(201);

        $this->assertDatabaseHas('posts', [
            'external_source' => 'capyshowtime',
            'content' => 'Esse episódio foi sensacional!',
        ]);
    }
}
