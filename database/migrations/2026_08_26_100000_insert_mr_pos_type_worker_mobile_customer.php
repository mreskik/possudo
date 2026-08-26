<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Matching persis migration sudocore2 (cmd/migration/121_insert_master_pos_type_worker_mobile_customer.sql)
// -- mr_pos_type di sini TABEL TERPISAH, gak di-sync otomatis dari master_pos_type ERP (cuma
// baris terminal-nya/mr_terminal yang disync, bukan tabel type-nya) -- ID WAJIB SAMA (4) biar
// pos_type_id yang ke-sync ke mr_terminal nemuin nama/device_type yang bener di LEFT JOIN
// (KioskController::TerminalDetail() dkk), bukan null.
return new class extends Migration
{
    public function up(): void
    {
        if (!DB::table('mr_pos_type')->where('id', 4)->exists()) {
            DB::table('mr_pos_type')->insert([
                'id' => 4,
                'name' => 'Worker Mobile Customer',
                'device_type' => 'worker_mobile_customer',
            ]);
        }
    }

    public function down(): void
    {
        DB::table('mr_pos_type')->where('id', 4)->delete();
    }
};
