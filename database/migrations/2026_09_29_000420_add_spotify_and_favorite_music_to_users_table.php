<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('spotify_id')->nullable()->after('spotify');
            $table->text('spotify_access_token')->nullable()->after('spotify_id');
            $table->text('spotify_refresh_token')->nullable()->after('spotify_access_token');
            $table->timestamp('spotify_token_expires_at')->nullable()->after('spotify_refresh_token');
            $table->string('spotify_avatar_url')->nullable()->after('spotify_token_expires_at');
            $table->string('spotify_profile_url')->nullable()->after('spotify_avatar_url');
            $table->string('spotify_display_name')->nullable()->after('spotify_profile_url');
            $table->json('favorite_music')->nullable()->after('spotify_display_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'spotify_id',
                'spotify_access_token',
                'spotify_refresh_token',
                'spotify_token_expires_at',
                'spotify_avatar_url',
                'spotify_profile_url',
                'spotify_display_name',
                'favorite_music',
            ]);
        });
    }
};
