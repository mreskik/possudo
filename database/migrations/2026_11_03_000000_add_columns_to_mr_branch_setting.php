<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// mr_branch_setting dapat 17 kolom baru (2026-11-03) -- menyamakan 1:1 ke skema ERP
// master_branch_setting (sudocore2, backend/models/masterBranch.go), yang sebelumnya cuma ditarik
// 2 kolom (domain_receipt/whatsapp_template_text, migration 2026_09_25_080000). Sekarang
// get_data_branch (APIANDORDER::branchDataQuery()) ngirim SEMUA kolom master_branch_setting
// (kecuali `id` surrogate ERP, gak perlu di sini -- branch_id tetap PK, pola yang sudah berjalan).
// Tipe kolom disesuaikan tipe ERP: boolean utk flag_*, bigInteger utk angka, string/nullable utk
// yang bisa NULL di ERP (rounding_mode/notification_end), decimal nullable utk
// rounding_nearst_value (numeric(20,2) di ERP).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mr_branch_setting', function (Blueprint $table) {
            $table->boolean('flag_time_out_user')->default(false)->after('whatsapp_template_text');
            $table->bigInteger('time_out_second')->default(0)->after('flag_time_out_user');
            $table->boolean('flag_rounding')->default(false)->after('time_out_second');
            $table->string('rounding_mode')->nullable()->after('flag_rounding');
            $table->decimal('rounding_nearst_value', 20, 2)->nullable()->after('rounding_mode');
            $table->boolean('flag_email_report_endday')->default(false)->after('rounding_nearst_value');
            $table->string('email_report_endday')->nullable()->after('flag_email_report_endday');
            $table->boolean('flag_notification_end')->default(false)->after('email_report_endday');
            $table->time('notification_end')->nullable()->after('flag_notification_end');
            $table->boolean('flag_queue')->default(false)->after('notification_end');
            $table->bigInteger('queue_reset_after_each')->default(0)->after('flag_queue');
            $table->bigInteger('queue_start_from')->default(0)->after('queue_reset_after_each');
            $table->boolean('flag_guest_comment')->default(false)->after('queue_start_from');
            $table->boolean('flag_bill_name')->default(false)->after('flag_guest_comment');
            $table->boolean('flag_note_payment')->default(false)->after('flag_bill_name');
            $table->boolean('flag_online_service_mobile_customer')->default(false)->after('flag_note_payment');
            $table->boolean('flag_barber_service')->default(false)->after('flag_online_service_mobile_customer');
        });
    }

    public function down(): void
    {
        Schema::table('mr_branch_setting', function (Blueprint $table) {
            $table->dropColumn([
                'flag_time_out_user',
                'time_out_second',
                'flag_rounding',
                'rounding_mode',
                'rounding_nearst_value',
                'flag_email_report_endday',
                'email_report_endday',
                'flag_notification_end',
                'notification_end',
                'flag_queue',
                'queue_reset_after_each',
                'queue_start_from',
                'flag_guest_comment',
                'flag_bill_name',
                'flag_note_payment',
                'flag_online_service_mobile_customer',
                'flag_barber_service',
            ]);
        });
    }
};
