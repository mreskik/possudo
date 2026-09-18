<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// branch_id: ketinggalan pas migration awal (2026_09_18_100000) -- disamain sama pola tr_order/
// tr_dayshift yang keduanya punya branch_id sendiri. Dibutuhkan di sisi APIANDORDER buat resolve
// company_id (branch_id -> master_branch.company_id) pas push ke ERP, sama pola PushPOSOrder()
// (lihat audit 2026-09-18: tanpa ini, push header selalu gagal karena branch_id di payload
// selalu 0/kosong -- resolve company_id gagal, dan push package ikut gagal FK constraint sebagai
// konsekuensinya).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tr_remove_item_before_save', function (Blueprint $table) {
            $table->unsignedBigInteger('branch_id')->nullable()->after('ulid');
        });
    }

    public function down(): void
    {
        Schema::table('tr_remove_item_before_save', function (Blueprint $table) {
            $table->dropColumn('branch_id');
        });
    }
};
