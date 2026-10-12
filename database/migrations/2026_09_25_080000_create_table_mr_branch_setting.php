<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// mr_branch_setting: pull dari ERP (master_branch_setting, sudocore2) -- SCOPE MINIMAL, cuma
// 2 kolom yang dibutuhin sekarang (domain_receipt/whatsapp_template_text), BUKAN semua ~15
// kolom setting yang ada di ERP (2026-09-25, keputusan sesi ini: "ini aja dulu"). Singleton
// per install (1 branch = 1 baris, SAMA konsep kayak mr_branch) -- disatuin ke jalur sync
// mr_branch yang udah ada (getDatabranch(), truncate+create), BUKAN sync/tabel terpisah,
// karena datanya emang nempel 1:1 ke branch yang sama.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mr_branch_setting', function (Blueprint $table) {
            $table->unsignedBigInteger('branch_id')->primary();
            $table->string('domain_receipt')->nullable();
            $table->text('whatsapp_template_text')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mr_branch_setting');
    }
};
