<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// service_charge_id/service_charge_rate/service_charge_amount -- ditambahkan ke
// tr_order_detail & tr_order_detail_package (2026-11-18), SAMA PERSIS pola kolom
// tax_id/tax_rate/tax_amount yang udah ada (bigint nullable, decimal(10,2) default 0,
// decimal(20,2) default 0). Sumber: MenuServices::GetMasterMenuList() sudah resolve
// serviceChargeId/serviceChargeRate per item/sub-item (lihat DOKUMENTASI API/POS/POS/MASTER/
// MENU LIST.md) -- field ini sekarang perlu disimpan juga pas SaveOrder(), bukan cuma
// dibaca dari menu list. service_charge_amount TIDAK ADA di menu list (baru dihitung pas
// order disimpan, sama kayak tax_amount) -- dihitung FE, dikirim di body SaveOrder() per
// baris listOrder[]/menuPackageList[], server simpan apa adanya (konsisten arsitektur
// "semua kalkulasi di frontend" yang sudah ada buat tax/discount, lihat SAVE ORDER.md).
// Scope SENGAJA cuma POS lokal (migration + SaveOrder()) -- sync push ke sudocore2 &
// konsumsi jurnal endday belum disentuh, menyusul terpisah.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE tr_order_detail ADD COLUMN service_charge_id BIGINT NULL AFTER tax_type');
        DB::statement('ALTER TABLE tr_order_detail ADD COLUMN service_charge_rate DECIMAL(10,2) NULL DEFAULT 0 AFTER service_charge_id');
        DB::statement('ALTER TABLE tr_order_detail ADD COLUMN service_charge_amount DECIMAL(20,2) NULL DEFAULT 0 AFTER service_charge_rate');

        DB::statement('ALTER TABLE tr_order_detail_package ADD COLUMN service_charge_id BIGINT NULL AFTER tax_type');
        DB::statement('ALTER TABLE tr_order_detail_package ADD COLUMN service_charge_rate DECIMAL(10,2) NULL DEFAULT 0 AFTER service_charge_id');
        DB::statement('ALTER TABLE tr_order_detail_package ADD COLUMN service_charge_amount DECIMAL(20,2) NULL DEFAULT 0 AFTER service_charge_rate');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tr_order_detail DROP COLUMN service_charge_id');
        DB::statement('ALTER TABLE tr_order_detail DROP COLUMN service_charge_rate');
        DB::statement('ALTER TABLE tr_order_detail DROP COLUMN service_charge_amount');

        DB::statement('ALTER TABLE tr_order_detail_package DROP COLUMN service_charge_id');
        DB::statement('ALTER TABLE tr_order_detail_package DROP COLUMN service_charge_rate');
        DB::statement('ALTER TABLE tr_order_detail_package DROP COLUMN service_charge_amount');
    }
};
