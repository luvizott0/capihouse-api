<?php

namespace Tests\Feature;

use App\Enums\UserRoles;
use App\Enums\UserStatuses;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $pendingUser;
    private User $approvedUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => UserRoles::Admin,
            'status' => UserStatuses::APPROVED,
        ]);

        $this->approvedUser = User::factory()->create([
            'role' => UserRoles::User,
            'status' => UserStatuses::APPROVED,
        ]);

        $this->pendingUser = User::factory()->create([
            'role' => UserRoles::User,
            'status' => UserStatuses::PENDING,
        ]);
    }

    public function test_non_admin_cannot_access_user_management(): void
    {
        Sanctum::actingAs($this->approvedUser);

        $response = $this->getJson('/api/admin/users');
        $response->assertStatus(403);
    }

    public function test_admin_can_list_users_with_empty_filters(): void
    {
        Sanctum::actingAs($this->admin);

        // Sending empty status and search query params should NOT filter out all users
        $response = $this->getJson('/api/admin/users?status=&search=');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'username', 'status', 'role'],
                ],
                'meta' => ['current_page', 'last_page', 'total'],
            ]);

        $data = $response->json('data');
        $this->assertGreaterThanOrEqual(3, count($data));

        // Pending user should be prioritized at the top
        $this->assertEquals(UserStatuses::PENDING->value, $data[0]['status']);
    }

    public function test_admin_can_filter_users_by_status(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/admin/users?status=pending');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertNotEmpty($data);
        foreach ($data as $item) {
            $this->assertEquals(UserStatuses::PENDING->value, $item['status']);
        }
    }

    public function test_admin_can_approve_user_via_patch_and_post(): void
    {
        Sanctum::actingAs($this->admin);

        // Test POST (as sent by frontend)
        $responsePost = $this->postJson("/api/admin/users/{$this->pendingUser->id}/approve");
        $responsePost->assertStatus(200);
        $this->pendingUser->refresh();
        $this->assertEquals(UserStatuses::APPROVED, $this->pendingUser->status);

        // Reset to pending and test PATCH
        $this->pendingUser->update(['status' => UserStatuses::PENDING]);
        $responsePatch = $this->patchJson("/api/admin/users/{$this->pendingUser->id}/approve");
        $responsePatch->assertStatus(200);
        $this->pendingUser->refresh();
        $this->assertEquals(UserStatuses::APPROVED, $this->pendingUser->status);
    }

    public function test_admin_can_reject_user_via_patch_and_post(): void
    {
        Sanctum::actingAs($this->admin);

        // Test POST
        $responsePost = $this->postJson("/api/admin/users/{$this->pendingUser->id}/reject");
        $responsePost->assertStatus(200);
        $this->pendingUser->refresh();
        $this->assertEquals(UserStatuses::REJECTED, $this->pendingUser->status);

        // Reset and test PATCH
        $this->pendingUser->update(['status' => UserStatuses::PENDING]);
        $responsePatch = $this->patchJson("/api/admin/users/{$this->pendingUser->id}/reject");
        $responsePatch->assertStatus(200);
        $this->pendingUser->refresh();
        $this->assertEquals(UserStatuses::REJECTED, $this->pendingUser->status);
    }

    public function test_admin_can_ban_and_unban_user(): void
    {
        Sanctum::actingAs($this->admin);

        $responseBan = $this->postJson("/api/admin/users/{$this->approvedUser->id}/ban");
        $responseBan->assertStatus(200);
        $this->approvedUser->refresh();
        $this->assertEquals(UserStatuses::BANNED, $this->approvedUser->status);

        $responseUnban = $this->postJson("/api/admin/users/{$this->approvedUser->id}/unban");
        $responseUnban->assertStatus(200);
        $this->approvedUser->refresh();
        $this->assertEquals(UserStatuses::APPROVED, $this->approvedUser->status);
    }
}
