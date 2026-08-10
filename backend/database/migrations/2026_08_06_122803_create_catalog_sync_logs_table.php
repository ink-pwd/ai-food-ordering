<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('catalog_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->json('summary')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['restaurant_id', 'started_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('catalog_sync_logs');
    }
};
