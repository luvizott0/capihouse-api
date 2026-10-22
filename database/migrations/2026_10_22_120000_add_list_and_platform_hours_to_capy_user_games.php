<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('capy_user_games', function (Blueprint $table) {
            if (! Schema::hasColumn('capy_user_games', 'in_list')) {
                $table->boolean('in_list')->default(false)->index()->after('status');
            }
            if (! Schema::hasColumn('capy_user_games', 'list_order')) {
                $table->integer('list_order')->default(0)->index()->after('in_list');
            }
            if (! Schema::hasColumn('capy_user_games', 'platform_hours')) {
                $table->json('platform_hours')->nullable()->after('hours_played');
            }
        });
    }

    public function down(): void
    {
        Schema::table('capy_user_games', function (Blueprint $table) {
            $columns = [];
            if (Schema::hasColumn('capy_user_games', 'in_list')) {
                $columns[] = 'in_list';
            }
            if (Schema::hasColumn('capy_user_games', 'list_order')) {
                $columns[] = 'list_order';
            }
            if (Schema::hasColumn('capy_user_games', 'platform_hours')) {
                $columns[] = 'platform_hours';
            }
            if (! empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
