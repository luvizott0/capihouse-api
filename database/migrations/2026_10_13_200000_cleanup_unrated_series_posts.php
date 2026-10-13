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
        // Limpa posts de séries que sejam apenas episódios assistidos sem nota,
        // garantindo que apenas atualizações relevantes (avaliações com nota, comentários e séries completadas) permaneçam no feed.
        Post::where(function ($q) {
            $q->where('external_source', 'capyshowtime')
                ->orWhere(function ($sub) {
                    $sub->where('category', 'entertainment')
                        ->where('entertainment_type', 'series');
                });
        })
            ->where(function ($q) {
                // Remove se o tipo for 'watched' (apenas assistido sem nota)
                $q->where('metadata->type', 'watched')
                    // Ou se for 'rating' mas não tiver nota válida (> 0)
                    ->orWhere(function ($sub) {
                        $sub->where('metadata->type', 'rating')
                            ->where(function ($r) {
                                $r->whereNull('metadata->rating')
                                    ->orWhere('metadata->rating', '<=', 0);
                            });
                    })
                    // Ou se o tipo for nulo e não tiver nota válida
                    ->orWhere(function ($sub) {
                        $sub->whereNull('metadata->type')
                            ->where(function ($r) {
                                $r->whereNull('metadata->rating')
                                    ->orWhere('metadata->rating', '<=', 0);
                            });
                    })
                    // Ou se não for nenhum dos tipos relevantes permitidos
                    ->orWhereNotIn('metadata->type', ['rating', 'comment', 'completed']);
            })
            ->delete();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Limpeza irreversível e sem necessidade de rollback
    }
};
