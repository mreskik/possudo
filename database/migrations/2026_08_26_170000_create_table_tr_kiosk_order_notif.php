<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// tr_kiosk_order_notif: penanda "order kiosk ini perlu di-confirm staff di POS", TERPISAH dari
// tr_order (sengaja, biar tr_order gak nambah kolom yang cuma buat kebutuhan UI toast). Pola
// SAMA kayak mb_order (flag_confirm, murni acknowledgment UI, gak ngaruh proses order) tapi buat
// sumber KIOSK -- mb_order tetap KHUSUS mobile (dia juga nyimpen status buat kebutuhan pull,
// beda tujuan). Baris di sini di-insert PaymentServices::SavePayment() pas order kiosk BERHASIL
// dibayar (bukan pas SaveOrder()/dibuat) -- order kiosk yang dibuat tapi gak jadi dibayar gak
// boleh ikut nongol jadi notif, sama semangatnya kayak mobile order yang cuma nongol pas
// status='paid'. Disepakati 2026-08-26.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tr_kiosk_order_notif', function (Blueprint $table) {
            $table->string('order_number')->primary();
            $table->boolean('flag_confirm')->default(false);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tr_kiosk_order_notif');
    }
};
