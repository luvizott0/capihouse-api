<?php

namespace Tests\Feature;

use App\Models\CapyEpisode;
use App\Models\CapySeason;
use App\Models\CapySeries;
use App\Models\User;
use App\Services\CapyShowTimeService;
use App\Services\TmdbService;
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
}
