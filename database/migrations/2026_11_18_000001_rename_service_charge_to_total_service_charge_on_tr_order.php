<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// tr_order.service_charge -> total_service_charge (2026-11-18) -- rename murni, TIDAK ada
// perubahan tipe/default (tetap decimal(20,2) NOT NULL DEFAULT 0.00). Nama lama ambigu (bisa
// disalahartikan sebagai tax_id service charge, padahal ini TOTAL AMOUNT level header order,
// sejajar sub_total/total_discount/total_tax/total_billing) -- rename biar konsisten penamaan
// "total_*" dengan kolom sejenis lainnya. Field ini SEBELUMNYA belum pernah dibaca/ditulis
// sama sekali di OrderServices::SaveOrder() (selalu default 0.00) -- field baru totalServiceCharge
// di request body mulai mengisinya sekarang (lihat SAVE ORDER.md).
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE tr_order CHANGE service_charge total_service_charge DECIMAL(20,2) NOT NULL DEFAULT 0.00');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tr_order CHANGE total_service_charge service_charge DECIMAL(20,2) NOT NULL DEFAULT 0.00');
    }
};
