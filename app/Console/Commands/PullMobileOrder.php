<?php

namespace App\Console\Commands;

use App\Services\MobileOrderPullServices;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

// PullMobileOrder: background job narik mb_order (paid, dari sudomobile lewat sudocore2) masuk
// ke tr_order lokal POS. Pola SAMA kayak KioskCheckPendingPayment -- while(true) + sleep di
// dalam 1 proses artisan yang jalan terus, BUKAN Task Scheduler. HARUS dijaga tetap hidup dari
// luar (Supervisor/pm2/systemd di production, `php artisan mobile-order:pull` manual buat dev).
//
// Dibangun BERTAHAP, sesuai urutan yang disepakati (2026-08-26):
//  1. [SEKARANG] Resolve terminal worker (preflight) -- kalau gak ketemu/gak lengkap, jangan
//     lanjut fetch apa pun ke APIANDORDER, cukup log & skip cycle.
//  2. [NYUSUL] Fetch kandidat order dari APIANDORDER.
//  3. [NYUSUL] Proses tiap order (insert staging + SaveOrder() + payment finalize + print + ack).
class PullMobileOrder extends Command
{
    protected $signature = 'mobile-order:pull';

    protected $description = 'Background job narik order mobile (sudomobile) yang paid ke tr_order lokal';

    public function handle(): void
    {
        $this->info('mobile-order:pull jalan -- Ctrl+C buat stop.');
        $service = new MobileOrderPullServices();

        while (true) {
            try {
                $terminals = $service->resolveWorkerTerminal();

                if ($terminals->isEmpty()) {
                    Log::warning('mobile-order:pull: belum ada terminal aktif bertipe Worker Mobile Customer, skip cycle ini.');
                    $this->error('Belum ada terminal aktif bertipe Worker Mobile Customer.');
                } else {
                    $terminal = $terminals->first();

                    if (empty($terminal->table_section_id)) {
                        Log::warning("mobile-order:pull: terminal worker '{$terminal->name}' (id={$terminal->id}) belum di-assign table_section_id, skip cycle ini.");
                        $this->error("Terminal worker '{$terminal->name}' belum di-assign table_section_id (lewat Setting > Terminal).");
                    } else {
                        $this->line("Terminal worker: {$terminal->name} (id={$terminal->id}, table_section_id={$terminal->table_section_id})");
                        // TODO: fetch kandidat dari APIANDORDER + proses tiap order (langkah 2 & 3).
                    }
                }
            } catch (\Throwable $e) {
                Log::error("mobile-order:pull: {$e->getMessage()}");
                $this->error("Gagal: {$e->getMessage()}");
            }

            sleep(10);
        }
    }
}
