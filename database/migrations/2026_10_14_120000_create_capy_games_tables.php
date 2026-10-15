<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Catálogo e Cache de Jogos
        Schema::create('capy_games', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->index();
            $table->string('external_source')->default('manual')->index(); // 'steam', 'xbox', 'manual', 'custom'
            $table->string('external_id')->index(); // ex: 'steam:400', 'xbox:title_id'
            $table->text('cover_url')->nullable();
            $table->text('banner_url')->nullable();
            $table->text('summary')->nullable();
            $table->string('developer')->nullable();
            $table->string('publisher')->nullable();
            $table->string('release_date')->nullable();
            $table->json('platforms')->nullable();
            $table->json('genres')->nullable();
            $table->json('raw_data')->nullable();
            $table->timestamps();

            $table->unique(['external_source', 'external_id']);
        });

        // 2. Biblioteca de Jogos do Usuário
        Schema::create('capy_user_games', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('game_id')->constrained('capy_games')->cascadeOnDelete();
            // plan_to_play, playing, completed, mastered, dropped, on_hold
            $table->string('status')->default('plan_to_play')->index();
            $table->string('platform')->nullable();
            $table->decimal('hours_played', 8, 1)->default(0);
            $table->decimal('rating', 3, 1)->nullable(); // Nota de 1.0 a 10.0
            $table->text('review')->nullable();
            $table->integer('achievements_earned')->default(0);
            $table->integer('achievements_total')->default(0);
            $table->integer('gamerscore_earned')->default(0);
            $table->integer('gamerscore_total')->default(0);
            $table->string('source')->default('manual'); // 'manual', 'xbox', 'steam'
            $table->boolean('is_favorite')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'game_id']);
            $table->index(['user_id', 'status']);
        });

        // 3. Atividades e Feed Comunitário do CapyGames
        Schema::create('capy_game_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('game_id')->constrained('capy_games')->cascadeOnDelete();
            // 'achievement', 'status_change', 'review', 'rating'
            $table->string('type')->index();
            $table->string('title');
            $table->text('content')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['created_at']);
            $table->index(['user_id', 'created_at']);
        });

        // 4. Campos de Steam na tabela de usuários
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'steam_id')) {
                $table->string('steam_id')->nullable()->after('xbox_last_synced_at');
            }
            if (! Schema::hasColumn('users', 'steam_username')) {
                $table->string('steam_username')->nullable()->after('steam_id');
            }
            if (! Schema::hasColumn('users', 'steam_last_synced_at')) {
                $table->timestamp('steam_last_synced_at')->nullable()->after('steam_username');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capy_game_activities');
        Schema::dropIfExists('capy_user_games');
        Schema::dropIfExists('capy_games');

        Schema::table('users', function (Blueprint $table) {
            $columns = [];
            if (Schema::hasColumn('users', 'steam_id')) {
                $columns[] = 'steam_id';
            }
            if (Schema::hasColumn('users', 'steam_username')) {
                $columns[] = 'steam_username';
            }
            if (Schema::hasColumn('users', 'steam_last_synced_at')) {
                $columns[] = 'steam_last_synced_at';
            }
            if (! empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
