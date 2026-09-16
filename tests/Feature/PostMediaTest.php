<?php

namespace Tests\Feature;

use App\Enums\UserRoles;
use App\Enums\UserStatuses;
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
}
