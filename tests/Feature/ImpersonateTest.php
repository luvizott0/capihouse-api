<?php

namespace Tests\Feature;

use App\Enums\UserRoles;
use App\Enums\UserStatuses;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ImpersonateTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_impersonate_by_username_in_local_environment(): void
    {
        $this->app->detectEnvironment(fn () => 'local');

        $user = User::factory()->create([
            'username' => 'bento',
            'role' => UserRoles::User,
            'status' => UserStatuses::APPROVED,
        ]);

        $response = $this->postJson('/api/auth/impersonate', [
            'login' => 'bento',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'user' => ['id', 'name', 'username'],
                'token',
                'impersonating',
            ])
            ->assertJson([
                'user' => ['username' => 'bento'],
                'impersonating' => true,
            ]);
    }

    public function test_can_impersonate_by_user_id_in_local_environment(): void
    {
        $this->app->detectEnvironment(fn () => 'local');

        $user = User::factory()->create([
            'username' => 'pipoca',
            'role' => UserRoles::User,
            'status' => UserStatuses::APPROVED,
        ]);

        $response = $this->postJson('/api/auth/impersonate', [
            'user_id' => $user->id,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'user' => ['id' => $user->id, 'username' => 'pipoca'],
                'impersonating' => true,
            ]);
    }

    public function test_admin_can_impersonate_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $admin = User::factory()->create([
            'role' => UserRoles::Admin,
            'status' => UserStatuses::APPROVED,
        ]);

        $targetUser = User::factory()->create([
            'username' => 'alvo',
            'role' => UserRoles::User,
            'status' => UserStatuses::APPROVED,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/auth/impersonate', [
            'login' => 'alvo',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'user' => ['username' => 'alvo'],
                'impersonating' => true,
            ]);
    }

    public function test_admin_route_impersonates_user(): void
    {
        $admin = User::factory()->create([
            'role' => UserRoles::Admin,
            'status' => UserStatuses::APPROVED,
        ]);

        $targetUser = User::factory()->create([
            'username' => 'alvo_admin',
            'role' => UserRoles::User,
            'status' => UserStatuses::APPROVED,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/admin/users/{$targetUser->id}/impersonate");

        $response->assertStatus(200)
            ->assertJson([
                'user' => ['username' => 'alvo_admin'],
                'impersonating' => true,
            ]);
    }

    public function test_unauthenticated_non_admin_cannot_impersonate_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $user = User::factory()->create([
            'username' => 'vitima',
            'role' => UserRoles::User,
            'status' => UserStatuses::APPROVED,
        ]);

        $response = $this->postJson('/api/auth/impersonate', [
            'login' => 'vitima',
        ]);

        $response->assertStatus(403);
    }

    public function test_dev_users_endpoint_returns_users_in_local_and_404_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        User::factory()->create(['username' => 'teste1']);

        $response = $this->getJson('/api/auth/dev-users');
        $response->assertStatus(200)
            ->assertJsonStructure(['users']);

        $this->app->detectEnvironment(fn () => 'production');
        $responseProd = $this->getJson('/api/auth/dev-users');
        $responseProd->assertStatus(404);
    }
}
