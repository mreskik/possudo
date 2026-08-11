<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// tr_dayshift_detail.sync_at kepasang NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE
// CURRENT_TIMESTAMP -- beda dari semua tabel push lain (tr_order, tr_dayshift, dst) yang
// NULL DEFAULT NULL. Ini bikin kolomnya kepakai kayak "last modified" (selalu keisi otomatis
// tiap insert/update), padahal maksudnya buat nandain "belum pernah dipush" (NULL) vs "udah
// dipush" (ada timestamp). Query WHERE sync_at IS NULL di PushDataServices::pushDataDayShiftDetail()
// gak akan pernah nemu baris tanpa fix ini.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE tr_dayshift_detail MODIFY sync_at TIMESTAMP NULL DEFAULT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tr_dayshift_detail MODIFY sync_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
    }
};
