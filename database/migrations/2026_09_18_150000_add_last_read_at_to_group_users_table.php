<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_users', function (Blueprint $table) {
            $table->timestamp('last_read_at')->nullable()->after('status');
        });

        // Initialize last_read_at for existing members with current timestamp
        DB::table('group_users')->whereNull('last_read_at')->update([
            'last_read_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('group_users', function (Blueprint $table) {
            $table->dropColumn('last_read_at');
        });
    }
};
