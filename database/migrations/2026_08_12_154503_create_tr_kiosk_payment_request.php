<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tr_kiosk_payment_request', function (Blueprint $table) {
            // order_id yang beneran dikirim ke service payment -- order_number di attempt
            // pertama, order_number-2/-3/dst di retry (lihat PaymentGatewayServices::RequestPayment()).
            $table->string('order_id')->primary();
            $table->string('order_number');
            $table->unsignedBigInteger('payment_method_id');
            $table->decimal('amount', 20, 2);
            $table->string('status')->default('pending'); // pending, settlement, cancel, failed, expired
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->index('order_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tr_kiosk_payment_request');
    }
};
