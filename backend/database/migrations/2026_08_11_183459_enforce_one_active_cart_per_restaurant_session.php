<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->dropUnique('carts_session_id_unique');
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX carts_restaurant_session_active_unique
            ON carts (restaurant_id, session_id)
            WHERE status = 'active'
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX carts_restaurant_session_active_unique');

        Schema::table('carts', function (Blueprint $table) {
            $table->unique('session_id', 'carts_session_id_unique');
        });
    }
};
