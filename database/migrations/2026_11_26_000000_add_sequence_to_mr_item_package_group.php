<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// mr_item_package_group dapat kolom `sequence` -- urutan tampil Package Group (mis. "Varian",
// "Addon") di dalam 1 package, 1:1 dengan master_item_package_group.sequence di ERP (sudocore2
// migration 356). Dibaca ORDER BY sequence, id (nilai kembar jatuh ke id). Default 0 supaya POS
// yang sync dari ERP lama (belum kirim sequence) tetap jalan, urutannya jatuh ke id.
// WAJIB di-migrate di POS SEBELUM APIANDORDER/ERP mulai mengirim field `sequence`.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mr_item_package_group', function (Blueprint $table) {
            $table->unsignedInteger('sequence')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('mr_item_package_group', function (Blueprint $table) {
            $table->dropColumn('sequence');
        });
    }
};
