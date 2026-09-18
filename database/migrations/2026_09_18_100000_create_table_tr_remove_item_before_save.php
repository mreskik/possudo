<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// tr_remove_item_before_save (+ _package): audit trail item yang dihapus kasir dari cart lokal
// SEBELUM order pernah tersimpan ke server sama sekali (tombol Trash2 di orderPage.vue,
// removeItemOrder() -- cuma muncul kalau itemOrder belum punya `ulid`, beda dari "Cancel Item"
// yang udah ada buat item yang UDAH tersimpan). order_number karena itu SELALU null di skenario
// ini (order belum pernah dibuat), kolomnya tetap disediakan (nullable) buat jaga-jaga kalau
// nanti dipakai skenario lain. dayshift_ulid nullable, diresolve server-side dari
// DayShiftServices::GetDayShift() (shift harian yang aktif) -- BUKAN dayshift_detail_ulid,
// karena tr_dayshift_detail itu catatan shift yang SUDAH di-EndShift (baris dibuat pas shift
// SELESAI, bukan pas mulai), jadi gak ada ulid yang valid buat "shift yang sedang berjalan
// sekarang" kalau belum pernah ganti shift hari itu (lihat diskusi 2026-09-18).
//
// PK `ulid` (bukan id auto-increment) + kolom `sync_at` nullable -- disamain sama konvensi
// tabel tr_* lain (tr_order, tr_order_detail, tr_order_payment, tr_dayshift, dst) yang emang
// didesain buat di-push/sync ke ERP (sudocore2), walau tabel ini SENDIRI belum tentu langsung
// dipush sekarang -- strukturnya disiapin konsisten dari awal biar gampang kalau nanti dipush.
//
// _package: 1 item HEAD yang dihapus bisa bawa beberapa sub-item package sekaligus (menuPackageList
// di frontend) -- pola header+detail sama persis tr_order_detail + tr_order_detail_package.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tr_remove_item_before_save', function (Blueprint $table) {
            $table->ulid('ulid')->primary();
            $table->string('order_number')->nullable();
            $table->ulid('dayshift_ulid')->nullable();
            $table->unsignedBigInteger('item_conv_id')->nullable();
            $table->unsignedBigInteger('qty');
            $table->timestamp('created_at')->useCurrent();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('sync_at')->nullable();
        });

        // nama foreign key dikasih eksplisit ('fk_riibs_package_header') -- nama auto-generate
        // Laravel kepanjangan buat limit identifier MySQL (64 karakter, error 1059).
        Schema::create('tr_remove_item_before_save_package', function (Blueprint $table) {
            $table->ulid('ulid')->primary();
            $table->ulid('tr_remove_item_before_save_ulid');
            $table->foreign('tr_remove_item_before_save_ulid', 'fk_riibs_package_header')
                ->references('ulid')->on('tr_remove_item_before_save')
                ->cascadeOnDelete();
            $table->unsignedBigInteger('item_conv_id')->nullable();
            $table->unsignedBigInteger('qty');
            $table->timestamp('sync_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tr_remove_item_before_save_package');
        Schema::dropIfExists('tr_remove_item_before_save');
    }
};
