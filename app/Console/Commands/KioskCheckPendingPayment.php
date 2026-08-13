<?php

namespace App\Console\Commands;

use App\Services\PaymentGatewayServices;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// KioskCheckPendingPayment: polling background buat order kiosk yang masih 'pending' DAN udah
// pernah manggil payment/request (exists check ke tr_kiosk_payment_request -- order yang masih
// di tahap milih menu, belum sampe minta QR, gak usah dicek, gak ada yang perlu di-polling).
// Manggil PaymentGatewayServices::CheckStatus() langsung in-process (logic yang sama dipakai
// GET /api/kiosk/payment/check-status/{order_number}), bukan HTTP request ke diri sendiri.
//
// SEMENTARA: loop while(true) + sleep(60) di dalam 1 proses artisan yang jalan terus --
// bukan lewat Laravel Task Scheduler (yang butuh cron `* * * * * php artisan schedule:run`).
// Konsekuensinya: proses ini HARUS dijalankan & dijaga tetap hidup dari luar (Supervisor/pm2/
// systemd di production, atau `php artisan kiosk:check-pending-payment` di terminal buat dev)
// -- gak ada yang otomatis nge-restart kalau proses ini mati/crash.
class KioskCheckPendingPayment extends Command
{
    protected $signature = 'kiosk:check-pending-payment';

    protected $description = 'Polling status pembayaran order kiosk yang masih pending, tiap 1 menit';

    public function handle(): void
    {
        $this->info('kiosk:check-pending-payment jalan -- Ctrl+C buat stop.');

        while (true) {
            $orderNumbers = DB::table('tr_order as o')
                ->where('o.order_source', 'kiosk')
                ->where('o.status', 'pending')
                ->whereExists(function ($query) {
                    $query->select(DB::raw(1))
                        ->from('tr_kiosk_payment_request as kpr')
                        ->whereColumn('kpr.order_number', 'o.order_number');
                })
                ->pluck('o.order_number');

            foreach ($orderNumbers as $orderNumber) {
                try {
                    $result = (new PaymentGatewayServices())->CheckStatus($orderNumber);
                    $this->line("[{$orderNumber}] status: {$result['status']}");
                } catch (\Throwable $e) {
                    Log::error("kiosk:check-pending-payment gagal cek {$orderNumber}: {$e->getMessage()}");
                    $this->error("[{$orderNumber}] gagal: {$e->getMessage()}");
                }
            }

            sleep(60);
        }
    }
}
