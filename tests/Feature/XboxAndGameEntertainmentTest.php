<?php

namespace Tests\Feature;

use App\Enums\UserRoles;
use App\Enums\UserStatuses;
use App\Jobs\SyncXboxJob;
use App\Models\Post;
use App\Models\User;
use App\Services\XboxSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class XboxAndGameEntertainmentTest extends TestCase
{
    use RefreshDatabase;

    private function createApprovedUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'status' => UserStatuses::APPROVED,
            'role' => UserRoles::User,
        ], $attributes));
    }

    public function test_user_can_connect_xbox_account_and_dispatches_sync_job()
    {
        Queue::fake();

        $user = $this->createApprovedUser();

        $response = $this->actingAs($user)->postJson('/api/profile/xbox/connect', [
            'gamertag' => 'MasterChief117',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'xbox_gamertag' => 'MasterChief117',
        ]);

        Queue::assertPushed(SyncXboxJob::class, function ($job) use ($user) {
            return $job->user->id === $user->id;
        });
    }

    public function test_user_can_disconnect_xbox_account()
    {
        $user = $this->createApprovedUser([
            'xbox_gamertag' => 'MasterChief117',
            'xbox_xuid' => '25332748293847',
            'xbox_last_synced_at' => now(),
        ]);

        $response = $this->actingAs($user)->postJson('/api/profile/xbox/disconnect');

        $response->assertStatus(200);
        $user->refresh();
        $this->assertNull($user->xbox_gamertag);
        $this->assertNull($user->xbox_xuid);
        $this->assertNull($user->xbox_last_synced_at);
    }

    public function test_user_can_manually_trigger_xbox_sync()
    {
        Queue::fake();

        $user = $this->createApprovedUser([
            'xbox_gamertag' => 'MasterChief117',
        ]);

        $response = $this->actingAs($user)->postJson('/api/profile/xbox/sync');

        $response->assertStatus(200);
        Queue::assertPushed(SyncXboxJob::class);
    }

    public function test_xbox_sync_service_imports_games_and_detects_100_percent_mastered()
    {
        Config::set('services.openxbl.api_key', 'test-api-key');

        $user = $this->createApprovedUser([
            'xbox_gamertag' => 'MasterChief117',
            'xbox_xuid' => '25332748293847',
        ]);

        $fakeTitlesResponse = [
            'titles' => [
                [
                    'titleId' => '1001',
                    'name' => 'Halo Infinite',
                    'displayImage' => 'https://images.xbox.com/halo.jpg',
                    'achievement' => [
                        'currentAchievements' => 50,
                        'totalAchievements' => 50,
                        'currentGamerscore' => 1000,
                        'totalGamerscore' => 1000,
                        'progressPercentage' => 100,
                    ],
                    'lastUnlock' => '2026-09-20T20:00:00Z',
                ],
                [
                    'titleId' => '1002',
                    'name' => 'Forza Motorsport',
                    'displayImage' => 'https://images.xbox.com/forza.jpg',
                    'achievement' => [
                        'currentAchievements' => 15,
                        'totalAchievements' => 60,
                        'currentGamerscore' => 300,
                        'totalGamerscore' => 1000,
                        'progressPercentage' => 25,
                    ],
                    'lastUnlock' => '2026-09-21T10:00:00Z',
                ],
            ],
        ];

        Http::fake([
            'https://api.xbl.io/v2/titles/*' => Http::response(['content' => $fakeTitlesResponse], 200),
            'https://api.xbl.io/v2/achievements/player/*' => Http::response($fakeTitlesResponse, 200),
        ]);

        $service = new XboxSyncService;
        $count = $service->sync($user);

        $this->assertEquals(2, $count);

        // Halo Infinite deve estar como 'mastered' (100% miletado)
        $haloPost = Post::where('user_id', $user->id)
            ->where('external_id', "xbox-{$user->id}-1001")
            ->first();

        $this->assertNotNull($haloPost);
        $this->assertEquals('entertainment', $haloPost->category);
        $this->assertEquals('game', $haloPost->entertainment_type);
        $this->assertEquals('Halo Infinite', $haloPost->metadata['game_title']);
        $this->assertEquals('mastered', $haloPost->metadata['game_status']);
        $this->assertNull($haloPost->content);

        // Forza deve estar como 'playing'
        $forzaPost = Post::where('user_id', $user->id)
            ->where('external_id', "xbox-{$user->id}-1002")
            ->first();

        $this->assertNotNull($forzaPost);
        $this->assertEquals('playing', $forzaPost->metadata['game_status']);
        $this->assertEquals(300, $forzaPost->metadata['gamerscore']);
    }

    public function test_user_can_create_manual_game_review()
    {
        $user = $this->createApprovedUser();

        $response = $this->actingAs($user)->postJson('/api/entertainment/games', [
            'game_title' => 'The Witcher 3: Wild Hunt',
            'platform' => 'Xbox Series X',
            'game_status' => 'completed',
            'rating' => 5,
            'content' => 'Uma obra-prima absoluta dos RPGs!',
            'box_art_url' => 'https://images.igdb.com/witcher3.jpg',
            'hours_played' => 120,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('message', 'Análise de jogo publicada com sucesso!');
        $response->assertJsonPath('post.metadata.game_title', 'The Witcher 3: Wild Hunt');
        $response->assertJsonPath('post.metadata.platform', 'Xbox Series X');
        $response->assertJsonPath('post.metadata.game_status', 'completed');
        $response->assertJsonPath('post.metadata.rating', 5);

        $this->assertDatabaseHas('posts', [
            'user_id' => $user->id,
            'category' => 'entertainment',
            'entertainment_type' => 'game',
            'external_source' => 'manual',
            'content' => 'Uma obra-prima absoluta dos RPGs!',
        ]);
    }

    public function test_entertainment_posts_can_be_filtered_by_game_type()
    {
        $user = $this->createApprovedUser();

        // 1 Movie post
        Post::create([
            'user_id' => $user->id,
            'category' => 'entertainment',
            'entertainment_type' => 'movie',
            'external_source' => 'letterboxd',
            'content' => 'Bom filme',
            'metadata' => ['film_title' => 'Oppenheimer'],
        ]);

        // 1 Game post
        Post::create([
            'user_id' => $user->id,
            'category' => 'entertainment',
            'entertainment_type' => 'game',
            'external_source' => 'xbox',
            'content' => 'Jogo top',
            'metadata' => ['game_title' => 'Elden Ring'],
        ]);

        // Requisição para a aba de jogos
        $response = $this->actingAs($user)->getJson('/api/posts?category=entertainment&entertainment_type=game');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('Elden Ring', $data[0]['metadata']['game_title']);
        $this->assertEquals('game', $data[0]['entertainment_type']);
    }

    public function test_posts_can_be_searched_by_game_title_in_entertainment_and_feed_reposts()
    {
        $user = $this->createApprovedUser();

        // Game post in entertainment
        $gamePost = Post::create([
            'user_id' => $user->id,
            'category' => 'entertainment',
            'entertainment_type' => 'game',
            'external_source' => 'xbox',
            'content' => null,
            'metadata' => ['game_title' => 'LEGO Marvel Super Heroes', 'platform' => 'Xbox'],
        ]);

        // Repost in feed
        $repost = Post::create([
            'user_id' => $user->id,
            'category' => 'feed',
            'repost_of_id' => $gamePost->id,
            'content' => 'Comentário sobre o jogo',
        ]);

        // 1. Busca na aba de entretenimento
        $entRes = $this->actingAs($user)->getJson('/api/posts?category=entertainment&entertainment_type=game&q=lego');
        $entRes->assertStatus(200);
        $this->assertCount(1, $entRes->json('data'));
        $this->assertEquals('LEGO Marvel Super Heroes', $entRes->json('data.0.metadata.game_title'));

        // 2. Busca no feed principal (deve encontrar o repost pelo título do jogo repostado)
        $feedRes = $this->actingAs($user)->getJson('/api/posts?q=lego');
        $feedRes->assertStatus(200);
        $this->assertCount(1, $feedRes->json('data'));
        $this->assertEquals($repost->id, $feedRes->json('data.0.id'));
    }
}
