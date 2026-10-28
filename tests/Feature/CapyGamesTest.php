<?php

namespace Tests\Feature;

use App\Models\CapyGame;
use App\Models\CapyUserGame;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CapyGamesTest extends TestCase
{
    use RefreshDatabase;

    protected function createApprovedUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'status' => 'approved',
            'role' => 'user',
        ], $attributes));
    }

    public function test_user_can_search_games()
    {
        $user = $this->createApprovedUser();
        Sanctum::actingAs($user);

        // Cria jogo local no banco
        CapyGame::create([
            'title' => 'Chrono Trigger',
            'slug' => 'chrono-trigger',
            'external_source' => 'manual',
            'external_id' => 'manual:chrono',
            'cover_url' => 'https://example.com/chrono.jpg',
            'platforms' => ['SNES', 'PC'],
        ]);

        $response = $this->getJson('/api/capygames/search?q=Chrono');

        $response->assertOk();
        $response->assertJsonFragment([
            'title' => 'Chrono Trigger',
        ]);
    }

    public function test_user_can_add_game_to_library_and_completed_game_appears_in_capihouse_feed()
    {
        $user = $this->createApprovedUser();
        Sanctum::actingAs($user);

        $payload = [
            'title' => 'The Legend of Zelda: Breath of the Wild',
            'external_id' => 'nintendo:botw',
            'external_source' => 'manual',
            'cover_url' => 'https://example.com/botw.jpg',
            'status' => 'completed',
            'platform' => 'Nintendo Switch',
            'rating' => 10,
            'hours_played' => 120,
            'review' => 'Obra-prima atemporal, um dos melhores jogos já feitos.',
        ];

        $response = $this->postJson('/api/capygames/library/status', $payload);

        $response->assertOk();
        $this->assertDatabaseHas('capy_user_games', [
            'user_id' => $user->id,
            'status' => 'completed',
            'rating' => 10,
            'hours_played' => 120,
        ]);

        // Jogo completado/com review DEVE gerar Post no feed geral da Capihouse
        $this->assertDatabaseHas('posts', [
            'user_id' => $user->id,
            'category' => 'entertainment',
            'entertainment_type' => 'game',
            'external_source' => 'capygames',
        ]);

        // E deve registrar atividade no feed do CapyGames
        $this->assertDatabaseHas('capy_game_activities', [
            'user_id' => $user->id,
            'type' => 'status_change',
        ]);
    }

    public function test_plan_to_play_game_does_not_pollute_main_capihouse_feed()
    {
        $user = $this->createApprovedUser();
        Sanctum::actingAs($user);

        $payload = [
            'title' => 'Metroid Prime 4',
            'external_id' => 'nintendo:mp4',
            'external_source' => 'manual',
            'status' => 'plan_to_play',
            'platform' => 'Nintendo Switch',
        ];

        $response = $this->postJson('/api/capygames/library/status', $payload);

        $response->assertOk();
        $this->assertDatabaseHas('capy_user_games', [
            'user_id' => $user->id,
            'status' => 'plan_to_play',
        ]);

        // NÃO deve gerar Post no feed principal da Capihouse
        $this->assertDatabaseMissing('posts', [
            'user_id' => $user->id,
            'category' => 'entertainment',
            'external_source' => 'capygames',
        ]);

        // Mas deve registrar no feed interno do CapyGames
        $this->assertDatabaseHas('capy_game_activities', [
            'user_id' => $user->id,
            'type' => 'status_change',
        ]);
    }

    public function test_user_can_fetch_gamer_profile_with_stats()
    {
        $user = $this->createApprovedUser();
        Sanctum::actingAs($user);

        $game = CapyGame::create([
            'title' => 'Dark Souls',
            'slug' => 'dark-souls',
            'external_source' => 'steam',
            'external_id' => 'steam:211420',
        ]);

        CapyUserGame::create([
            'user_id' => $user->id,
            'game_id' => $game->id,
            'status' => 'mastered',
            'hours_played' => 85.5,
            'rating' => 9.5,
        ]);

        $response = $this->getJson("/api/capygames/users/{$user->id}");

        $response->assertOk();
        $response->assertJsonPath('stats.total_games', 1);
        $response->assertJsonPath('stats.mastered', 1);
        $response->assertJsonPath('stats.total_hours', 85.5);
        $response->assertJsonPath('stats.avg_rating', 9.5);
    }

    public function test_user_can_review_game_with_aspect_ratings_and_mean_is_computed()
    {
        $user = $this->createApprovedUser();
        Sanctum::actingAs($user);

        $payload = [
            'title' => 'Elden Ring',
            'external_id' => 'steam:1245620',
            'external_source' => 'steam',
            'cover_url' => 'https://example.com/eldenring.jpg',
            'status' => 'completed',
            'platform' => 'PlayStation 5',
            'hours_played' => 110,
            'aspect_ratings' => [
                'gameplay' => 10,
                'story' => 8,
                'graphics' => 9,
                'sound' => 10,
                'performance' => 8,
                'replay' => 9,
                'level_design' => 10,
                'innovation' => 8,
            ],
            'review' => 'Uma jornada extraordinária pelas Terras Intermédias.',
        ];

        $response = $this->postJson('/api/capygames/library/status', $payload);

        $response->assertOk();

        // Média de 10+8+9+10+8+9+10+8 = 72 / 8 = 9.0
        $this->assertDatabaseHas('capy_user_games', [
            'user_id' => $user->id,
            'rating' => 9.0,
            'platform' => 'PlayStation 5',
        ]);

        $userGame = CapyUserGame::where('user_id', $user->id)->first();
        $this->assertEquals(10, $userGame->aspect_ratings['gameplay']);
        $this->assertEquals(8, $userGame->aspect_ratings['story']);

        // E o jogo deve agora conter PlayStation 5 nas plataformas conhecidas
        $game = CapyGame::find($userGame->game_id);
        $this->assertContains('PlayStation 5', $game->platforms);
    }
}
