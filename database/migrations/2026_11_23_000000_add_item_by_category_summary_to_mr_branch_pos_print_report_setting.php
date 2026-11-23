<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// mr_branch_pos_print_report_setting dapat 2 kolom baru -- section BARU, breakdown NESTED
// kategori->item (kategori sebagai header, item2 di dalamnya sebagai sub-baris qty+amount),
// BEDA dari endday_item_category_summary/shift_item_category_summary yang SUDAH ADA (itu
// breakdown FLAT per kategori doang, sekarang dicetak dengan judul "CATEGORY SUMMARY" di
// struk). 1:1 sama skema ERP master_branch_pos_print_report_setting (sudocore2 migration 353).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mr_branch_pos_print_report_setting', function (Blueprint $table) {
            $table->boolean('endday_item_by_category_summary')->default(true);
            $table->boolean('shift_item_by_category_summary')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('mr_branch_pos_print_report_setting', function (Blueprint $table) {
            $table->dropColumn(['endday_item_by_category_summary', 'shift_item_by_category_summary']);
        });
    }
};
