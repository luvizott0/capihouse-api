<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Tabela Principal de Memórias / Cápsulas
        Schema::create('capsule_memories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->longText('content')->nullable();

            // Controle cronológico preciso e flexível
            $table->date('occurred_at')->nullable()->index();
            $table->string('occurred_at_precision')->default('exact'); // exact, month, year, approximate
            $table->string('approximate_date')->nullable(); // ex: "Verão de 2022", "Final de 2023"

            // Localização
            $table->string('location_name')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            // Sentimento / Humor
            $table->string('mood')->nullable();
            $table->string('mood_emoji', 32)->nullable();

            // Visibilidade & Tranca de Cápsula
            $table->string('visibility')->default('shared'); // private, shared, public
            $table->boolean('is_favorite')->default(false)->index();
            $table->timestamp('locked_until')->nullable()->index(); // Revelar apenas no futuro

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'occurred_at']);
        });

        // 2. Tags para organização temática
        Schema::create('capsule_tags', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->string('color', 32)->nullable();
            $table->timestamps();
        });

        // 3. Pivô Memórias <-> Tags
        Schema::create('capsule_memory_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('memory_id')->constrained('capsule_memories')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('capsule_tags')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['memory_id', 'tag_id']);
        });

        // 4. Participantes do acontecimento (usuários da Capihouse ou nomes avulsos)
        Schema::create('capsule_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('memory_id')->constrained('capsule_memories')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('role')->nullable(); // ex: "comigo", "autor da foto"
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capsule_participants');
        Schema::dropIfExists('capsule_memory_tag');
        Schema::dropIfExists('capsule_tags');
        Schema::dropIfExists('capsule_memories');
    }
};
