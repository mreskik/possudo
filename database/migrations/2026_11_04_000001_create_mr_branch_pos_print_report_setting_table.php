<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// mr_branch_pos_print_report_setting -- singleton per install (branch_id langsung PK, sama pola
// mr_branch_setting), toggle 16 section cetak struk End Day/Shift Report. 1:1 sama skema ERP
// master_branch_pos_print_report_setting (sudocore2 migration 323), ditarik lewat jalur sync
// mr_branch yang sudah ada (SetupServices::getDatabranch(), truncate+create bareng mr_branch,
// terpisah dari mr_branch_setting -- 2 tabel beda di ERP, 2 tabel beda juga di sini).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mr_branch_pos_print_report_setting', function (Blueprint $table) {
            $table->unsignedBigInteger('branch_id')->primary();

            $table->boolean('endday_sales_summary')->default(true);
            $table->boolean('endday_sales_type_summary')->default(true);
            $table->boolean('endday_order_source_summary')->default(true);
            $table->boolean('endday_item_category_summary')->default(true);
            $table->boolean('endday_staff_sales_summary')->default(true);
            $table->boolean('endday_payment_method_summary')->default(true);
            $table->boolean('endday_staff_payment_summary')->default(true);
            $table->boolean('endday_cash_flow_summary')->default(true);

            $table->boolean('shift_sales_summary')->default(true);
            $table->boolean('shift_sales_type_summary')->default(true);
            $table->boolean('shift_order_source_summary')->default(true);
            $table->boolean('shift_item_category_summary')->default(true);
            $table->boolean('shift_staff_sales_summary')->default(true);
            $table->boolean('shift_payment_method_summary')->default(true);
            $table->boolean('shift_staff_payment_summary')->default(true);
            $table->boolean('shift_cash_flow_summary')->default(true);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mr_branch_pos_print_report_setting');
    }
};
