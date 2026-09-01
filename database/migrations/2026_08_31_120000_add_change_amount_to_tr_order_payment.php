<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// change_amount: kembalian tunai (uang diterima - payment_amount), SELALU 0 buat method non-cash.
// payment_amount SENGAJA tetap "bersih" (cuma nominal yang keaplikasi ke tagihan, dibatasin ke
// outstanding) -- kembalian dipisah ke kolom sendiri, bukan digabung ke payment_amount, biar
// laporan/rekonsiliasi cash drawer bisa bedain "uang yang diterima kasir" vs "yang keitung bayar
// tagihan". Disepakati 2026-08-31, kolom yang sama juga ditambahin ke pos_order_payment di ERP
// (sudocore2) -- lihat cmd/migration/ di situ -- biar gak ke-drop diam-diam pas sync:push.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tr_order_payment', function (Blueprint $table) {
            $table->decimal('change_amount', 20, 2)->default(0)->after('payment_amount');
        });
    }

    public function down(): void
    {
        Schema::table('tr_order_payment', function (Blueprint $table) {
            $table->dropColumn('change_amount');
        });
    }
};
