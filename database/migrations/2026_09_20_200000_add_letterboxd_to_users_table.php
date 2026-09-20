<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('letterboxd_username')->nullable()->after('spotify');
            $table->timestamp('letterboxd_last_synced_at')->nullable()->after('letterboxd_username');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['letterboxd_username', 'letterboxd_last_synced_at']);
        });
    }
};
