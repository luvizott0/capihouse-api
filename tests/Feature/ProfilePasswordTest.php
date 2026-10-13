<?php

namespace Tests\Feature;

use App\Enums\UserRoles;
use App\Enums\UserStatuses;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfilePasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_update_password_without_providing_current_password(): void
    {
        $user = User::factory()->create([
            'role' => UserRoles::User,
            'status' => UserStatuses::APPROVED,
            'password' => Hash::make('old_password_123'),
        ]);

        Sanctum::actingAs($user);

        $response = $this->putJson('/api/profile/password', [
            'password' => 'new_secret_password',
            'password_confirmation' => 'new_secret_password',
        ]);

        $response->assertStatus(200)
            ->assertJson(['message' => 'Senha atualizada com sucesso.']);

        $user->refresh();
        $this->assertTrue(Hash::check('new_secret_password', $user->password));
    }
}
