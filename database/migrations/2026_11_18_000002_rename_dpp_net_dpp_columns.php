<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Rename dpp/net_dpp (2026-11-18) -- nama lama MEMBINGUNGKAN: kolom "dpp" sebenarnya net price
// (SEBELUM diskon, hasil formula flag_inclusive_tax ? price/(1+rate/100) : price -- lihat
// OrderServices::RecalculateOrderTotals()), sementara "net_dpp" (dpp SETELAH dipotong diskon)
// itu yang SEBENARNYA jadi basis pengenaan pajak -- istilah akuntansi "DPP" (Dasar Pengenaan
// Pajak) semestinya merujuk ke nilai INI, bukan yang pertama. Rename TUKAR NAMA (swap):
//   dpp     -> net_price  (nilai SEBELUM diskon, net-of-tax)
//   net_dpp -> dpp        (nilai SETELAH diskon, basis pajak -- makna "DPP" yang benar)
// Swap 2 kolom butuh kolom perantara sementara (dpp_rename_tmp) biar gak collision nama pas
// rename berjalan. Scope: tr_order_detail + tr_order_detail_package (kolom yang sama persis
// di kedua tabel).
//
// SCOPE SESI INI SENGAJA DIBATASI: cuma migration kolom DB + OrderServices::SaveOrder()/
// ViewOrder() (minimal fix biar query gak crash) yang disesuaikan. Konsumen lain kolom ini
// (KioskController.php, DayShiftServices.php, MobileOrderPullServices.php, PrintServices.php)
// BELUM disentuh -- PENDING, menyusul terpisah (dampaknya lebih besar, termasuk payload dari
// sudomobile/mb_order yang kemungkinan masih pakai makna lama). KioskController.php &
// DayShiftServices.php aman sementara karena pakai `tro.*`/`trod.*` (SELECT semua kolom, gak
// crash), bukan alias eksplisit nama kolom.
return new class extends Migration
{
    public function up(): void
    {
        foreach (['tr_order_detail', 'tr_order_detail_package'] as $table) {
            DB::statement("ALTER TABLE {$table} CHANGE net_dpp dpp_rename_tmp DECIMAL(20,2) NULL DEFAULT 0.00");
            DB::statement("ALTER TABLE {$table} CHANGE dpp net_price DECIMAL(20,2) NULL DEFAULT 0.00");
            DB::statement("ALTER TABLE {$table} CHANGE dpp_rename_tmp dpp DECIMAL(20,2) NULL DEFAULT 0.00");
        }
    }

    public function down(): void
    {
        foreach (['tr_order_detail', 'tr_order_detail_package'] as $table) {
            DB::statement("ALTER TABLE {$table} CHANGE dpp dpp_rename_tmp DECIMAL(20,2) NULL DEFAULT 0.00");
            DB::statement("ALTER TABLE {$table} CHANGE net_price dpp DECIMAL(20,2) NULL DEFAULT 0.00");
            DB::statement("ALTER TABLE {$table} CHANGE dpp_rename_tmp net_dpp DECIMAL(20,2) NULL DEFAULT 0.00");
        }
    }
};
