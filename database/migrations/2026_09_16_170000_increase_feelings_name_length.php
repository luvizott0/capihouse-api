<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feelings', function (Blueprint $table) {
            $table->string('name', 50)->change();
        });
    }

    public function down(): void
    {
        Schema::table('feelings', function (Blueprint $table) {
            $table->string('name', 10)->change();
        });
    }
};
