<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// mr_payment_method dapat kolom `is_active` -- 1:1 dengan master_payment_method.is_active di ERP.
// Sebelumnya APIANDORDER hanya mengirim metode yang aktif dan POS memakai upsert (tidak pernah menghapus),
// jadi metode yang dinonaktifkan di ERP tetap hidup di POS. Sekarang ERP mengirim SEMUA metode cabang beserta
// is_active-nya; baris TIDAK dihapus (riwayat transaksi meng-INNER JOIN mr_payment_method), list payment method
// yang memfilter is_active = true. Default true supaya baris lama tetap aktif sampai sync berikutnya.
// WAJIB di-migrate di POS SEBELUM APIANDORDER mulai mengirim field `is_active` (model memakai $guarded = []).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mr_payment_method', function (Blueprint $table) {
            $table->boolean('is_active')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('mr_payment_method', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
