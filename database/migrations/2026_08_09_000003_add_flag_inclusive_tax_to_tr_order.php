<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// flag_inclusive_tax di header order -- invariant per order (sumbernya mr_pricelist.is_inclusive
// via visit purpose, dikonfirmasi semua baris tr_order_detail dalam 1 order selalu sama), dipakai
// buat nentuin layout print (inclusive: gak ada breakdown pajak, exclusive: breakdown di atas
// Grand Total). Nullable & TANPA default -- order lama (sebelum kolom ini ada) sengaja dibiarkan
// NULL, PrintServices fallback baca dari tr_order_detail baris pertama, bukan diasumsikan true/false.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tr_order', function (Blueprint $table) {
            $table->boolean('flag_inclusive_tax')->nullable()->after('total_billing');
        });
    }

    public function down(): void
    {
        Schema::table('tr_order', function (Blueprint $table) {
            $table->dropColumn('flag_inclusive_tax');
        });
    }
};
