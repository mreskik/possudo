<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tr_order_detail', function (Blueprint $table) {
            $table->decimal('after_discount', 20, 2)->nullable()->default(0)->after('discount_value');
            $table->decimal('dpp', 20, 2)->nullable()->default(0)->after('after_discount');
        });

        Schema::table('tr_order_detail_package', function (Blueprint $table) {
            $table->decimal('after_discount', 20, 2)->nullable()->default(0)->after('discount_value');
            $table->decimal('dpp', 20, 2)->nullable()->default(0)->after('after_discount');
        });
    }

    public function down(): void
    {
        Schema::table('tr_order_detail', function (Blueprint $table) {
            $table->dropColumn(['after_discount', 'dpp']);
        });

        Schema::table('tr_order_detail_package', function (Blueprint $table) {
            $table->dropColumn(['after_discount', 'dpp']);
        });
    }
};
