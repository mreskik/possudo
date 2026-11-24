<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// mr_branch_setting.rounding_mode DIHAPUS (2026-11-24) -- mengikuti ERP (sudocore2 migration 354:
// master_branch_setting.rounding_mode di-DROP). Kolom ini tidak pernah dipakai logic apa pun:
// pembulatan di OrderServices::RecalculateOrderTotals() selalu "ke atas" ke kelipatan
// rounding_nearst_value, yang dipakai tetap flag_rounding + rounding_nearst_value (TIDAK diubah).
//
// Sinkronisasi: SetupServices::getDatabranch() (dipakai wizard setup + SyncGroupController::
// syncBranchSetting()) satu-satunya yang menulis mr_branch_setting, dan sudah tidak memetakan
// 'rounding_mode' -- WAJIB ikut dirilis bareng migration ini, kalau tidak create() akan error
// "Unknown column 'rounding_mode'" begitu kolom lokalnya hilang. APIANDORDER juga sudah tidak
// mengirim key "RoundingMode" (POS versi lama yang belum update tetap aman: nilainya null dan
// kolom lokalnya masih ada).
//
// Migration 2026_11_03_000000_add_columns_to_mr_branch_setting.php TIDAK diubah (riwayat) --
// down() migration ini menambahkan kolom lagi supaya rollback berurutan tetap jalan.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('mr_branch_setting', 'rounding_mode')) {
            Schema::table('mr_branch_setting', function (Blueprint $table) {
                $table->dropColumn('rounding_mode');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('mr_branch_setting', 'rounding_mode')) {
            Schema::table('mr_branch_setting', function (Blueprint $table) {
                // sama persis definisi asli (migration 2026_11_03): string nullable setelah flag_rounding.
                // Nilai lama TIDAK kembali (kolom hanya dibuat ulang kosong).
                $table->string('rounding_mode')->nullable()->after('flag_rounding');
            });
        }
    }
};
