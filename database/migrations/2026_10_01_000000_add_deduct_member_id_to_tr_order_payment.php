<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// tr_order_payment dapat kolom baru deduct_member_id -- SNAPSHOT id member yang saldonya
// kepotong buat baris payment ini (method "Bayar pakai Saldo"/wallet), nullable & tanpa FK
// (histori payment gak boleh ke-block/corrupt kalau member-nya kehapus belakangan). NULL buat
// payment method non-saldo. Mirror 1:1 ke pos_order_payment (sudocore2, migration 240) &
// mb_order_payment (sudocore2 juga, migration 241).
//
// pushDataOrderPayment() (PushDataServices.php) ambil SEMUA kolom model generic (TrOrderPaymentModel::get()),
// jadi kolom ini OTOMATIS ikut ke-push apa adanya -- gak perlu ubah PushDataServices.php. Yang
// WAJIB disesuaikan manual di sisi penerima: APIANDORDER pushdata_model.go (PosOrderPaymentModel,
// field eksplisit, field yang gak dikenal di-drop diam-diam).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tr_order_payment', function (Blueprint $table) {
            $table->unsignedBigInteger('deduct_member_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tr_order_payment', function (Blueprint $table) {
            $table->dropColumn('deduct_member_id');
        });
    }
};
