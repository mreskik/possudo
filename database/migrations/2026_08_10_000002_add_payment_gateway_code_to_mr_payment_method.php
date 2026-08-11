<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// mr_payment_method.payment_gateway_code -- kode referensi ke payment gateway pihak ketiga
// (misal Midtrans/Xendit), nullable karena banyak payment method (cash, dll) gak ada gateway
// sama sekali. Cermin dari master_payment_method.payment_gateway_code di sudocore2 (migration
// 078_alter_table_master_payment_method_add_payment_gateway_code.sql), ke-sync otomatis lewat
// SetupServices::getMasterPaymentMethod() -- gak perlu perubahan kode sync, kolomnya tinggal
// ada di sini biar upsert-nya nemu target.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE mr_payment_method ADD COLUMN payment_gateway_code VARCHAR(255) NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE mr_payment_method DROP COLUMN payment_gateway_code');
    }
};
