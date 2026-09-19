<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monthly_recaps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('post_id')->nullable()->constrained()->nullOnDelete();
            $table->string('year_month', 7); // YYYY-MM
            $table->unsignedInteger('total_feelings')->default(0);
            $table->text('emoji_summary')->nullable();
            $table->json('top_emojis')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'year_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_recaps');
    }
};
