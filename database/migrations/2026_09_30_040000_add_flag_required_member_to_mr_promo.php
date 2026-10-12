<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// mr_promo dapat kolom baru flag_required_member -- barrier UTAMA "promo ini cuma buat member
// yang login/dipilih di kasir", BEDA dari flag_all_type_members (soal TIPE member, bukan
// ADA/GAK ADA member). Selaras sama master_promo.flag_required_member ERP (migration sudocore2
// 234, 2026-09-30) -- WAJIB disinkronkan lewat get_promo_list sync pull (SetupServices.php),
// gak boleh ketinggalan di sisi ini kayak kasus lama shift_number.
//
// Default false (backward compatible -- promo existing tetap bisa dipakai tanpa pilih member
// dulu, sama kelakuan lama). Ditegakkan di OrderServices::SaveOrder() -- order yang pakai promo
// flag_required_member=true tapi member_id kosong DITOLAK.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mr_promo', function (Blueprint $table) {
            $table->boolean('flag_required_member')->default(false)->after('flag_all_type_members');
        });
    }

    public function down(): void
    {
        Schema::table('mr_promo', function (Blueprint $table) {
            $table->dropColumn('flag_required_member');
        });
    }
};
