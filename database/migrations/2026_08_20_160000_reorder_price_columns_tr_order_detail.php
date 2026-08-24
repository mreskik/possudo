<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE tr_order_detail MODIFY COLUMN tax_id BIGINT NULL AFTER flag_inclusive_tax");
        DB::statement("ALTER TABLE tr_order_detail MODIFY COLUMN tax_type VARCHAR(255) NULL AFTER tax_id");
        DB::statement("ALTER TABLE tr_order_detail MODIFY COLUMN dpp DECIMAL(20,2) NULL DEFAULT 0 AFTER price_pos");
        DB::statement("ALTER TABLE tr_order_detail MODIFY COLUMN discount_percent DECIMAL(10,2) NULL DEFAULT 0 AFTER dpp");
        DB::statement("ALTER TABLE tr_order_detail MODIFY COLUMN discount_amount DECIMAL(20,2) NULL DEFAULT 0 AFTER discount_percent");
        DB::statement("ALTER TABLE tr_order_detail MODIFY COLUMN net_dpp DECIMAL(20,2) NULL DEFAULT 0 AFTER discount_amount");
        DB::statement("ALTER TABLE tr_order_detail MODIFY COLUMN tax_rate DECIMAL(10,2) NULL DEFAULT 0 AFTER net_dpp");
        DB::statement("ALTER TABLE tr_order_detail MODIFY COLUMN tax_amount DECIMAL(20,2) NULL DEFAULT 0 AFTER tax_rate");
        DB::statement("ALTER TABLE tr_order_detail MODIFY COLUMN total DECIMAL(20,2) NULL DEFAULT 0 AFTER tax_amount");
        DB::statement("ALTER TABLE tr_order_detail MODIFY COLUMN promo_id BIGINT NULL AFTER total");
        DB::statement("ALTER TABLE tr_order_detail MODIFY COLUMN is_free_item_promo TINYINT(1) NOT NULL DEFAULT 0 AFTER promo_id");

        DB::statement("ALTER TABLE tr_order_detail_package MODIFY COLUMN tax_id BIGINT NULL AFTER flag_inclusive_tax");
        DB::statement("ALTER TABLE tr_order_detail_package MODIFY COLUMN tax_type VARCHAR(255) NULL AFTER tax_id");
        DB::statement("ALTER TABLE tr_order_detail_package MODIFY COLUMN dpp DECIMAL(20,2) NULL DEFAULT 0 AFTER price_pos");
        DB::statement("ALTER TABLE tr_order_detail_package MODIFY COLUMN discount_percent DECIMAL(10,2) NULL DEFAULT 0 AFTER dpp");
        DB::statement("ALTER TABLE tr_order_detail_package MODIFY COLUMN discount_amount DECIMAL(20,2) NULL DEFAULT 0 AFTER discount_percent");
        DB::statement("ALTER TABLE tr_order_detail_package MODIFY COLUMN net_dpp DECIMAL(20,2) NULL DEFAULT 0 AFTER discount_amount");
        DB::statement("ALTER TABLE tr_order_detail_package MODIFY COLUMN tax_rate DECIMAL(10,2) NULL DEFAULT 0 AFTER net_dpp");
        DB::statement("ALTER TABLE tr_order_detail_package MODIFY COLUMN tax_amount DECIMAL(20,2) NULL DEFAULT 0 AFTER tax_rate");
        DB::statement("ALTER TABLE tr_order_detail_package MODIFY COLUMN total DECIMAL(20,2) NULL DEFAULT 0 AFTER tax_amount");
        DB::statement("ALTER TABLE tr_order_detail_package MODIFY COLUMN promo_id BIGINT NULL AFTER total");
    }

    public function down(): void
    {
        // Reorder kolom murni kosmetik (posisi fisik doang, gak ngubah nama/tipe/data) --
        // gak ada efek fungsional yang perlu di-revert.
    }
};
