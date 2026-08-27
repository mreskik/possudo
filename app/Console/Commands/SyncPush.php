<?php

namespace App\Console\Commands;

use App\Services\PushDataServices;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

// SyncPush: push data lokal (dayshift + order + detail + payment) ke ERP secara BERKALA --
// sebelumnya PushDataServices cuma dipanggil 1x sehari (DayShiftServices::EndDay(), sesaat
// sebelum jurnal). Job ini gak GANTIIN panggilan itu (dibiarin apa adanya, tetap jadi "jaring
// pengaman terakhir" sebelum jurnal) -- cuma nambahin biar ERP gak nunggu sampai end-of-day
// buat liat data yang uptodate, berguna misal ada laporan/monitoring yang butuh data lokal
// yang gak terlalu basi.
//
// AMAN dipanggil berkali-kali kapan aja -- semua fungsi PushDataServices::push*() filter
// `sync_at IS NULL`, dan sync_at di-reset NULL lagi tiap ada perubahan (lihat "sync update" di
// OrderServices.php) -- gak ada duplikasi push, gak ada resiko nabrak sama panggilan di
// EndDay() (row yang udah ke-push duluan di sini otomatis gak lolos filter lagi pas EndDay()
// manggil ulang).
class SyncPush extends Command
{
    protected $signature = 'sync:push';

    protected $description = 'Push data dayshift/order/payment lokal ke ERP secara berkala';

    // SYNC_PUSH_INTERVAL_SECONDS: diatur lewat .env (default 120 kalau gak di-set), bukan
    // hardcode -- biar gampang disesuaikan per environment tanpa perlu ubah kode/rebuild.
    private const DEFAULT_INTERVAL_SECONDS = 120;

    public function handle(): void
    {
        $intervalSeconds = (int) env('SYNC_PUSH_INTERVAL_SECONDS', self::DEFAULT_INTERVAL_SECONDS);
        $this->info("sync:push jalan (interval {$intervalSeconds} detik) -- Ctrl+C buat stop.");
        $pushService = new PushDataServices;

        while (true) {
            // Urutan WAJIB dayshift dulu sebelum order -- pos_order.dayshift_ulid ngerujuk ke
            // situ, walau gak ada FK constraint keras di server, sama urutan yang dipakai
            // DayShiftServices::EndDay(). 1 fungsi gagal (try/catch per panggilan) gak nahan
            // fungsi lain di putaran yang sama.
            $this->pushOne('dayshift', fn() => $pushService->pushDataDayShift());
            $this->pushOne('dayshift_detail', fn() => $pushService->pushDataDayShiftDetail());
            $this->pushOne('order', fn() => $pushService->pushDataOrder());
            $this->pushOne('order_detail', fn() => $pushService->pushDataOrderDetail());
            $this->pushOne('order_detail_package', fn() => $pushService->pushDataOrderDetailPackage());
            $this->pushOne('order_payment', fn() => $pushService->pushDataOrderPayment());

            sleep($intervalSeconds);
        }
    }

    private function pushOne(string $label, callable $fn): void
    {
        try {
            $fn();
            $this->line("[{$label}] push ok.");
        } catch (\Throwable $e) {
            Log::error("sync:push gagal push {$label}: {$e->getMessage()}");
            $this->error("[{$label}] gagal: {$e->getMessage()}");
        }
    }
}
