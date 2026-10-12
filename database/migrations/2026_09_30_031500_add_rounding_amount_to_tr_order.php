<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// tr_order dapat kolom baru rounding_amount -- request 2026-09-30. Scope sesi ini: SIAPIN
// KOLOM DOANG, default 0, LOGIC PENERAPAN ROUNDING BELUM DIBANGUN (belum ada kode yang baca
// setting rounding cabang buat ngitung nilai kolom ini -- itu udah lama ada sebagai SETTING
// di ERP tapi belum pernah dipakai di transaksi manapun di POS). Nanti dimaksudkan nyimpen
// SELISIH hasil pembulatan (bisa negatif/positif), bukan nilai final setelah dibulatkan.
//
// Sesuaikan bareng migration serupa di sudocore2 (pos_order, migration 230) -- 2 sisi WAJIB
// selaras karena pos_order ERP diisi lewat sync push dari tabel ini (generic, ambil semua
// kolom model apa adanya -- kalau kolom ketinggalan di salah satu sisi Go/PHP, data ke-drop
// diam-diam, sama kasus lama chasier_name/customer_phone_number, lihat DOKUMENTASI API/SYNC/
// SYNC PUSH.md).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tr_order', function (Blueprint $table) {
            $table->decimal('rounding_amount', 20, 2)->default(0)->after('total_billing');
        });
    }

    public function down(): void
    {
        Schema::table('tr_order', function (Blueprint $table) {
            $table->dropColumn('rounding_amount');
        });
    }
};
