<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// mr_branch dapat 4 kolom baru terkait brand (2026-10-12) -- brand_id (referensi/identitas,
// NULLABLE karena branch bisa aja belum di-assign brand) + brand_color/primary_color/
// secondary_color (3 warna brand, migration sudocore2 263). Ditarik via sync_pull/get_data_branch
// (APIANDORDER::GetDataBranch(), LEFT JOIN master_brand -- branch tanpa brand_id tetap ke-pull,
// field2 brand doang yang kosong). brand_code/brand_name UDAH ADA duluan (migration awal),
// bukan bagian perubahan ini.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mr_branch', function (Blueprint $table) {
            $table->bigInteger('brand_id')->nullable()->after('id');
            $table->string('brand_color', 255)->nullable()->after('brand_name');
            $table->string('primary_color', 255)->nullable()->after('brand_color');
            $table->string('secondary_color', 255)->nullable()->after('primary_color');
        });
    }

    public function down(): void
    {
        Schema::table('mr_branch', function (Blueprint $table) {
            $table->dropColumn(['brand_id', 'brand_color', 'primary_color', 'secondary_color']);
        });
    }
};
