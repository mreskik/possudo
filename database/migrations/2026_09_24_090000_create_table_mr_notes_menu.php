<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// mr_notes_menu: pull dari ERP (master_notes_menu, sudocore2) -- daftar catatan siap-pilih
// buat menu POS (mis. "Extra Pedas", "Less Sugar"). Filter branch & flag_active UDAH KELAR di
// server (APIANDORDER, query GetMasterNotesMenu()) sebelum sampai ke sini -- makanya SENGAJA
// GAK ADA kolom flag_all_branch/flag_active/tabel _branches di POS (beda dari mr_promo yang
// masih nyimpen field itu apa adanya walau POS gak pernah baca) -- baris yang nyampe ke POS
// UDAH PASTI aktif & berlaku buat branch ini. Sync-nya FULL REPLACE (truncate+insert, sama
// pola getMasterPricelist()), bukan upsert -- notes menu yang dinonaktifkan/dihapus di ERP
// otomatis ikut hilang dari POS di sync berikutnya.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mr_notes_menu', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->string('name');
            $table->string('applies_to'); // all_category, category, sub_category
            $table->timestamp('created_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
        });

        Schema::create('mr_notes_menu_categories', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('notes_menu_id');
            $table->unsignedBigInteger('category_id');
        });

        Schema::create('mr_notes_menu_subcategories', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('notes_menu_id');
            $table->unsignedBigInteger('sub_category_id');
        });

        Schema::create('mr_notes_menu_detail', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('notes_menu_id');
            $table->string('short_notes');
            $table->text('full_notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mr_notes_menu_detail');
        Schema::dropIfExists('mr_notes_menu_subcategories');
        Schema::dropIfExists('mr_notes_menu_categories');
        Schema::dropIfExists('mr_notes_menu');
    }
};
