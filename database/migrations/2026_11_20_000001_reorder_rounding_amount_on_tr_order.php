<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Reorder kolom fisik tr_order biar urutannya sesuai urutan LOGIKA nilai akhir order
// (request 2026-11-20): grand_total -> rounding_amount -> total_billing.
//   grand_total     = hasil kalkulasi murni SEBELUM dibulatkan
//   rounding_amount = selisih pembulatan (bisa negatif/positif)
//   total_billing   = nilai FINAL yang ditagih, SUDAH dibulatkan
// rounding_amount sebelumnya nempel AFTER total_billing (migration 2026-09-30, dibuat sebelum
// grand_total ada) -- sekarang dipindah ke antara grand_total dan total_billing. Reposisi kolom
// MySQL murni kosmetik/urutan fisik, tidak mengubah tipe/nilai data, jadi aman dijalankan kapan
// saja tanpa downtime berarti.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE tr_order MODIFY COLUMN rounding_amount DECIMAL(20,2) NOT NULL DEFAULT 0 AFTER grand_total');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tr_order MODIFY COLUMN rounding_amount DECIMAL(20,2) NOT NULL DEFAULT 0 AFTER total_billing');
    }
};
