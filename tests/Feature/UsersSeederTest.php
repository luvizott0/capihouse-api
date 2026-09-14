<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\UsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsersSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_only_capivara_rogeria_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        (new UsersSeeder())->run();

        $this->assertEquals(1, User::count());
        $user = User::first();
        $this->assertEquals('capivara.rogeria', $user->username);
        $this->assertEquals('Capivara Rogéria', $user->name);
        $this->assertTrue($user->isAdmin());
        $this->assertTrue($user->isApproved());
    }

    public function test_seeder_creates_11_users_in_local_environment(): void
    {
        $this->app->detectEnvironment(fn () => 'local');

        (new UsersSeeder())->run();

        // Capivara Rogéria + 10 usuários locais de teste = 11
        $this->assertEquals(11, User::count());

        $this->assertDatabaseHas('users', ['username' => 'capivara.rogeria']);
        $this->assertDatabaseHas('users', ['username' => 'bento']);
        $this->assertDatabaseHas('users', ['username' => 'pipoca']);
        $this->assertDatabaseHas('users', ['username' => 'tiago']);
        $this->assertDatabaseHas('users', ['username' => 'luna']);
        $this->assertDatabaseHas('users', ['username' => 'chico']);
        $this->assertDatabaseHas('users', ['username' => 'maya']);
        $this->assertDatabaseHas('users', ['username' => 'gabriel']);
        $this->assertDatabaseHas('users', ['username' => 'olivia']);
        $this->assertDatabaseHas('users', ['username' => 'pedro.novato']);
        $this->assertDatabaseHas('users', ['username' => 'zeca.travesso']);
    }
}
