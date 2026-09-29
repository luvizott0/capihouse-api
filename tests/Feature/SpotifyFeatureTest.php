<?php

namespace Tests\Feature;

use App\Enums\UserRoles;
use App\Enums\UserStatuses;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SpotifyFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'status' => UserStatuses::APPROVED,
            'role' => UserRoles::User,
        ]);
    }

    public function test_can_update_and_remove_favorite_music(): void
    {
        $payload = [
            'id' => 'track-123',
            'title' => 'Bohemian Rhapsody',
            'artist' => 'Queen',
            'album' => 'A Night at the Opera',
            'album_art' => 'https://example.com/queen.jpg',
            'spotify_url' => 'https://open.spotify.com/track/track-123',
            'preview_url' => null,
            'duration_ms' => 354000,
        ];

        // Atualiza a música fixa
        $response = $this->actingAs($this->user)
            ->putJson('/api/profile/favorite-music', $payload);

        $response->assertOk()
            ->assertJsonPath('user.favorite_music.title', 'Bohemian Rhapsody')
            ->assertJsonPath('user.favorite_music.artist', 'Queen');

        $this->user->refresh();
        $this->assertEquals('Bohemian Rhapsody', $this->user->favorite_music['title']);

        // Remove a música fixa
        $removeResponse = $this->actingAs($this->user)
            ->deleteJson('/api/profile/favorite-music');

        $removeResponse->assertOk()
            ->assertJsonPath('user.favorite_music', null);

        $this->user->refresh();
        $this->assertNull($this->user->favorite_music);
    }

    public function test_can_disconnect_spotify_account(): void
    {
        $this->user->update([
            'spotify_id' => 'spotify-user-1',
            'spotify_access_token' => 'access-token-123',
            'spotify_refresh_token' => 'refresh-token-123',
            'spotify_display_name' => 'Capivara DJ',
        ]);

        $this->assertTrue($this->user->hasSpotifyConnected());

        $response = $this->actingAs($this->user)
            ->postJson('/api/spotify/disconnect');

        $response->assertOk()
            ->assertJsonPath('user.has_spotify_connected', false);

        $this->user->refresh();
        $this->assertNull($this->user->spotify_refresh_token);
        $this->assertFalse($this->user->hasSpotifyConnected());
    }

    public function test_returns_not_playing_when_user_has_no_spotify_connected(): void
    {
        $otherUser = User::factory()->create([
            'status' => UserStatuses::APPROVED,
            'username' => 'testuser',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/users/{$otherUser->username}/spotify-status");

        $response->assertOk()
            ->assertJson(['is_playing' => false]);
    }

    public function test_returns_currently_playing_when_spotify_is_active(): void
    {
        $this->user->update([
            'spotify_id' => 'spot-123',
            'spotify_access_token' => 'valid-access-token',
            'spotify_refresh_token' => 'valid-refresh-token',
            'spotify_token_expires_at' => now()->addHour(),
        ]);

        Http::fake([
            'https://api.spotify.com/v1/me/player/currently-playing' => Http::response([
                'is_playing' => true,
                'progress_ms' => 45000,
                'item' => [
                    'id' => 'song-456',
                    'name' => 'Clint Eastwood',
                    'duration_ms' => 340000,
                    'preview_url' => null,
                    'external_urls' => ['spotify' => 'https://open.spotify.com/track/song-456'],
                    'artists' => [
                        ['name' => 'Gorillaz'],
                    ],
                    'album' => [
                        'name' => 'Gorillaz',
                        'images' => [
                            ['url' => 'https://example.com/gorillaz.jpg'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/users/{$this->user->username}/spotify-status");

        $response->assertOk()
            ->assertJsonPath('is_playing', true)
            ->assertJsonPath('title', 'Clint Eastwood')
            ->assertJsonPath('artist', 'Gorillaz');

        // Confirma que o cache para online users foi alimentado
        $onlineCache = Cache::get("user_spotify_track_{$this->user->id}");
        $this->assertNotNull($onlineCache);
        $this->assertEquals('Clint Eastwood', $onlineCache['title']);
    }

    public function test_online_users_resource_includes_spotify_current_track_from_cache(): void
    {
        $this->user->update([
            'last_seen_at' => now(),
        ]);

        Cache::put("user_spotify_track_{$this->user->id}", [
            'is_playing' => true,
            'title' => 'Midnight City',
            'artist' => 'M83',
            'spotify_url' => 'https://open.spotify.com/track/m83',
        ], 60);

        $response = $this->actingAs($this->user)
            ->getJson('/api/users/online');

        $response->assertOk()
            ->assertJsonFragment([
                'id' => $this->user->id,
                'spotify_current_track' => [
                    'is_playing' => true,
                    'title' => 'Midnight City',
                    'artist' => 'M83',
                    'spotify_url' => 'https://open.spotify.com/track/m83',
                ],
            ]);
    }
}
