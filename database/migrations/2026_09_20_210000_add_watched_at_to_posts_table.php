<?php

use App\Models\Post;
use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->timestamp('watched_at')->nullable()->index()->after('category');
        });

        // Backfill existing entertainment posts with their watched date from metadata
        Post::where('category', 'entertainment')->chunkById(100, function ($posts) {
            foreach ($posts as $post) {
                $metadata = $post->metadata;
                $watchedDate = is_array($metadata) ? ($metadata['watched_date'] ?? null) : null;
                $watchedAt = $watchedDate ? Carbon::parse($watchedDate)->startOfDay() : $post->created_at;

                $post->updateQuietly([
                    'watched_at' => $watchedAt,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('watched_at');
        });
    }
};
