<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->text('payment_checkout_url')->nullable()->after('fulfillment_snapshot');
            $table->jsonb('payment_snapshot')->nullable()->after('payment_checkout_url');
            $table->timestamp('payment_received_at')->nullable()->after('payment_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'payment_checkout_url',
                'payment_snapshot',
                'payment_received_at',
            ]);
        });
    }
};
