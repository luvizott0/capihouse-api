<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('capy_user_games', function (Blueprint $table) {
            if (! Schema::hasColumn('capy_user_games', 'aspect_ratings')) {
                $table->json('aspect_ratings')->nullable()->after('rating');
            }
        });
    }

    public function down(): void
    {
        Schema::table('capy_user_games', function (Blueprint $table) {
            if (Schema::hasColumn('capy_user_games', 'aspect_ratings')) {
                $table->dropColumn('aspect_ratings');
            }
        });
    }
};
