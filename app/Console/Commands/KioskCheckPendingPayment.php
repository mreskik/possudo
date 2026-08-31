<?php

namespace App\Console\Commands;

use App\Services\JobHealthReporter;
use App\Services\PaymentGatewayServices;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// KioskCheckPendingPayment: polling background buat order kiosk yang masih 'pending' DAN
// attempt TERAKHIRNYA di tr_kiosk_payment_request masih 'pending'/'settlement' (exists check,
// dibatesin ke created_at = MAX(created_at) per order_number biar yang dicek beneran attempt
// terakhir, bukan sembarang baris lama).
// - Order yang belum pernah manggil payment/request sama sekali -- gak ke-exists, gak usah
//   dicek (belum ada apa-apa buat di-live-check).
// - Order yang attempt terakhirnya udah 'cancel'/'expired'/'failed' -- SENGAJA di-exclude,
//   itu state final, Midtrans gak bakal ngubah itu lagi, dicek ulang tiap menit cuma buang
//   API call ke service payment percuma.
// - 'settlement' SENGAJA tetep di-include (bukan cuma 'pending') -- kalau confirmPayment()
//   di CheckStatus() sempet gagal di tengah jalan (network/DB error) abis status attempt-nya
//   keupdate duluan jadi 'settlement', order itu nyangkut 'pending' padahal duitnya udah
//   settlement. Kalau di-exclude, order kayak gini gak akan PERNAH ke-retry lagi oleh sweep ini.
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
                        ->whereColumn('kpr.order_number', 'o.order_number')
                        ->whereIn('kpr.status', ['pending', 'settlement'])
                        ->whereRaw('kpr.created_at = (SELECT MAX(created_at) FROM tr_kiosk_payment_request WHERE order_number = o.order_number)');
                })
                ->pluck('o.order_number');

            $lastError = null;
            foreach ($orderNumbers as $orderNumber) {
                try {
                    $result = (new PaymentGatewayServices())->CheckStatus($orderNumber);
                    $this->line("[{$orderNumber}] status: {$result['status']}");
                } catch (\Throwable $e) {
                    Log::channel('jobs')->error("kiosk:check-pending-payment gagal cek {$orderNumber}: {$e->getMessage()}");
                    $this->error("[{$orderNumber}] gagal: {$e->getMessage()}");
                    $lastError = "{$orderNumber}: {$e->getMessage()}";
                }
            }

            // Putaran ini sukses kalau gak ada 1 pun order yang gagal dicek (termasuk kalau
            // $orderNumbers kosong -- gak ada kandidat itu normal, bukan error).
            if ($lastError === null) {
                JobHealthReporter::success('kiosk:check-pending-payment');
            } else {
                JobHealthReporter::failed('kiosk:check-pending-payment', $lastError);
            }

            sleep(60);
        }
    }
}
