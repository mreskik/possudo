<?php

namespace App\Console\Commands;

use App\Services\JobHealthReporter;
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
            $lastError = $this->pushOne('dayshift', fn() => $pushService->pushDataDayShift());
            $lastError = $this->pushOne('dayshift_detail', fn() => $pushService->pushDataDayShiftDetail()) ?? $lastError;
            $lastError = $this->pushOne('order', fn() => $pushService->pushDataOrder()) ?? $lastError;
            $lastError = $this->pushOne('order_detail', fn() => $pushService->pushDataOrderDetail()) ?? $lastError;
            $lastError = $this->pushOne('order_detail_package', fn() => $pushService->pushDataOrderDetailPackage()) ?? $lastError;
            $lastError = $this->pushOne('order_payment', fn() => $pushService->pushDataOrderPayment()) ?? $lastError;

            // Putaran ini dianggap sukses cuma kalau SEMUA 6 fungsi lolos -- kalau ada 1 aja yang
            // gagal, JobHealthReporter::failed() (bukan success()) biar kelihatan di jobs-health,
            // walau 5 fungsi lain berhasil (lihat GET /api/system/jobs-health).
            if ($lastError === null) {
                JobHealthReporter::success('sync:push');
            } else {
                JobHealthReporter::failed('sync:push', $lastError);
            }

            sleep($intervalSeconds);
        }
    }

    // pushOne: balikin pesan error (string) kalau $fn() gagal, null kalau sukses -- dipakai
    // caller buat nentuin status putaran ini di sys_job_health tanpa nge-throw lagi ke luar.
    private function pushOne(string $label, callable $fn): ?string
    {
        try {
            $fn();
            $this->line("[{$label}] push ok.");
            return null;
        } catch (\Throwable $e) {
            Log::channel('jobs')->error("sync:push gagal push {$label}: {$e->getMessage()}");
            $this->error("[{$label}] gagal: {$e->getMessage()}");
            return "{$label}: {$e->getMessage()}";
        }
    }
}
