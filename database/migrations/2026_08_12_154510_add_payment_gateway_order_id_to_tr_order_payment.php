<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tr_order_payment', function (Blueprint $table) {
            // nullable -- cuma keisi buat pembayaran yang lewat payment gateway (QRIS dkk),
            // NULL buat pembayaran cash/card biasa dari POS. Nyambungin balik 1 baris
            // tr_order_payment ke attempt spesifik mana yang beneran kebayar (bisa ada
            // beberapa order_id per order_number gara-gara retry, lihat tr_kiosk_payment_request).
            $table->string('payment_gateway_order_id')->nullable()->after('payment_method_id');
        });
    }

    public function down(): void
    {
        Schema::table('tr_order_payment', function (Blueprint $table) {
            $table->dropColumn('payment_gateway_order_id');
        });
    }
};
