<?php

namespace Tests\Feature;

use App\Enums\UserRoles;
use App\Enums\UserStatuses;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LastFmFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.lastfm.api_key', 'test-lastfm-api-key');

        $this->user = User::factory()->create([
            'status' => UserStatuses::APPROVED,
            'role' => UserRoles::User,
            'username' => 'capitest',
        ]);
    }

    public function test_can_connect_lastfm_account(): void
    {
        Http::fake([
            'https://ws.audioscrobbler.com/2.0/*' => Http::response([
                'user' => [
                    'name' => 'capivara_dj',
                    'url' => 'https://www.last.fm/user/capivara_dj',
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/profile/lastfm/connect', [
                'username' => 'capivara_dj',
            ]);

        $response->assertOk()
            ->assertJsonPath('user.lastfm_username', 'capivara_dj')
            ->assertJsonPath('user.has_lastfm_connected', true);

        $this->user->refresh();
        $this->assertEquals('capivara_dj', $this->user->lastfm_username);
        $this->assertTrue($this->user->hasLastFmConnected());
    }

    public function test_cannot_connect_non_existent_lastfm_user(): void
    {
        Http::fake([
            'https://ws.audioscrobbler.com/2.0/*' => Http::response([
                'error' => 6,
                'message' => 'User not found',
            ], 200),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/profile/lastfm/connect', [
                'username' => 'inexistent_user_xyz',
            ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['message']);

        $this->user->refresh();
        $this->assertNull($this->user->lastfm_username);
    }

    public function test_can_disconnect_lastfm_account(): void
    {
        $this->user->update([
            'lastfm_username' => 'capivara_dj',
        ]);

        $this->assertTrue($this->user->hasLastFmConnected());

        $response = $this->actingAs($this->user)
            ->postJson('/api/profile/lastfm/disconnect');

        $response->assertOk()
            ->assertJsonPath('user.lastfm_username', null)
            ->assertJsonPath('user.has_lastfm_connected', false);

        $this->user->refresh();
        $this->assertNull($this->user->lastfm_username);
        $this->assertFalse($this->user->hasLastFmConnected());
    }

    public function test_returns_currently_playing_from_lastfm_when_active(): void
    {
        $this->user->update([
            'lastfm_username' => 'capivara_dj',
        ]);

        Http::fake([
            'https://ws.audioscrobbler.com/2.0/*' => Http::response([
                'recenttracks' => [
                    'track' => [
                        [
                            'name' => 'Starboy',
                            'artist' => ['#text' => 'The Weeknd'],
                            'album' => ['#text' => 'Starboy'],
                            'image' => [
                                ['size' => 'small', '#text' => 'https://example.com/small.jpg'],
                                ['size' => 'extralarge', '#text' => 'https://example.com/starboy.jpg'],
                            ],
                            'url' => 'https://www.last.fm/music/The+Weeknd/_/Starboy',
                            '@attr' => ['nowplaying' => 'true'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/users/{$this->user->username}/music-status");

        $response->assertOk()
            ->assertJsonPath('is_playing', true)
            ->assertJsonPath('is_recent', false)
            ->assertJsonPath('source', 'lastfm')
            ->assertJsonPath('title', 'Starboy')
            ->assertJsonPath('artist', 'The Weeknd')
            ->assertJsonPath('album_art', 'https://example.com/starboy.jpg');

        $cached = Cache::get("user_spotify_track_{$this->user->id}");
        $this->assertNotNull($cached);
        $this->assertEquals('Starboy', $cached['title']);
        $this->assertEquals('lastfm', $cached['source']);
    }

    public function test_returns_recently_played_from_lastfm_when_stopped(): void
    {
        $this->user->update([
            'lastfm_username' => 'capivara_dj',
        ]);

        Http::fake([
            'https://ws.audioscrobbler.com/2.0/*' => Http::response([
                'recenttracks' => [
                    'track' => [
                        [
                            'name' => 'Blinding Lights',
                            'artist' => ['#text' => 'The Weeknd'],
                            'album' => ['#text' => 'After Hours'],
                            'image' => [
                                ['size' => 'large', '#text' => 'https://example.com/blinding.jpg'],
                            ],
                            'url' => 'https://www.last.fm/music/The+Weeknd/_/Blinding+Lights',
                            'date' => ['uts' => 1700000000],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/users/{$this->user->username}/spotify-status");

        $response->assertOk()
            ->assertJsonPath('is_playing', false)
            ->assertJsonPath('is_recent', true)
            ->assertJsonPath('source', 'lastfm')
            ->assertJsonPath('title', 'Blinding Lights')
            ->assertJsonPath('artist', 'The Weeknd');
    }

    public function test_can_repost_track_from_lastfm(): void
    {
        $payload = [
            'track' => [
                'id' => 'lastfm-song-1',
                'title' => 'Get Lucky',
                'artist' => 'Daft Punk',
                'album' => 'Random Access Memories',
                'album_art' => 'https://example.com/ram.jpg',
                'spotify_url' => 'https://www.last.fm/music/Daft+Punk/_/Get+Lucky',
                'source' => 'lastfm',
            ],
            'content' => 'Que música incrível via Last.fm!',
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/api/spotify/repost', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('post.external_source', 'lastfm')
            ->assertJsonPath('post.metadata.track_title', 'Get Lucky')
            ->assertJsonPath('post.metadata.artist', 'Daft Punk');
    }
}
