<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// tr_order.platform_fee DIHAPUS (2026-11-18) -- keputusan user: kolom ini gak pernah diisi
// OrderServices::SaveOrder() (order dari kasir POS, selalu default 0.00) dan dianggap gak
// relevan lagi buat sistem ini. SEBELUM dihapus kolomnya masih dipakai aktif: baris laporan
// "Netsales Platform Fee" (DayShiftServices.php, 6 blok laporan -- GetReportDayshiftorEndDay(),
// GetReportCurrentShift(), GetReportPerShift(), dst) ikut dijumlahkan ke gross_sales, dan
// MobileOrderPullServices.php menulisnya dari payload order mobile/member app (sudomobile) --
// SEMUA konsumen itu ikut dihapus bareng migration ini (lihat kode masing-masing file).
// Efek nyata: baris "Netsales Platform Fee" hilang dari laporan, rumus gross_sales gak lagi
// menjumlahkan komponen ini -- order mobile yang platform_fee-nya > 0 (kalau ada) nilainya
// TIDAK LAGI masuk ke gross_sales setelah perubahan ini (disetujui user, bukan oversight).
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE tr_order DROP COLUMN platform_fee');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tr_order ADD COLUMN platform_fee DECIMAL(20,2) NOT NULL DEFAULT 0.00 AFTER total_service_charge');
    }
};
