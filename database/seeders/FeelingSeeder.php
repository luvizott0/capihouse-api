<?php

namespace Database\Seeders;

use App\Models\Post;
use Illuminate\Database\Seeder;

class FeelingSeeder extends Seeder
{
    public function run(): void
    {
        // Common feelings without post context for lookup if needed later,
        // but feelings currently require a post_id based on schema.
        // We will leave this seeder empty or seeded with a dummy post for demonstration.
    }
}
