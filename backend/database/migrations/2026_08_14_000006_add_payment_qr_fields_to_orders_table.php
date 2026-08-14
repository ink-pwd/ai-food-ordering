<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_qr_path')->nullable()->after('payment_received_at');
            $table->string('payment_qr_fingerprint')->nullable()->after('payment_qr_path');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'payment_qr_path',
                'payment_qr_fingerprint',
            ]);
        });
    }
};
