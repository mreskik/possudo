<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// tr_member_topup_print_log: dedupe guard buat print struk top-up saldo member. CheckTopupStatus
// di-poll berkali-kali sama Kiosk sampai status jadi 'paid' -- tanpa penanda ini, tiap polling
// abis paid bakal nyoba print ulang. TIDAK nyimpen ulang data topup apapun (member_id, amount,
// dst) -- itu semua ditarik live dari APIANDORDER tiap kali (stateless), tabel ini murni "udah
// pernah diproses buat print apa belum". Disepakati 2026-08-28.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tr_member_topup_print_log', function (Blueprint $table) {
            $table->string('reference_number')->primary();
            $table->timestamp('printed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tr_member_topup_print_log');
    }
};
