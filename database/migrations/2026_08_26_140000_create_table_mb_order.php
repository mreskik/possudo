<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// mb_order: tabel tampungan LOKAL (MINIMAL, cuma order_number + flag_confirm) buat order mobile
// (sudomobile) yang udah ke-pull & diproses PullMobileOrder (App\Console\Commands). BUKAN sumber
// kebenaran transaksi -- itu tetap tr_order/tr_order_detail (diisi via
// OrderServices::SaveOrder(), reuse order_number & payment_number dari mb_order REMOTE apa
// adanya, gak digenerate baru). Tabel ini murni penanda "order_number ini asalnya dari mobile,
// sekaligus flag udah dikonfirm/dilihat staff apa belum" -- detail lain (customer/total/dst)
// SENGAJA gak dimirror di sini, tinggal JOIN ke tr_order pake order_number yang sama kalau
// butuh, daripada nyimpen dobel data yang bisa basi.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mb_order', function (Blueprint $table) {
            $table->string('order_number')->primary();
            // status: snapshot status mb_order REMOTE pas ke-pull -- default 'paid' (satu-satunya
            // status yang bikin order lolos filter kandidat di APIANDORDER, lihat
            // MobileOrderPullServices). Kolom ini BUKAN buat nyambungin balik ke lifecycle
            // tr_order (itu urusan tr_order.status sendiri) -- murni referensi status mobile
            // pas order ini diproses.
            $table->string('status')->default('paid');
            // flag_confirm: MURNI acknowledgment UI ("staff udah liat notif order baru ini"),
            // gak ngaruh ke proses order sama sekali -- beda tujuan dari sync_at di mb_order
            // REMOTE (yang nandain "udah diproses backend").
            $table->boolean('flag_confirm')->default(false);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mb_order');
    }
};
