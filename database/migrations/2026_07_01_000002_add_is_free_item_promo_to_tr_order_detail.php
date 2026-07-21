<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tr_order_detail', function (Blueprint $table) {
            $table->boolean('is_free_item_promo')->default(false)->after('promo_id');
        });
    }

    public function down(): void
    {
        Schema::table('tr_order_detail', function (Blueprint $table) {
            $table->dropColumn('is_free_item_promo');
        });
    }
};
