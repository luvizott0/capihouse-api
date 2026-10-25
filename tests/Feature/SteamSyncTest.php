<?php

namespace Tests\Feature;

use App\Models\CapyGame;
use App\Models\CapyUserGame;
use App\Models\User;
use App\Services\SteamSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SteamSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.steam.api_key', 'test_steam_api_key');
    }

    public function test_steam_sync_fetches_achievements_and_does_not_mark_all_games_as_completed(): void
    {
        $user = User::factory()->create([
            'steam_id' => '76561198000000001',
            'steam_username' => 'testgamer',
            'status' => 'approved',
        ]);

        Http::fake([
            'https://api.steampowered.com/IPlayerService/GetOwnedGames/v0001/*' => Http::response([
                'response' => [
                    'game_count' => 3,
                    'games' => [
                        [
                            'appid' => 1091500, // Cyberpunk 2077 - Recent playtime, 10/44 achievements
                            'name' => 'Cyberpunk 2077',
                            'playtime_forever' => 1200, // 20 hours
                            'playtime_2weeks' => 180,
                            'rtime_last_played' => now()->timestamp,
                        ],
                        [
                            'appid' => 292030, // The Witcher 3 - 78/78 achievements (mastered)
                            'name' => 'The Witcher 3: Wild Hunt',
                            'playtime_forever' => 6000, // 100 hours
                            'playtime_2weeks' => 0,
                            'rtime_last_played' => now()->subMonths(3)->timestamp,
                        ],
                        [
                            'appid' => 400, // Portal - 0 hours (unplayed)
                            'name' => 'Portal',
                            'playtime_forever' => 0,
                            'playtime_2weeks' => 0,
                            'rtime_last_played' => 0,
                        ],
                    ],
                ],
            ], 200),
            'https://api.steampowered.com/ISteamUserStats/GetPlayerAchievements/v0001/*appid=1091500*' => Http::response([
                'playerstats' => [
                    'steamID' => '76561198000000001',
                    'gameName' => 'Cyberpunk 2077',
                    'success' => true,
                    'achievements' => array_merge(
                        array_fill(0, 10, ['apiname' => 'ACH_1', 'achieved' => 1]),
                        array_fill(0, 34, ['apiname' => 'ACH_2', 'achieved' => 0])
                    ),
                ],
            ], 200),
            'https://api.steampowered.com/ISteamUserStats/GetPlayerAchievements/v0001/*appid=292030*' => Http::response([
                'playerstats' => [
                    'steamID' => '76561198000000001',
                    'gameName' => 'The Witcher 3: Wild Hunt',
                    'success' => true,
                    'achievements' => array_fill(0, 78, ['apiname' => 'ACH_W', 'achieved' => 1]),
                ],
            ], 200),
        ]);

        $service = app(SteamSyncService::class);
        $imported = $service->sync($user);

        $this->assertEquals(3, $imported);

        // 1. Cyberpunk 2077: should be 'playing' (recent playtime), NOT 'completed', and have 10/44 achievements
        $cyberpunk = CapyGame::where('external_id', 'steam:1091500')->first();
        $this->assertNotNull($cyberpunk);
        $userCyberpunk = CapyUserGame::where('user_id', $user->id)->where('game_id', $cyberpunk->id)->first();
        $this->assertNotNull($userCyberpunk);
        $this->assertEquals('playing', $userCyberpunk->status);
        $this->assertNotEquals('completed', $userCyberpunk->status);
        $this->assertEquals(44, $userCyberpunk->achievements_total);
        $this->assertEquals(10, $userCyberpunk->achievements_earned);
        $this->assertEquals(23, $userCyberpunk->progress_percentage); // round((10 / 44) * 100) = 23%
        $this->assertNotEquals(100, $userCyberpunk->progress_percentage);

        // 2. The Witcher 3: 100% achievements, should be 'mastered'
        $witcher = CapyGame::where('external_id', 'steam:292030')->first();
        $this->assertNotNull($witcher);
        $userWitcher = CapyUserGame::where('user_id', $user->id)->where('game_id', $witcher->id)->first();
        $this->assertNotNull($userWitcher);
        $this->assertEquals('mastered', $userWitcher->status);
        $this->assertEquals(78, $userWitcher->achievements_total);
        $this->assertEquals(78, $userWitcher->achievements_earned);
        $this->assertEquals(100, $userWitcher->progress_percentage);

        // 3. Portal: 0 hours, should be 'plan_to_play'
        $portal = CapyGame::where('external_id', 'steam:400')->first();
        $this->assertNotNull($portal);
        $userPortal = CapyUserGame::where('user_id', $user->id)->where('game_id', $portal->id)->first();
        $this->assertNotNull($userPortal);
        $this->assertEquals('plan_to_play', $userPortal->status);
        $this->assertNotEquals('completed', $userPortal->status);
        $this->assertEquals(0, $userPortal->progress_percentage);
    }

    public function test_fix_steam_status_command(): void
    {
        $user = User::factory()->create(['status' => 'approved']);
        $game = CapyGame::create([
            'title' => 'Half-Life 2',
            'slug' => 'half-life-2',
            'external_source' => 'steam',
            'external_id' => 'steam:220',
            'cover_url' => 'https://example.com/hl2.jpg',
            'platforms' => ['PC'],
        ]);

        // Simula jogo que havia ficado como 'completed' pelo bug anterior há mais de 30 dias
        $userGame = CapyUserGame::create([
            'user_id' => $user->id,
            'game_id' => $game->id,
            'source' => 'steam',
            'status' => 'completed',
            'hours_played' => 15,
            'achievements_total' => 33,
            'achievements_earned' => 12,
        ]);
        $userGame->timestamps = false;
        $userGame->updated_at = now()->subDays(45);
        $userGame->save();
        $userGame->timestamps = true;

        $this->artisan('capygames:fix-steam-status')
            ->expectsOutputToContain('Corrigindo status')
            ->assertSuccessful();

        $userGame->refresh();
        $this->assertEquals('on_hold', $userGame->status);
        $this->assertEquals(36, $userGame->progress_percentage); // round((12 / 33) * 100) = 36%
    }
}
