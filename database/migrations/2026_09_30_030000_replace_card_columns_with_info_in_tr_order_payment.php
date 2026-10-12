<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// tr_order_payment: 4 kolom terpisah (card_number/bank_name/account_name/verification_code)
// DIGANTI 1 kolom info (text) -- request 2026-09-30. Kolom lama SENGAJA DIDROP tanpa migrasi
// data existing (data histori boleh hilang, keputusan eksplisit user). Sesuaikan bareng
// migration serupa di sudocore2 (pos_order_payment) -- 2 sisi WAJIB selaras karena
// pos_order_payment ERP diisi lewat sync push dari tabel ini (lihat
// PushDataServices::pushDataOrderPayment(), generic ambil semua kolom model apa adanya --
// kalau kolomnya beda nama/gak ada, data ke-drop diam-diam pas insert ke ERP).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tr_order_payment', function (Blueprint $table) {
            $table->dropColumn(['card_number', 'bank_name', 'account_name', 'verification_code']);
            $table->text('info')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tr_order_payment', function (Blueprint $table) {
            $table->dropColumn('info');
            $table->string('card_number')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('account_name')->nullable();
            $table->string('verification_code')->nullable();
        });
    }
};
