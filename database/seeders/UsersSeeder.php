<?php

namespace Database\Seeders;

use App\Enums\UserRoles;
use App\Enums\UserStatuses;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UsersSeeder extends Seeder
{
    public function run(): void
    {
        // Usuário clássico Capivara Rogéria (Admin)
        User::create([
            'name' => 'Capivara Rogéria',
            'username' => 'capivara.rogeria',
            'email' => 'capivara@rogeria.com',
            'password' => Hash::make('password'),
            'role' => UserRoles::Admin,
            'status' => UserStatuses::APPROVED,
            'birth' => '2023-12-10',
            'bio' => 'A capivara fundadora do CapiHouse!',
        ]);

        User::create([
            'name' => 'Admin User',
            'username' => 'admin',
            'email' => 'admin@capihouse.com',
            'password' => Hash::make('password'),
            'role' => UserRoles::Admin,
            'status' => UserStatuses::APPROVED,
        ]);

        User::create([
            'name' => 'Regular User',
            'username' => 'user',
            'email' => 'user@capihouse.com',
            'password' => Hash::make('password'),
            'role' => UserRoles::User,
            'status' => UserStatuses::APPROVED,
        ]);

        User::create([
            'name' => 'Pending User',
            'username' => 'pending',
            'email' => 'pending@capihouse.com',
            'password' => Hash::make('password'),
            'role' => UserRoles::User,
            'status' => UserStatuses::PENDING,
        ]);

        User::create([
            'name' => 'Banned User',
            'username' => 'banned',
            'email' => 'banned@capihouse.com',
            'password' => Hash::make('password'),
            'role' => UserRoles::User,
            'status' => UserStatuses::BANNED,
        ]);
    }
}
