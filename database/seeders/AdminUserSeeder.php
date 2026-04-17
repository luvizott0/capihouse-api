<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class AdminUserSeeder extends Seeder
{
    /**
     * Seed a development admin user.
     */
    public function run(): void
    {
        $adminRole = Role::firstOrCreate([
            'name' => 'admin',
            'guard_name' => 'web',
        ]);

        $user = User::updateOrCreate(
            ['email' => 'capivara@rogeria.com'],
            [
                'name' => 'Capivara Rogéria',
                'username' => 'capivara_rogeria',
                'password' => 'password',
                'status' => User::STATUS_APPROVED,
            ]
        );

        if (! $user->hasRole($adminRole->name)) {
            $user->assignRole($adminRole);
        }
    }
}
