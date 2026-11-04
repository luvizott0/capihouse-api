<?php

namespace Tests\Feature;

use App\Enums\UserRoles;
use App\Enums\UserStatuses;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserOnlineStatusTest extends TestCase
{
    use RefreshDatabase;

    private function createApprovedUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'status' => UserStatuses::APPROVED,
            'role' => UserRoles::User,
        ], $attributes));
    }

    public function test_heartbeat_updates_user_last_seen_at()
    {
        $user = $this->createApprovedUser([
            'last_seen_at' => now()->subMinutes(10),
        ]);

        $response = $this->actingAs($user)->postJson('/api/users/heartbeat');
        $response->assertStatus(200);
        $response->assertJsonStructure(['status', 'last_seen_at']);

        $user->refresh();
        $this->assertTrue($user->last_seen_at->gte(now()->subSeconds(5)));
    }

    public function test_users_online_endpoint_returns_only_recently_seen_users()
    {
        $activeUser = $this->createApprovedUser([
            'name' => 'Usuário Ativo',
            'last_seen_at' => now(),
        ]);

        $inactiveUser = $this->createApprovedUser([
            'name' => 'Usuário Ausente',
            'last_seen_at' => now()->subMinutes(10),
        ]);

        $response = $this->actingAs($activeUser)->getJson('/api/users/online');
        $response->assertStatus(200);

        $ids = collect($response->json())->pluck('id')->toArray();
        $this->assertContains($activeUser->id, $ids);
        $this->assertNotContains($inactiveUser->id, $ids);
    }

    public function test_user_resource_and_list_resource_reflect_accurate_is_online()
    {
        $viewer = $this->createApprovedUser(['last_seen_at' => now()]);
        $onlineTarget = $this->createApprovedUser(['username' => 'onlinetarget', 'last_seen_at' => now()]);
        $offlineTarget = $this->createApprovedUser(['username' => 'offlinetarget', 'last_seen_at' => now()->subMinutes(10)]);

        // Test UserListResource via GET /api/users
        $listResponse = $this->actingAs($viewer)->getJson('/api/users');
        $listResponse->assertStatus(200);

        $users = collect($listResponse->json());
        $onlineUserItem = $users->firstWhere('id', $onlineTarget->id);
        $offlineUserItem = $users->firstWhere('id', $offlineTarget->id);

        $this->assertTrue($onlineUserItem['is_online']);
        $this->assertFalse($offlineUserItem['is_online']);

        // Test UserResource via GET /api/users/{username}
        $profileResponseOnline = $this->actingAs($viewer)->getJson('/api/users/onlinetarget');
        $profileResponseOnline->assertStatus(200);
        $this->assertTrue($profileResponseOnline->json('is_online'));

        $profileResponseOffline = $this->actingAs($viewer)->getJson('/api/users/offlinetarget');
        $profileResponseOffline->assertStatus(200);
        $this->assertFalse($profileResponseOffline->json('is_online'));
    }

    public function test_presence_channel_online_authorizes_authenticated_user()
    {
        $user = $this->createApprovedUser([
            'name' => 'Usuário Presença',
            'username' => 'presenca_user',
        ]);

        $response = $this->actingAs($user)->post('/api/broadcasting/auth', [
            'channel_name' => 'presence-online',
            'socket_id' => '1234.5678',
        ]);

        $response->assertStatus(200);
    }
}
