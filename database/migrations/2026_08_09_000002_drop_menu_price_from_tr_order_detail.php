<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// menu_price (harga per unit dikurangi pajak) sekarang diturunkan langsung dari
// base_price/tax_rate/flag_inclusive_tax di kode (bukan disimpan) -- lihat
// netPrice() di orderPage.vue dan $netPrice closure di OrderServices::RecalculateOrderTotals().
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tr_order_detail', function (Blueprint $table) {
            $table->dropColumn('menu_price');
        });
        Schema::table('tr_order_detail_package', function (Blueprint $table) {
            $table->dropColumn('menu_price');
        });
    }

    public function down(): void
    {
        Schema::table('tr_order_detail', function (Blueprint $table) {
            $table->decimal('menu_price', 20, 2)->nullable()->default(0)->after('base_price');
        });
        Schema::table('tr_order_detail_package', function (Blueprint $table) {
            $table->decimal('menu_price', 20, 2)->nullable()->default(0)->after('base_price');
        });
    }
};
