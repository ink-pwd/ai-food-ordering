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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();

            $table->foreignId('restaurant_id')
                ->constrained()
                ->restrictOnDelete();

            $table->foreignId('cart_id')
                ->unique()
                ->constrained()
                ->restrictOnDelete();

            $table->string('session_id', 26)->index();

            $table->string('idempotency_key', 128)->unique();

            $table->string('external_order_id')->nullable();

            $table->string('channel');
            $table->string('status');
            $table->string('receiving_type');

            $table->string('customer_name');
            $table->string('customer_phone');

            $table->decimal('total', 12, 2);
            $table->string('currency', 3);

            $table->jsonb('request_payload')->nullable();
            $table->jsonb('response_payload')->nullable();

            $table->text('failure_message')->nullable();

            $table->timestamps();

            $table->unique([
                'restaurant_id',
                'external_order_id',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
