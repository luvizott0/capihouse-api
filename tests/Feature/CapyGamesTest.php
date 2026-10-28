<?php

namespace Tests\Feature;

use App\Models\CapyGame;
use App\Models\CapyUserGame;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_completed_game_does_not_automatically_get_100_percent_progress_without_achievements()
    {
        $user = $this->createApprovedUser();
        Sanctum::actingAs($user);

        $game = CapyGame::create([
            'title' => 'Sea of Stars',
            'slug' => 'sea-of-stars',
            'external_source' => 'steam',
            'external_id' => 'steam:1244090',
            'platforms' => ['Steam'],
        ]);

        // Jogo completado (zerado) sem conquistas registradas
        $userGame = CapyUserGame::create([
            'user_id' => $user->id,
            'game_id' => $game->id,
            'status' => 'completed',
            'hours_played' => 35,
            'achievements_earned' => 0,
            'achievements_total' => 0,
        ]);

        // Conclusão não pode ser 100% nem baseada em horas heurísticas
        $this->assertEquals(0, $userGame->progress_percentage);

        // Se tiver 10 de 20 conquistas, o progresso é 50%
        $userGame->achievements_earned = 10;
        $userGame->achievements_total = 20;
        $userGame->save();
        $this->assertEquals(50, $userGame->progress_percentage);

        // Apenas 'mastered' (platinado) tem 100% garantido
        $userGame->status = 'mastered';
        $userGame->save();
        $this->assertEquals(100, $userGame->progress_percentage);
    }

    public function test_platform_names_are_normalized_to_steam_and_tails_of_iron_has_all_platforms()
    {
        $user = $this->createApprovedUser();
        Sanctum::actingAs($user);

        // Adiciona Tails of Iron com plataforma 'PC'
        $payload = [
            'title' => 'Tails of Iron',
            'external_id' => 'steam:700580',
            'external_source' => 'steam',
            'status' => 'playing',
            'platform' => 'PC',
            'hours_played' => 12,
        ];

        $response = $this->postJson('/api/capygames/library/status', $payload);
        $response->assertOk();

        $userGame = CapyUserGame::where('user_id', $user->id)->first();
        // A plataforma do usuário é normalizada para 'Steam'
        $this->assertEquals('Steam', $userGame->platform);
        $this->assertArrayHasKey('Steam', $userGame->platform_hours);
        $this->assertArrayNotHasKey('PC', $userGame->platform_hours);

        // Ao consultar os detalhes do Tails of Iron, todas as plataformas oficiais devem estar presentes
        $detailsRes = $this->getJson("/api/capygames/games/{$userGame->game_id}");
        $detailsRes->assertOk();
        $platforms = $detailsRes->json('game.platforms');

        $this->assertContains('Steam', $platforms);
        $this->assertNotContains('PC', $platforms);
        $this->assertNotContains('PC (Steam)', $platforms);
        $this->assertContains('PlayStation 5', $platforms);
        $this->assertContains('PlayStation 4', $platforms);
        $this->assertContains('Xbox Series X|S', $platforms);
        $this->assertContains('Xbox One', $platforms);
        $this->assertContains('Nintendo Switch', $platforms);
    }

    public function test_friends_recent_games_returns_up_to_10_unique_games()
    {
        $user = $this->createApprovedUser();
        $friend = $this->createApprovedUser(['email' => 'friend@capihouse.com']);
        Sanctum::actingAs($user);

        for ($i = 1; $i <= 12; $i++) {
            $game = CapyGame::create([
                'title' => "Game {$i}",
                'slug' => "game-{$i}",
                'external_id' => "manual:game-{$i}",
                'external_source' => 'manual',
                'cover_url' => "https://example.com/cover{$i}.jpg",
                'platforms' => ['Steam'],
            ]);

            $ug = CapyUserGame::create([
                'user_id' => $friend->id,
                'game_id' => $game->id,
                'status' => 'playing',
                'hours_played' => $i,
            ]);

            DB::table('capy_user_games')
                ->where('id', $ug->id)
                ->update(['updated_at' => now()->addMinutes($i)]);
        }

        $response = $this->getJson('/api/capygames/friends-games');
        $response->assertOk();
        $data = $response->json('data');

        $this->assertCount(10, $data);
        // O jogo mais recentemente atualizado deve ser o Game 12
        $this->assertEquals('Game 12', $data[0]['title']);
        $this->assertEquals($friend->name, $data[0]['recent_player']['name']);
    }

    public function test_top_rated_games_returns_top_10_sorted_from_best_to_worst()
    {
        $user = $this->createApprovedUser();
        Sanctum::actingAs($user);

        $gameHigh = CapyGame::create([
            'title' => 'Top Game A',
            'slug' => 'top-game-a',
            'external_id' => 'manual:top-a',
            'external_source' => 'manual',
            'cover_url' => 'https://example.com/a.jpg',
        ]);
        $gameLow = CapyGame::create([
            'title' => 'Low Game B',
            'slug' => 'low-game-b',
            'external_id' => 'manual:low-b',
            'external_source' => 'manual',
            'cover_url' => 'https://example.com/b.jpg',
        ]);

        CapyUserGame::create([
            'user_id' => $user->id,
            'game_id' => $gameHigh->id,
            'status' => 'completed',
            'rating' => 10.0,
        ]);
        CapyUserGame::create([
            'user_id' => $user->id,
            'game_id' => $gameLow->id,
            'status' => 'completed',
            'rating' => 7.5,
        ]);

        $response = $this->getJson('/api/capygames/top-rated');
        $response->assertOk();
        $data = $response->json('data');

        $this->assertCount(10, $data);
        // Primeiro item deve ser nota 10.0 (melhor avaliado)
        $this->assertEquals(10.0, (float) $data[0]['rating']);
        $this->assertEquals('Top Game A', $data[0]['title']);

        // Verifica que está estritamente em ordem decrescente
        for ($i = 0; $i < count($data) - 1; $i++) {
            $this->assertGreaterThanOrEqual(
                (float) $data[$i + 1]['rating'],
                (float) $data[$i]['rating']
            );
        }
    }
}
