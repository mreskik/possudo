<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mr_promo', function (Blueprint $table) {
            $table->boolean('flag_apply_to_all')->default(true)->after('flag_all_times');
        });

        Schema::create('mr_promo_apply_to', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('promo_id');
            $table->string('apply_to'); // pos, kiosk, mobile_customer, qr_order
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mr_promo_apply_to');
        Schema::table('mr_promo', function (Blueprint $table) {
            $table->dropColumn('flag_apply_to_all');
        });
    }
};
