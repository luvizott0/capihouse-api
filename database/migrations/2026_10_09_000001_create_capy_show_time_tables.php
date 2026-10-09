<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Catálogo de Séries (Cache local do TMDB)
        Schema::create('capy_series', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tmdb_id')->unique()->index();
            $table->string('name');
            $table->string('original_name')->nullable();
            $table->text('overview')->nullable();
            $table->string('poster_path')->nullable();
            $table->string('backdrop_path')->nullable();
            $table->date('first_air_date')->nullable();
            $table->string('status')->nullable(); // Returning Series, Ended, etc.
            $table->decimal('vote_average', 3, 1)->default(0);
            $table->integer('number_of_seasons')->default(0);
            $table->integer('number_of_episodes')->default(0);
            $table->json('genres')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        // 2. Temporadas da Série
        Schema::create('capy_seasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('series_id')->constrained('capy_series')->cascadeOnDelete();
            $table->unsignedBigInteger('tmdb_id')->nullable();
            $table->integer('season_number');
            $table->string('name')->nullable();
            $table->text('overview')->nullable();
            $table->string('poster_path')->nullable();
            $table->date('air_date')->nullable();
            $table->integer('episode_count')->default(0);
            $table->timestamps();

            $table->unique(['series_id', 'season_number']);
        });

        // 3. Episódios
        Schema::create('capy_episodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('series_id')->constrained('capy_series')->cascadeOnDelete();
            $table->foreignId('season_id')->constrained('capy_seasons')->cascadeOnDelete();
            $table->unsignedBigInteger('tmdb_id')->nullable();
            $table->integer('season_number');
            $table->integer('episode_number');
            $table->string('name');
            $table->text('overview')->nullable();
            $table->string('still_path')->nullable();
            $table->date('air_date')->nullable();
            $table->integer('runtime')->nullable(); // minutos
            $table->timestamps();

            $table->unique(['series_id', 'season_number', 'episode_number']);
        });

        // 4. Lista do Usuário (Status da série para o usuário)
        Schema::create('capy_user_series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('series_id')->constrained('capy_series')->cascadeOnDelete();
            // plan_to_watch (Quero assistir futuramente), watching, completed, on_hold, dropped
            $table->string('status')->default('plan_to_watch')->index();
            $table->timestamps();

            $table->unique(['user_id', 'series_id']);
        });

        // 5. Progresso de Episódios e Notas (1 a 10)
        Schema::create('capy_user_episodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('series_id')->constrained('capy_series')->cascadeOnDelete();
            $table->foreignId('season_id')->constrained('capy_seasons')->cascadeOnDelete();
            $table->foreignId('episode_id')->constrained('capy_episodes')->cascadeOnDelete();
            $table->boolean('is_watched')->default(true);
            $table->timestamp('watched_at')->nullable();
            $table->decimal('rating', 3, 1)->nullable(); // Nota de 1.0 a 10.0
            $table->timestamps();

            $table->unique(['user_id', 'episode_id']);
            $table->index(['user_id', 'season_id']);
            $table->index(['user_id', 'series_id']);
        });

        // 6. Comentários por Episódio
        Schema::create('capy_episode_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('episode_id')->constrained('capy_episodes')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('content');
            $table->boolean('is_spoiler')->default(false);
            $table->timestamps();

            $table->index(['episode_id', 'created_at']);
        });

        // 7. Likes nos Comentários
        Schema::create('capy_episode_comment_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comment_id')->constrained('capy_episode_comments')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['comment_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capy_episode_comment_likes');
        Schema::dropIfExists('capy_episode_comments');
        Schema::dropIfExists('capy_user_episodes');
        Schema::dropIfExists('capy_user_series');
        Schema::dropIfExists('capy_episodes');
        Schema::dropIfExists('capy_seasons');
        Schema::dropIfExists('capy_series');
    }
};
