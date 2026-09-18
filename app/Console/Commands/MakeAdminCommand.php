<?php

namespace App\Console\Commands;

use App\Enums\UserRoles;
use App\Models\User;
use Illuminate\Console\Command;

class MakeAdminCommand extends Command
{
    protected $signature = 'make:admin {email}';

    protected $description = 'Promote a user to Admin role by their email address';

    public function handle()
    {
        $email = $this->argument('email');

        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("User with email {$email} not found.");

            return 1;
        }

        $user->update(['role' => UserRoles::Admin]);

        $this->info("User {$user->name} has been promoted to Admin.");

        return 0;
    }
}
