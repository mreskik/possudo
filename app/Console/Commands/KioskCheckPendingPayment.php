<?php

namespace App\Console\Commands;

use App\Services\JobHealthReporter;
use App\Services\PaymentGatewayServices;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// KioskCheckPendingPayment: polling background buat order yang masih 'pending'/'hold' DAN
// attempt TERAKHIRNYA di tr_kiosk_payment_request masih 'pending'/'settlement' (exists check,
// dibatesin ke created_at = MAX(created_at) per order_number biar yang dicek beneran attempt
// terakhir, bukan sembarang baris lama).
//
// SENGAJA gak difilter order_source lagi (dulu cuma 'kiosk', disepakati 2026-08-31 diperluas) --
// tr_kiosk_payment_request & PaymentGatewayServices emang generic dari awal, dipakai bareng
// sama jalur kasir POS (payment-gateway/request, lihat PaymentGatewayController) yang baru
// dibangun. Job ini jaring pengaman KEDUANYA: kalau customer/kasir keluar dari halaman sebelum
// polling frontend sempet nangkep status paid, sweep ini yang nutup celahnya (Laravel gak
// punya webhook Midtrans, status CUMA keupdate kalau ada yang aktif manggil CheckStatus()).
//
// status 'pending' ATAU 'hold' (bukan cuma 'pending') -- order POS pra-bayar bisa di salah satu
// dari dua ini tergantung table_section.can_hold (lihat OrderServices::SaveOrder()), order Kiosk
// selalu 'pending'. Kalau cuma nyapu 'pending', order dine-in (hold) yang lagi nunggu QRIS gak
// akan pernah ke-cover sweep ini.
//
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
// GET /api/kiosk/payment/check-status/{order_number} & /api/payment-gateway/check-status/*),
// bukan HTTP request ke diri sendiri.
//
// SEMENTARA: loop while(true) + sleep(60) di dalam 1 proses artisan yang jalan terus --
// bukan lewat Laravel Task Scheduler (yang butuh cron `* * * * * php artisan schedule:run`).
// Konsekuensinya: proses ini HARUS dijalankan & dijaga tetap hidup dari luar (Supervisor/pm2/
// systemd di production, atau `php artisan kiosk:check-pending-payment` di terminal buat dev)
// -- gak ada yang otomatis nge-restart kalau proses ini mati/crash.
//
// Nama command/service TETAP "kiosk:check-pending-payment"/tr_kiosk_payment_request walau
// scope-nya udah gak Kiosk-doang lagi -- rename itu ngubah signature Artisan (NSSM service,
// deploy/nssm/install-services.ps1) + nama tabel (banyak titik), gak sepadan buat sekarang.
class KioskCheckPendingPayment extends Command
{
    protected $signature = 'kiosk:check-pending-payment';

    protected $description = 'Polling status pembayaran order (Kiosk & POS) yang masih pending/hold, tiap 1 menit';

    public function handle(): void
    {
        $this->info('kiosk:check-pending-payment jalan -- Ctrl+C buat stop.');

        while (true) {
            $orderNumbers = DB::table('tr_order as o')
                ->whereIn('o.status', ['pending', 'hold'])
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
