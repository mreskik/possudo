<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// tr_order dapat kolom baru grand_total -- request 2026-11-20. Lengkapi rantai nilai akhir
// order bareng rounding_amount (sudah ada, migration 2026-09-30) dan total_billing (sudah ada):
//   grand_total     = hasil kalkulasi murni SEBELUM dibulatkan (sub_total - total_discount +
//                      total_tax + total_service_charge + order_fee + delivery_cost, dst)
//   rounding_amount = selisih pembulatan (bisa negatif/positif) = total_billing - grand_total
//   total_billing   = nilai FINAL yang ditagih ke customer, SUDAH dibulatkan (posisi terakhir,
//                      sudah ada sejak awal)
// Scope sesi ini: SIAPIN KOLOM DOANG, default 0, LOGIC PENGISIAN BELUM DIBANGUN (sama status
// rounding_amount -- kolom disiapkan duluan, logic kalkulasi menyusul).
//
// Sesuaikan bareng migration serupa di sudocore2 (pos_order) kalau sudah waktunya -- 2 sisi
// WAJIB selaras karena pos_order ERP diisi lewat sync push dari tabel ini (generic, ambil semua
// kolom model apa adanya -- kalau kolom ketinggalan di salah satu sisi Go/PHP, data ke-drop
// diam-diam, lihat DOKUMENTASI API/SYNC/SYNC PUSH.md).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tr_order', function (Blueprint $table) {
            $table->decimal('grand_total', 20, 2)->default(0)->after('total_tax');
        });
    }

    public function down(): void
    {
        Schema::table('tr_order', function (Blueprint $table) {
            $table->dropColumn('grand_total');
        });
    }
};
