<?php

namespace Tests\Feature;

use App\Services\CapyFlixStorageService;
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
        $this->assertEquals('Acervo P2P / Web', $response->json('streams.0.name'));
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
}
