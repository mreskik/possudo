<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Matching ERP (sudocore2 migration 124_alter_table_master_item_package_detail_add_default_item.sql)
// -- disync via SetupServices::getMasterItemPackageDetail() (truncate+insert, kolom ini otomatis
// ikut kesave, sama kayak flag_all_menu_template). Partial unique index-nya SENGAJA gak
// dimirror di sini -- data yang masuk ke lokal POS udah pasti valid (lolos validasi di ERP
// dulu), lokal cuma "nampung" apa adanya.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mr_item_package_detail', function (Blueprint $table) {
            $table->boolean('default_item')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('mr_item_package_detail', function (Blueprint $table) {
            $table->dropColumn('default_item');
        });
    }
};
