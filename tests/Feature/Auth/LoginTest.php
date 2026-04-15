<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_user_can_login_with_email(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => 'password123',
            'status' => 'approved',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'login' => 'test@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Login realizado com sucesso.',
            ])
            ->assertJsonPath('data.user.email', 'test@example.com')
            ->assertJsonPath('data.user.status', 'approved');

        $this->assertAuthenticatedAs($user);
    }

    public function test_approved_user_can_login_with_username(): void
    {
        $user = User::factory()->create([
            'username' => 'testuser',
            'password' => 'password123',
            'status' => 'approved',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'login' => 'testuser',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Login realizado com sucesso.',
            ])
            ->assertJsonPath('data.user.username', 'testuser');

        $this->assertAuthenticatedAs($user);
    }

    public function test_pending_user_cannot_login(): void
    {
        User::factory()->create([
            'email' => 'pending@example.com',
            'password' => 'password123',
            'status' => 'pending',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'login' => 'pending@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'status' => 'error',
                'message' => 'Sua conta ainda está pendente de aprovação.',
            ]);
    }

    public function test_rejected_user_cannot_login(): void
    {
        User::factory()->create([
            'email' => 'rejected@example.com',
            'password' => 'password123',
            'status' => 'rejected',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'login' => 'rejected@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'status' => 'error',
                'message' => 'Sua solicitação de conta foi rejeitada.',
            ]);
    }

    public function test_suspended_user_cannot_login(): void
    {
        User::factory()->create([
            'email' => 'suspended@example.com',
            'password' => 'password123',
            'status' => 'suspended',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'login' => 'suspended@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'status' => 'error',
                'message' => 'Sua conta está suspensa.',
            ]);
    }

    public function test_login_fails_with_invalid_credentials(): void
    {
        User::factory()->create([
            'email' => 'test@example.com',
            'password' => 'password123',
            'status' => 'approved',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'login' => 'test@example.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'status' => 'error',
                'message' => 'Credenciais inválidas.',
            ]);
    }

    public function test_login_fails_with_nonexistent_user(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'login' => 'nonexistent@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'status' => 'error',
                'message' => 'Credenciais inválidas.',
            ]);
    }

    public function test_authenticated_user_can_logout(): void
    {
        $user = User::factory()->create([
            'status' => 'approved',
        ]);

        $response = $this->actingAs($user)
            ->postJson('/api/v1/auth/logout');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Logout realizado com sucesso.',
            ]);
    }

    public function test_authenticated_user_can_get_their_data(): void
    {
        $user = User::factory()->create([
            'name' => 'Test User',
            'username' => 'testuser',
            'email' => 'test@example.com',
            'status' => 'approved',
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/v1/auth/user');

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'Test User')
            ->assertJsonPath('data.username', 'testuser')
            ->assertJsonPath('data.email', 'test@example.com');
    }

    public function test_unauthenticated_user_cannot_get_user_data(): void
    {
        $response = $this->getJson('/api/v1/auth/user');

        $response->assertStatus(401);
    }

    public function test_unauthenticated_user_cannot_logout(): void
    {
        $response = $this->postJson('/api/v1/auth/logout');

        $response->assertStatus(401);
    }
}
