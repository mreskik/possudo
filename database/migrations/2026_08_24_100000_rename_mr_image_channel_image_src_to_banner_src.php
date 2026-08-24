<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Rename image_src -> banner_src di mr_image_customer_display & mr_image_kiosk -- penyeragaman
// istilah, ngikutin rename yang sama di ERP (sudocore2 migration 115). Efeknya nyampe ke
// response API Kiosk/Customer Display (field JSON ikut berubah, disengaja -- lihat
// KIOSK BANNER IMAGE.md).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mr_image_customer_display', function (Blueprint $table) {
            $table->renameColumn('image_src', 'banner_src');
        });
        Schema::table('mr_image_kiosk', function (Blueprint $table) {
            $table->renameColumn('image_src', 'banner_src');
        });
    }

    public function down(): void
    {
        Schema::table('mr_image_customer_display', function (Blueprint $table) {
            $table->renameColumn('banner_src', 'image_src');
        });
        Schema::table('mr_image_kiosk', function (Blueprint $table) {
            $table->renameColumn('banner_src', 'image_src');
        });
    }
};
