<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use App\Services\StorageHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StorageHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        StorageHealthService::resetFake();
        parent::tearDown();
    }

    public function test_can_check_storage_status_endpoint(): void
    {
        $user = User::factory()->create([
            'status' => 'approved',
        ]);

        $response = $this->actingAs($user)->getJson('/api/system/storage-status');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'available',
                'disk',
                'message',
                'checked_at',
            ]);
    }

    public function test_storage_status_returns_unavailable_when_faked_offline(): void
    {
        $user = User::factory()->create([
            'status' => 'approved',
        ]);

        StorageHealthService::fake(false);

        $response = $this->actingAs($user)->getJson('/api/system/storage-status');

        $response->assertStatus(200)
            ->assertJson([
                'available' => false,
            ]);
    }

    public function test_allows_creating_text_post_when_storage_is_offline(): void
    {
        $user = User::factory()->create([
            'status' => 'approved',
        ]);

        StorageHealthService::fake(false);

        $response = $this->actingAs($user)->postJson('/api/posts', [
            'content' => 'Publicação apenas em texto enquanto o NAS está fora do ar!',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('posts', [
            'content' => 'Publicação apenas em texto enquanto o NAS está fora do ar!',
            'user_id' => $user->id,
        ]);
    }

    public function test_blocks_image_upload_when_storage_is_offline(): void
    {
        Storage::fake('public');

        $user = User::factory()->create([
            'status' => 'approved',
        ]);

        StorageHealthService::fake(false);

        $file = UploadedFile::fake()->image('capivara.jpg', 800, 600);

        $response = $this->actingAs($user)->postJson('/api/posts', [
            'content' => 'Tentando postar imagem com storage offline',
            'media' => [$file],
        ]);

        $response->assertStatus(503)
            ->assertJsonFragment([
                'message' => 'O servidor de armazenamento (NAS) está temporariamente offline. Não é possível enviar fotos ou vídeos no momento. Remova os arquivos de mídia e publique apenas o conteúdo de texto.',
            ]);

        $this->assertDatabaseMissing('posts', [
            'content' => 'Tentando postar imagem com storage offline',
        ]);
    }

    public function test_blocks_adding_media_on_update_when_storage_is_offline(): void
    {
        Storage::fake('public');

        $user = User::factory()->create([
            'status' => 'approved',
        ]);

        $post = Post::create([
            'user_id' => $user->id,
            'content' => 'Post existente',
            'category' => 'feed',
        ]);

        StorageHealthService::fake(false);

        $file = UploadedFile::fake()->image('nova_foto.jpg', 800, 600);

        $response = $this->actingAs($user)->postJson("/api/posts/{$post->id}", [
            'content' => 'Post atualizado',
            'media' => [$file],
        ]);

        $response->assertStatus(503)
            ->assertJsonFragment([
                'message' => 'O servidor de armazenamento (NAS) está temporariamente offline. Não é possível enviar novas fotos ou vídeos no momento.',
            ]);
    }

    public function test_allows_updating_text_when_storage_is_offline(): void
    {
        $user = User::factory()->create([
            'status' => 'approved',
        ]);

        $post = Post::create([
            'user_id' => $user->id,
            'content' => 'Texto anterior',
            'category' => 'feed',
        ]);

        StorageHealthService::fake(false);

        $response = $this->actingAs($user)->postJson("/api/posts/{$post->id}", [
            'content' => 'Texto devidamente atualizado sem anexar imagens',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('posts', [
            'id' => $post->id,
            'content' => 'Texto devidamente atualizado sem anexar imagens',
        ]);
    }
}
