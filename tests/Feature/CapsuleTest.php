<?php

namespace Tests\Feature;

use App\Enums\MediaType;
use App\Models\CapsuleMemory;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CapsuleTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected User $friend;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'status' => 'approved',
            'username' => 'capyuser',
        ]);

        $this->friend = User::factory()->create([
            'status' => 'approved',
            'username' => 'capyfriend',
        ]);
    }

    public function test_can_create_memory_with_tags_and_participants(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/capsule/memories', [
            'title' => 'Viagem inesquecível para Florianópolis',
            'content' => 'Fomos na praia da Joaquina e depois comemos peixe na lagoa.',
            'occurred_at' => '2023-02-15',
            'occurred_at_precision' => 'exact',
            'location_name' => 'Florianópolis, SC',
            'mood' => 'Nostálgico',
            'mood_emoji' => '🌊',
            'visibility' => 'shared',
            'tags' => ['Viagem', 'Praia', 'Verão'],
            'participants' => [
                ['user_id' => $this->friend->id, 'role' => 'presente'],
                ['name' => 'Tio Beto', 'role' => 'motorista'],
            ],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.title', 'Viagem inesquecível para Florianópolis');
        $response->assertJsonPath('data.is_locked', false);

        $this->assertDatabaseHas('capsule_memories', [
            'title' => 'Viagem inesquecível para Florianópolis',
            'user_id' => $this->user->id,
            'location_name' => 'Florianópolis, SC',
        ]);

        $this->assertDatabaseHas('capsule_tags', [
            'slug' => 'viagem',
        ]);

        $this->assertDatabaseHas('capsule_participants', [
            'name' => 'Tio Beto',
        ]);

        $this->assertDatabaseHas('capsule_participants', [
            'user_id' => $this->friend->id,
        ]);
    }

    public function test_can_upload_photo_and_audio_to_memory(): void
    {
        Storage::fake('public');

        $imageFile = UploadedFile::fake()->image('praia.jpg', 800, 600);
        $audioFile = UploadedFile::fake()->create('gravacao_voz.mp3', 256, 'audio/mpeg');

        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/capsule/memories', [
            'title' => 'Conversa de madrugada com áudio',
            'content' => 'Gravamos essa conversa hilária.',
            'occurred_at' => '2024-05-10',
            'media' => [
                $imageFile,
                $audioFile,
            ],
        ]);

        $response->assertStatus(201);
        $memoryId = $response->json('data.id');

        $memory = CapsuleMemory::with('media')->find($memoryId);
        $this->assertCount(2, $memory->media);

        $types = $memory->media->pluck('type')->all();
        $this->assertContains(MediaType::IMAGE, $types);
        $this->assertContains(MediaType::AUDIO, $types);
    }

    public function test_filters_memories_by_year_and_search(): void
    {
        CapsuleMemory::create([
            'user_id' => $this->user->id,
            'title' => 'Aniversário de 2022',
            'content' => 'Bolo de chocolate maravilhoso',
            'occurred_at' => '2022-08-20',
            'visibility' => 'shared',
        ]);

        CapsuleMemory::create([
            'user_id' => $this->user->id,
            'title' => 'Aniversário de 2023',
            'content' => 'Churrasco no quintal com os amigos',
            'occurred_at' => '2023-08-20',
            'visibility' => 'shared',
        ]);

        CapsuleMemory::create([
            'user_id' => $this->user->id,
            'title' => 'Passeio no museu',
            'content' => 'Tarde chuvosa aprendendo sobre história',
            'occurred_at' => '2023-11-05',
            'visibility' => 'shared',
        ]);

        // 1. Filtrar por ano 2023
        $respYear = $this->actingAs($this->user, 'sanctum')->getJson('/api/capsule/memories?year=2023');
        $respYear->assertStatus(200);
        $this->assertEquals(2, count($respYear->json('data')));

        // 2. Busca por palavra-chave 'chocolate'
        $respSearch = $this->actingAs($this->user, 'sanctum')->getJson('/api/capsule/memories?search=chocolate');
        $respSearch->assertStatus(200);
        $this->assertEquals(1, count($respSearch->json('data')));
        $this->assertEquals('Aniversário de 2022', $respSearch->json('data.0.title'));
    }

    public function test_locked_capsule_masks_content_until_unlocked(): void
    {
        $lockedFuture = Carbon::now()->addYears(2);

        $memory = CapsuleMemory::create([
            'user_id' => $this->user->id,
            'title' => 'Cápsula para o Futuro: 10 Anos Juntos',
            'content' => 'Segredo que só deve ser lido daqui a 2 anos!',
            'occurred_at' => '2024-01-01',
            'locked_until' => $lockedFuture,
            'visibility' => 'shared',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->getJson("/api/capsule/memories/{$memory->id}");

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertTrue($data['is_locked']);
        $this->assertStringNotContainsString('Segredo que só deve ser lido', $data['content']);
        $this->assertStringContainsString('Esta cápsula está lacrada no tempo', $data['content']);
    }

    public function test_on_this_day_returns_memories_from_past_years(): void
    {
        // Cria memória no mesmo dia e mês, mas há 2 anos
        $pastDate = Carbon::now()->subYears(2)->format('Y-m-d');
        CapsuleMemory::create([
            'user_id' => $this->user->id,
            'title' => 'Aconteceu exatamente neste dia há 2 anos!',
            'content' => 'Dia inesquecível',
            'occurred_at' => $pastDate,
            'visibility' => 'shared',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->getJson('/api/capsule/on-this-day');

        $response->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, $response->json('total_memories'));
        $this->assertEquals(2, $response->json('timeline.0.years_ago'));
    }

    public function test_can_update_toggle_favorite_and_delete_memory(): void
    {
        $memory = CapsuleMemory::create([
            'user_id' => $this->user->id,
            'title' => 'Memória Antiga',
            'content' => 'Texto antes',
            'occurred_at' => '2024-03-01',
            'is_favorite' => false,
            'visibility' => 'shared',
        ]);

        // Toggle Favorite
        $favResp = $this->actingAs($this->user, 'sanctum')->postJson("/api/capsule/memories/{$memory->id}/toggle-favorite");
        $favResp->assertStatus(200);
        $favResp->assertJsonPath('is_favorite', true);
        $this->assertTrue($memory->fresh()->is_favorite);

        // Update
        $updResp = $this->actingAs($this->user, 'sanctum')->putJson("/api/capsule/memories/{$memory->id}", [
            'title' => 'Título Atualizado com Sucesso',
            'content' => 'Texto alterado e refinado',
        ]);
        $updResp->assertStatus(200);
        $this->assertEquals('Título Atualizado com Sucesso', $memory->fresh()->title);

        // Delete
        $delResp = $this->actingAs($this->user, 'sanctum')->deleteJson("/api/capsule/memories/{$memory->id}");
        $delResp->assertStatus(200);
        $this->assertDatabaseMissing('capsule_memories', ['id' => $memory->id]);
    }

    public function test_private_memory_is_only_visible_to_author_and_shared_participants(): void
    {
        $otherUser = User::factory()->create(['status' => 'approved']);

        $memory = CapsuleMemory::create([
            'user_id' => $this->user->id,
            'title' => 'Memória Privada Só com Amigo',
            'content' => 'Conteúdo secreto',
            'occurred_at' => '2024-01-01',
            'visibility' => 'private',
        ]);

        $memory->participants()->create([
            'user_id' => $this->friend->id,
            'role' => 'presente',
        ]);

        // Autor pode ver
        $this->actingAs($this->user, 'sanctum')->getJson("/api/capsule/memories/{$memory->id}")
            ->assertStatus(200);

        // Amigo marcado pode ver
        $this->actingAs($this->friend, 'sanctum')->getJson("/api/capsule/memories/{$memory->id}")
            ->assertStatus(200);

        // Usuário não autorizado não pode ver
        $this->actingAs($otherUser, 'sanctum')->getJson("/api/capsule/memories/{$memory->id}")
            ->assertStatus(403);
    }
}
