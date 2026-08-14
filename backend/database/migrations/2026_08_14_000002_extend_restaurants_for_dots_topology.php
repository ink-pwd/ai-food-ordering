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
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->foreignId('city_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->text('image_url')->nullable()->after('is_active');
            $table->json('available_payment_types')->nullable()->after('image_url');
            $table->json('available_delivery_types')->nullable()->after('available_payment_types');
            $table->json('schedule')->nullable()->after('available_delivery_types');
            $table->string('delivery_time_text')->nullable()->after('schedule');
            $table->string('delivery_price_text')->nullable()->after('delivery_time_text');
            $table->unique(['city_id', 'slug']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropUnique(['city_id', 'slug']);
            $table->dropConstrainedForeignId('city_id');
            $table->dropColumn([
                'image_url',
                'available_payment_types',
                'available_delivery_types',
                'schedule',
                'delivery_time_text',
                'delivery_price_text',
            ]);
            $table->unique('slug');
        });
    }
};
