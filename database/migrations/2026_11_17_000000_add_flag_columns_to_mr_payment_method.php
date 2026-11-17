<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// mr_payment_method.flag_all_visit_purpose/flag_mandatory_note -- cermin dari
// master_payment_method.flag_all_visit_purpose/flag_mandatory_note di sudocore2 (lihat
// paymentmethod_dto.go), ke-sync otomatis lewat SetupServices::getMasterPaymentMethod() --
// gak perlu perubahan kode sync di POS (upsert otomatis nemu kolom ini begitu ada), cuma
// apiandorder yang perlu ditambahin SELECT 2 kolom ini (lihat master_service.go). Nullable,
// sama pola kolom payment_gateway_code (migration 2026_08_10_000002).
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE mr_payment_method ADD COLUMN flag_all_visit_purpose BOOLEAN NULL');
        DB::statement('ALTER TABLE mr_payment_method ADD COLUMN flag_mandatory_note BOOLEAN NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE mr_payment_method DROP COLUMN flag_all_visit_purpose');
        DB::statement('ALTER TABLE mr_payment_method DROP COLUMN flag_mandatory_note');
    }
};
