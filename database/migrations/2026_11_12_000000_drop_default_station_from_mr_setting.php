<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// mr_setting.default_station dihapus (2026-11-12) -- printer struk order/bill (PrintPayment/
// PrintBill) dan struk laporan shift/endday (PrintEndDay/PrintReportDaysift/PrintCurrentShift/
// PrintPerShiftv2) sekarang resolve dari SESSION KASIR yang sedang login (mr_session.data.
// terminal_id -> mr_terminal.receipt_station_id, lihat AuthController::login() &
// PrintServices::RetrieveReceiptStationIdFromSession()), bukan lagi 1 station global buat
// semua terminal. Semua caller sudah dipastikan tidak ada yang masih baca default_station
// sebelum migration ini dijalankan.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mr_setting', function (Blueprint $table) {
            $table->dropColumn('default_station');
        });
    }

    public function down(): void
    {
        Schema::table('mr_setting', function (Blueprint $table) {
            $table->unsignedBigInteger('default_station')->default(0)->after('id');
        });
    }
};
