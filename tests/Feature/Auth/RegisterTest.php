<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_with_valid_data(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Test User',
            'username' => 'testuser',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'status' => 'success',
                'message' => 'Registro realizado com sucesso. Aguarde a aprovação do administrador.',
            ])
            ->assertJsonPath('data.name', 'Test User')
            ->assertJsonPath('data.username', 'testuser')
            ->assertJsonPath('data.email', 'test@example.com')
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('users', [
            'name' => 'Test User',
            'username' => 'testuser',
            'email' => 'test@example.com',
            'status' => 'pending',
        ]);
    }

    public function test_registration_requires_name(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'username' => 'testuser',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('data.name.0', 'O nome é obrigatório.');
    }

    public function test_registration_requires_username(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('data.username.0', 'O nome de usuário é obrigatório.');
    }

    public function test_registration_requires_unique_username(): void
    {
        User::factory()->create(['username' => 'existinguser']);

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Test User',
            'username' => 'existinguser',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('data.username.0', 'Este nome de usuário já está em uso.');
    }

    public function test_registration_requires_unique_email(): void
    {
        User::factory()->create(['email' => 'existing@example.com']);

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Test User',
            'username' => 'testuser',
            'email' => 'existing@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('data.email.0', 'Este email já está em uso.');
    }

    public function test_registration_requires_password_confirmation(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Test User',
            'username' => 'testuser',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'differentpassword',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('data.password.0', 'A confirmação de senha não confere.');
    }

    public function test_registration_requires_minimum_password_length(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Test User',
            'username' => 'testuser',
            'email' => 'test@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('data.password.0', 'A senha deve ter no mínimo 8 caracteres.');
    }

    public function test_username_only_allows_alpha_dash_characters(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Test User',
            'username' => 'test user!',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('data.username.0', 'O nome de usuário deve conter apenas letras, números, traços e underscores.');
    }
}
