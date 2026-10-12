<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// tr_order_payment dapat kolom baru mdr_amount -- SNAPSHOT nominal biaya MDR (merchant discount
// rate) yang dihitung SAAT payment ini dibuat (payment_amount x mr_payment_method.mdr / 100),
// disimpan apa adanya per baris payment. CUMA informasi/snapshot -- TIDAK ada jurnal/posting
// akuntansi yang nyentuh nilai ini. Mirror pos_order_payment & mb_order_payment (sudocore2,
// migration 242).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tr_order_payment', function (Blueprint $table) {
            $table->decimal('mdr_amount', 20, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('tr_order_payment', function (Blueprint $table) {
            $table->dropColumn('mdr_amount');
        });
    }
};
