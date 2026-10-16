<?php

namespace Tests\Feature;

use App\Services\CapyFlixStorageService;
use App\Services\CapyFlixStreamResolverService;
use App\Services\QBittorrentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CapyFlixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_can_get_storage_status_with_300gb_quota(): void
    {
        $response = $this->getJson('/api/capyflix/storage');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'media_root',
                'total_bytes',
                'total_formatted',
                'max_bytes',
                'max_formatted',
                'free_bytes',
                'free_formatted',
                'percent_used',
                'is_near_limit',
                'is_exceeded',
                'file_count',
                'files',
            ]);

        $this->assertEquals(CapyFlixStorageService::MAX_STORAGE_BYTES, $response->json('max_bytes'));
    }

    public function test_can_get_and_save_debrid_config(): void
    {
        $getRes = $this->getJson('/api/capyflix/debrid-config');
        $getRes->assertStatus(200)
            ->assertJson([
                'has_token' => false,
            ]);

        $postRes = $this->postJson('/api/capyflix/debrid-config', [
            'provider' => 'torbox',
            'token' => 'test-secret-token-1234',
        ]);

        $postRes->assertStatus(200)
            ->assertJson([
                'success' => true,
                'config' => [
                    'provider' => 'torbox',
                    'has_token' => true,
                ],
            ]);

        $this->assertEquals('torbox', Cache::get('capyflix:debrid_provider'));
        $this->assertEquals('test-secret-token-1234', Cache::get('capyflix:debrid_token'));
    }

    public function test_resolve_streams_endpoint_structure(): void
    {
        Http::fake([
            'https://api.themoviedb.org/*' => Http::response(['imdb_id' => 'tt0137523'], 200),
            'https://torrentio.strem.fun/*' => Http::response([
                'streams' => [
                    [
                        'name' => 'Torrentio 1080p',
                        'title' => "Fight Club 1080p\n💾 2.1 GB",
                        'infoHash' => 'abc123456',
                    ],
                ],
            ], 200),
        ]);

        $response = $this->getJson('/api/capyflix/resolve-streams?tmdb_id=550&media_type=movie');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'streams',
                'debrid_configured',
            ]);

        $this->assertNotEmpty($response->json('streams'));
        $this->assertEquals('qBittorrent (P2P Local)', $response->json('streams.0.name'));
    }

    public function test_start_download_validates_required_fields(): void
    {
        $response = $this->postJson('/api/capyflix/download', []);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'media_type']);
    }

    public function test_downloads_list_endpoint(): void
    {
        $response = $this->getJson('/api/capyflix/downloads');
        $response->assertStatus(200)
            ->assertJsonStructure(['downloads']);
    }

    public function test_blocks_duplicate_downloads_in_queue(): void
    {
        $payload = [
            'title' => 'Test Series Duplicate',
            'media_type' => 'series',
            'season' => 1,
            'episode' => 1,
            'url' => 'http://example.com/test.mp4',
        ];

        // 1st request succeeds
        $res1 = $this->postJson('/api/capyflix/download', $payload);
        $res1->assertStatus(200);

        // 2nd request for identical series episode is blocked with 422
        $res2 = $this->postJson('/api/capyflix/download', $payload);
        $res2->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'O episódio 1x01 já está na fila de downloads ou sendo baixado.',
            ]);
    }

    public function test_media_status_endpoint(): void
    {
        $response = $this->getJson('/api/capyflix/media-status?title=Stranger+Things&media_type=series&season=1');
        $response->assertStatus(200)
            ->assertJsonStructure([
                'title',
                'media_type',
                'season',
                'is_downloaded',
                'downloaded_episodes',
                'active_downloads',
                'debrid_configured',
            ]);
    }

    public function test_batch_download_handles_episodes_and_skips_duplicates(): void
    {
        $res = $this->postJson('/api/capyflix/batch-download', [
            'title' => 'Batch Test Series',
            'media_type' => 'series',
            'season' => 1,
            'episodes' => [1, 2],
            'url' => 'http://example.com/video.mp4',
        ]);

        $res->assertStatus(200)
            ->assertJson([
                'success' => true,
                'result' => [
                    'queued_count' => 2,
                    'skipped_count' => 0,
                ],
            ]);

        // Attempt batch again with episodes 2 and 3: 2 is skipped as duplicate, 3 is queued
        $res2 = $this->postJson('/api/capyflix/batch-download', [
            'title' => 'Batch Test Series',
            'media_type' => 'series',
            'season' => 1,
            'episodes' => [2, 3],
            'url' => 'http://example.com/video.mp4',
        ]);

        $res2->assertStatus(200)
            ->assertJson([
                'success' => true,
                'result' => [
                    'queued_count' => 1,
                    'skipped_count' => 1,
                ],
            ]);
    }

    public function test_qbittorrent_status_endpoint(): void
    {
        $response = $this->getJson('/api/capyflix/qbittorrent-status');
        $response->assertStatus(200)
            ->assertJsonStructure([
                'enabled',
                'available',
                'version',
                'host',
                'port',
                'base_url',
            ]);
    }

    public function test_preference_ranking_animation_favors_dubbed(): void
    {
        $resolver = app(CapyFlixStreamResolverService::class);

        $dubResult = $resolver->evaluateTorrentPreference('Dragon Ball Z S01 1080p Dublado PT-BR Dual Audio', 'anime');
        $origResult = $resolver->evaluateTorrentPreference('Dragon Ball Z S01 1080p Japanese Raw x264', 'anime');

        $this->assertTrue($dubResult['preference_matched']);
        $this->assertStringContainsString('DUB PT-BR', $dubResult['badge']);
        $this->assertGreaterThan($origResult['score'], $dubResult['score']);
    }

    public function test_preference_ranking_movies_and_series_favors_original_with_subs(): void
    {
        $resolver = app(CapyFlixStreamResolverService::class);

        $legResult = $resolver->evaluateTorrentPreference('The OA S01 1080p NF WEB-DL DDP5.1 x265 Legendado PT-BR', 'series');
        $dubOnlyResult = $resolver->evaluateTorrentPreference('The OA S01 720p Dublado Nacional', 'series');

        $this->assertTrue($legResult['preference_matched']);
        $this->assertStringContainsString('LEG PT-BR', $legResult['badge']);
        $this->assertGreaterThan($dubOnlyResult['score'], $legResult['score']);
    }

    public function test_resolve_streams_accepts_boolean_query_params_without_validation_error(): void
    {
        // When frontend passes string booleans 'true' or 'false', controller must not return 422
        $response = $this->getJson('/api/capyflix/resolve-streams?title=Hotel+Assombrado&media_type=series&season=1&episode=1&is_animation=true&include_p2p=true&block_foreign=false');

        $this->assertNotEquals(422, $response->status());
        $response->assertStatus(200)
            ->assertJsonStructure(['streams', 'debrid_configured']);
    }

    public function test_qbittorrent_selective_episodes_logic(): void
    {
        Http::fake([
            '*/api/v2/torrents/files*' => Http::response([
                ['index' => 0, 'name' => 'Haunted.Hotel.S01E01.1080p.mkv', 'size' => 500000000, 'priority' => 1, 'progress' => 0.5],
                ['index' => 1, 'name' => 'Haunted.Hotel.S01E02.1080p.mkv', 'size' => 500000000, 'priority' => 1, 'progress' => 0.0],
                ['index' => 2, 'name' => 'sample.mkv', 'size' => 5000000, 'priority' => 1, 'progress' => 0.0],
            ], 200),
            '*/api/v2/torrents/filePrio' => Http::response('Ok.', 200),
            '*/api/v2/auth/login' => Http::response('Ok.', 200),
        ]);

        $qbit = app(QBittorrentService::class);

        // Keep only episode 1, ignore episode 2 and sample
        $configured = $qbit->configureSelectiveEpisodes('test_hash', 1, [1]);
        $this->assertTrue($configured);

        // Verify episode status lookup
        $ep1 = $qbit->getEpisodeFileStatus('test_hash', 1, 1);
        $this->assertNotNull($ep1);
        $this->assertEquals(0, $ep1['index']);
        $this->assertEquals(0.5, $ep1['progress']);

        $ep2 = $qbit->getEpisodeFileStatus('test_hash', 1, 2);
        $this->assertNotNull($ep2);
        $this->assertEquals(1, $ep2['index']);
    }
}
