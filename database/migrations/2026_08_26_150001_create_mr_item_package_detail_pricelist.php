<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// mr_item_package_detail_pricelist: mirror lokal master_item_package_detail_menu_template (ERP)
// -- override harga sub-item package PER pricelist, dikonsumsi kalau
// mr_item_package_detail.flag_all_menu_template = false. Field "pricelist_id" (BUKAN
// "menu_template_id") -- APIANDORDER (sync endpoint get_item_package_detail_pricelist) yang
// nerjemahin nama kolomnya, biar konsisten sama penamaan yang udah dipake mr_pricelist_detail.
//
// Sync-nya truncate+insert (sama pola kayak mr_item_package_detail/mr_item_package_group) --
// AMAN sekarang karena id master_item_package_detail_menu_template di ERP juga udah stabil
// (SyncPackageGroups(), bukan replace-all lagi).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mr_item_package_detail_pricelist', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('item_package_detail_id');
            $table->unsignedBigInteger('pricelist_id');
            $table->decimal('price', 18, 2);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mr_item_package_detail_pricelist');
    }
};
