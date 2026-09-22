<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('xbox_gamertag')->nullable()->after('letterboxd_last_synced_at');
            $table->string('xbox_xuid')->nullable()->after('xbox_gamertag');
            $table->timestamp('xbox_last_synced_at')->nullable()->after('xbox_xuid');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['xbox_gamertag', 'xbox_xuid', 'xbox_last_synced_at']);
        });
    }
};
