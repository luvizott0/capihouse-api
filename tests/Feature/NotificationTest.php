<?php

namespace Tests\Feature;

use App\Enums\UserRoles;
use App\Enums\UserStatuses;
use App\Models\AppNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private function createApprovedUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'status' => UserStatuses::APPROVED,
            'role' => UserRoles::User,
        ], $attributes));
    }

    public function test_user_can_list_notifications_and_read_them()
    {
        $user = $this->createApprovedUser();

        $n1 = AppNotification::create([
            'user_id' => $user->id,
            'type' => 'system',
            'title' => 'Bem-vindo ao CapiHouse',
            'content' => 'Explore o app',
        ]);

        $n2 = AppNotification::create([
            'user_id' => $user->id,
            'type' => 'system',
            'title' => 'Segunda notificação',
            'content' => 'Teste',
        ]);

        // Check unread count
        $countRes = $this->actingAs($user)->getJson('/api/notifications/unread-count');
        $countRes->assertStatus(200)
            ->assertJson(['unread_count' => 2]);

        // List notifications
        $listRes = $this->actingAs($user)->getJson('/api/notifications');
        $listRes->assertStatus(200)
            ->assertJsonCount(2, 'data');

        // Mark single as read
        $readRes = $this->actingAs($user)->patchJson("/api/notifications/{$n1->id}/read");
        $readRes->assertStatus(200);

        $this->assertNotNull($n1->fresh()->read_at);
        $this->assertNull($n2->fresh()->read_at);

        // Check count is now 1
        $countRes2 = $this->actingAs($user)->getJson('/api/notifications/unread-count');
        $countRes2->assertJson(['unread_count' => 1]);

        // Mark all as read
        $readAllRes = $this->actingAs($user)->postJson('/api/notifications/read-all');
        $readAllRes->assertStatus(200);

        $this->assertNotNull($n2->fresh()->read_at);

        // Check count is 0
        $countRes3 = $this->actingAs($user)->getJson('/api/notifications/unread-count');
        $countRes3->assertJson(['unread_count' => 0]);
    }
}
