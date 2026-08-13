<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tr_kiosk_payment_request', function (Blueprint $table) {
            // dari vendor_qr punya waktu expired_at (Midtrans) -- disnapshot di sini pas
            // PaymentGatewayServices::RequestPayment() sukses, dipakai buat nampilin
            // payment_expired_at di KIOSK ORDER HISTORY.md / KIOSK ORDER DETAIL.md.
            $table->timestamp('expired_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('tr_kiosk_payment_request', function (Blueprint $table) {
            $table->dropColumn('expired_at');
        });
    }
};
