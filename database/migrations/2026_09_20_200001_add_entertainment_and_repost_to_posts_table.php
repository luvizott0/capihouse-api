<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->string('category')->default('feed')->index()->after('event_id');
            $table->string('entertainment_type')->nullable()->index()->after('category');
            $table->string('external_source')->nullable()->index()->after('entertainment_type');
            $table->string('external_id')->nullable()->index()->after('external_source');
            $table->json('metadata')->nullable()->after('external_id');
            $table->foreignId('repost_of_id')->nullable()->after('metadata')->constrained('posts')->nullOnDelete();

            $table->unique(['user_id', 'external_id'], 'posts_user_external_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropUnique('posts_user_external_id_unique');
            $table->dropForeign(['repost_of_id']);
            $table->dropColumn([
                'category',
                'entertainment_type',
                'external_source',
                'external_id',
                'metadata',
                'repost_of_id',
            ]);
        });
    }
};
