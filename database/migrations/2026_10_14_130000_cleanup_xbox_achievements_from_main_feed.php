<?php

use App\Models\Post;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Limpa posts de jogos gerados automaticamente que sejam apenas conquistas/progresso no Xbox,
        // mantendo apenas jogos concluídos/platinados ('mastered' ou 'completed') ou com review explícita do usuário.
        Post::where('external_source', 'xbox')
            ->where(function ($q) {
                $q->whereNull('metadata->game_status')
                    ->orWhereNotIn('metadata->game_status', ['completed', 'mastered', 'dropped']);
            })
            ->whereNull('content')
            ->delete();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Irreversível por design (limpeza de feed de conquistas incrementais)
    }
};
