<?php

namespace Tests\Feature;

use App\Services\CapyFlixStorageService;
use App\Services\CapyFlixStreamResolverService;
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
}
