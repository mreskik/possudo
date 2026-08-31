<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// sys_job_health: status kesehatan tiap background job (heartbeat:send, sync:push, dst),
// 1 baris per job (upsert lewat job_name, sama pola kayak branch_heartbeat di ERP). Diisi
// App\Services\JobHealthReporter dari dalam loop tiap command, dibaca
// GET /api/system/jobs-health -- lihat DOKUMENTASI BACKGROUND JOB/POLA UMUM.md.
//
// last_tick_at vs last_success_at SENGAJA dipisah -- last_tick_at nunjukin proses masih HIDUP
// & jalan tiap putaran (walau gagal), last_success_at nunjukin putaran TERAKHIR YANG SUKSES.
// Job yang tick terus tapi last_error_at lebih baru dari last_success_at berarti proses hidup
// tapi tiap putaran gagal -- kondisi yang gak kedeteksi cuma dari "proses masih ada di Task
// Manager" (NSSM cuma tau PID alive, gak tau proses itu ngapain).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sys_job_health', function (Blueprint $table) {
            $table->string('job_name')->primary();
            $table->timestamp('last_tick_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->text('last_error_message')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sys_job_health');
    }
};
