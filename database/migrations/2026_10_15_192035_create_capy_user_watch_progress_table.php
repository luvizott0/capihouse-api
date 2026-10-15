<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('capy_user_watch_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('media_id')->index(); // id ou slug do vídeo/série (ex: avatar-aang, caverna-do-dragao)
            $table->integer('season_index')->default(0);
            $table->integer('episode_index')->default(0);
            $table->integer('time')->default(0); // em segundos
            $table->integer('duration')->default(0); // em segundos
            $table->boolean('completed')->default(false);
            $table->timestamp('last_watched_at')->useCurrent();
            $table->timestamps();

            $table->unique(['user_id', 'media_id', 'season_index', 'episode_index'], 'user_media_ep_unique');
            $table->index(['user_id', 'last_watched_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capy_user_watch_progress');
    }
};
