<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Matching ERP (sudocore2 migration 122_alter_table_master_item_package_detail_add_flag_all_menu_template.sql)
// -- disync via SetupServices::getMasterItemPackageDetail() (truncate+insert, kolom baru ini
// otomatis ikut kesave karena MasterItemPackageDetailModel::$guarded = [], gak ada fillable
// list yang perlu diupdate).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mr_item_package_detail', function (Blueprint $table) {
            $table->boolean('flag_all_menu_template')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('mr_item_package_detail', function (Blueprint $table) {
            $table->dropColumn('flag_all_menu_template');
        });
    }
};
