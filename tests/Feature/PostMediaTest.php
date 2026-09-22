<?php

namespace Tests\Feature;

use App\Enums\UserRoles;
use App\Enums\UserStatuses;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PostMediaTest extends TestCase
{
    use RefreshDatabase;

    private function createApprovedUser(): User
    {
        return User::factory()->create([
            'status' => UserStatuses::APPROVED,
            'role' => UserRoles::User,
        ]);
    }

    public function test_post_creation_with_up_to_5_images_succeeds()
    {
        Storage::fake('public');
        $user = $this->createApprovedUser();

        $files = [
            UploadedFile::fake()->image('foto1.jpg', 600, 600),
            UploadedFile::fake()->image('foto2.png', 600, 600),
            UploadedFile::fake()->image('foto3.webp', 600, 600),
            UploadedFile::fake()->image('foto4.jpg', 600, 600),
            UploadedFile::fake()->image('foto5.jpg', 600, 600),
        ];

        $res = $this->actingAs($user)->post('/api/posts', [
            'content' => 'Post com 5 fotos',
            'media' => $files,
        ], ['Accept' => 'application/json']);

        $res->assertStatus(201);
        $this->assertCount(5, $res->json('media'));
    }

    public function test_post_creation_rejects_more_than_5_images()
    {
        Storage::fake('public');
        $user = $this->createApprovedUser();

        $files = [
            UploadedFile::fake()->image('foto1.jpg'),
            UploadedFile::fake()->image('foto2.jpg'),
            UploadedFile::fake()->image('foto3.jpg'),
            UploadedFile::fake()->image('foto4.jpg'),
            UploadedFile::fake()->image('foto5.jpg'),
            UploadedFile::fake()->image('foto6.jpg'),
        ];

        $res = $this->actingAs($user)->postJson('/api/posts', [
            'content' => 'Post com 6 fotos',
            'media' => $files,
        ]);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['media']);
        $this->assertEquals('Você pode anexar no máximo 5 arquivos de mídia.', $res->json('errors.media.0'));
    }

    public function test_post_creation_rejects_file_larger_than_20mb()
    {
        Storage::fake('public');
        $user = $this->createApprovedUser();

        $largeFile = UploadedFile::fake()->create('heavy.jpg', 21000, 'image/jpeg');

        $res = $this->actingAs($user)->post('/api/posts', [
            'content' => 'Post com foto muito grande',
            'media' => [$largeFile],
        ], ['Accept' => 'application/json']);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['media.0']);
        $this->assertEquals('Cada arquivo de mídia pode ter no máximo 20MB.', $res->json('errors')['media.0'][0]);
    }

    public function test_post_creation_detects_post_max_size_overflow()
    {
        $user = $this->createApprovedUser();

        // Simulate a request where CONTENT_LENGTH is set, but payload was dumped by PHP
        $res = $this->actingAs($user)->call(
            'POST',
            '/api/posts',
            [], // parameters empty
            [], // cookies
            [], // files empty
            [
                'CONTENT_LENGTH' => 85000000,
                'HTTP_ACCEPT' => 'application/json',
            ]
        );

        $res->assertStatus(413);
        $res->assertJson([
            'message' => 'O tamanho total dos arquivos enviados ultrapassou o limite máximo aceito pelo servidor. Reduza o tamanho ou a quantidade das imagens.',
        ]);
    }

    public function test_post_update_can_add_images()
    {
        Storage::fake('public');
        $user = $this->createApprovedUser();

        $createRes = $this->actingAs($user)->post('/api/posts', [
            'content' => 'Post original sem foto',
        ], ['Accept' => 'application/json']);

        $postId = $createRes->json('id');

        $updateRes = $this->actingAs($user)->post("/api/posts/{$postId}", [
            '_method' => 'PUT',
            'content' => 'Post atualizado com foto',
            'media' => [UploadedFile::fake()->image('nova_foto.jpg', 400, 400)],
        ], ['Accept' => 'application/json']);

        $updateRes->assertStatus(200);
        $this->assertCount(1, $updateRes->json('media'));
        $this->assertEquals('Post atualizado com foto', $updateRes->json('content'));
    }

    public function test_post_update_can_remove_existing_image_and_deletes_from_storage()
    {
        $disk = config('filesystems.default', 'public');
        Storage::fake($disk);
        $user = $this->createApprovedUser();

        $createRes = $this->actingAs($user)->post('/api/posts', [
            'content' => 'Post com foto inicial',
            'media' => [UploadedFile::fake()->image('foto_remover.jpg', 400, 400)],
        ], ['Accept' => 'application/json']);

        $postId = $createRes->json('id');
        $mediaId = $createRes->json('media.0.id');
        $media = Media::find($mediaId);
        $rawPath = $media->getRawOriginal('path');

        $this->assertTrue(Storage::disk($disk)->exists($rawPath));

        $updateRes = $this->actingAs($user)->post("/api/posts/{$postId}", [
            '_method' => 'PUT',
            'content' => 'Post agora sem foto',
            'remove_media_ids' => [$mediaId],
        ], ['Accept' => 'application/json']);

        $updateRes->assertStatus(200);
        $this->assertCount(0, $updateRes->json('media'));
        $this->assertDatabaseMissing('media', ['id' => $mediaId]);
        $this->assertFalse(Storage::disk($disk)->exists($rawPath));
    }

    public function test_post_update_can_replace_images()
    {
        Storage::fake('public');
        $user = $this->createApprovedUser();

        $createRes = $this->actingAs($user)->post('/api/posts', [
            'content' => 'Post original com 1 foto',
            'media' => [UploadedFile::fake()->image('antiga.jpg', 400, 400)],
        ], ['Accept' => 'application/json']);

        $postId = $createRes->json('id');
        $oldMediaId = $createRes->json('media.0.id');

        $updateRes = $this->actingAs($user)->post("/api/posts/{$postId}", [
            '_method' => 'PUT',
            'content' => 'Post com foto trocada',
            'remove_media_ids' => [$oldMediaId],
            'media' => [UploadedFile::fake()->image('nova.png', 400, 400)],
        ], ['Accept' => 'application/json']);

        $updateRes->assertStatus(200);
        $this->assertCount(1, $updateRes->json('media'));
        $this->assertNotEquals($oldMediaId, $updateRes->json('media.0.id'));
        $this->assertDatabaseMissing('media', ['id' => $oldMediaId]);
    }

    public function test_post_update_rejects_exceeding_total_5_images()
    {
        Storage::fake('public');
        $user = $this->createApprovedUser();

        $createRes = $this->actingAs($user)->post('/api/posts', [
            'content' => 'Post com 3 fotos',
            'media' => [
                UploadedFile::fake()->image('f1.jpg'),
                UploadedFile::fake()->image('f2.jpg'),
                UploadedFile::fake()->image('f3.jpg'),
            ],
        ], ['Accept' => 'application/json']);

        $postId = $createRes->json('id');

        // Tentar adicionar mais 3 fotos (total seria 6 > 5)
        $updateRes = $this->actingAs($user)->post("/api/posts/{$postId}", [
            '_method' => 'PUT',
            'media' => [
                UploadedFile::fake()->image('f4.jpg'),
                UploadedFile::fake()->image('f5.jpg'),
                UploadedFile::fake()->image('f6.jpg'),
            ],
        ], ['Accept' => 'application/json']);

        $updateRes->assertStatus(422);
        $this->assertEquals('Você pode anexar no máximo 5 arquivos de mídia.', $updateRes->json('message'));
    }

    public function test_post_update_rejects_removing_all_media_if_no_content()
    {
        Storage::fake('public');
        $user = $this->createApprovedUser();

        $createRes = $this->actingAs($user)->post('/api/posts', [
            'content' => '',
            'media' => [UploadedFile::fake()->image('foto_unica.jpg')],
        ], ['Accept' => 'application/json']);

        $postId = $createRes->json('id');
        $mediaId = $createRes->json('media.0.id');

        $updateRes = $this->actingAs($user)->post("/api/posts/{$postId}", [
            '_method' => 'PUT',
            'content' => '',
            'remove_media_ids' => [$mediaId],
        ], ['Accept' => 'application/json']);

        $updateRes->assertStatus(422);
        $this->assertEquals('O post precisa ter texto, mídia ou votação.', $updateRes->json('message'));
    }
}
