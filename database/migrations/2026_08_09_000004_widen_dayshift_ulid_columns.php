<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// tr_dayshift.ulid (dan kolom FK-nya tr_order.dayshift_ulid, tr_dayshift_detail.dayshift_ulid)
// tadinya CHAR(26) -- pas buat ULID doang. Sekarang isinya diganti komposisi
// <kode_modul><branch_code><YmdHis> (lihat DayShiftServices::StartDay()), bisa lebih dari
// 26 karakter kalau branch_code-nya panjang, jadi kolomnya dilebarin ke VARCHAR(40) biar
// gak pernah kepotong. Nama kolom TETAP "ulid" (gak di-rename) -- cuma isinya yang beda format.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE tr_dayshift MODIFY ulid VARCHAR(40) NOT NULL');
        DB::statement('ALTER TABLE tr_order MODIFY dayshift_ulid VARCHAR(40) NOT NULL');
        DB::statement('ALTER TABLE tr_dayshift_detail MODIFY dayshift_ulid VARCHAR(40) NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tr_dayshift MODIFY ulid CHAR(26) NOT NULL');
        DB::statement('ALTER TABLE tr_order MODIFY dayshift_ulid CHAR(26) NOT NULL');
        DB::statement('ALTER TABLE tr_dayshift_detail MODIFY dayshift_ulid CHAR(26) NOT NULL');
    }
};
