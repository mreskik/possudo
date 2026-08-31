<?php

namespace App\Services;

use App\Models\SysJobHealthModel;
use Illuminate\Support\Str;

// JobHealthReporter: dipanggil dari dalam loop tiap background job (App\Console\Commands) buat
// nyatet "masih hidup & jalan" (tick/success) atau "putaran ini gagal" (failed) ke
// sys_job_health. Dibaca balik lewat GET /api/system/jobs-health (SystemController).
//
// EXPECTED_INTERVAL_SECONDS dipakai buat nentuin status 'stale' -- threshold-nya 3x interval,
// pola SAMA kayak yang dipakai heartbeat:send -> sudomobile (lihat SEND HEARTBEAT.md), banyak
// slack buat variasi kecil (waktu proses query/HTTP di luar sleep()) tanpa false alarm.
// mobile-order:pull null -- dia event-driven (WebSocket), gak ada interval polling tetap buat
// dibandingin, jadi status-nya cuma dibedain 'ok' (ada tick) vs 'unknown' (belum pernah tick).
class JobHealthReporter
{
    public const EXPECTED_INTERVAL_SECONDS = [
        'heartbeat:send' => 30,
        'kiosk:check-pending-payment' => 60,
        'mobile-order:pull' => null,
        'sync:push' => null, // dari .env (SYNC_PUSH_INTERVAL_SECONDS), lihat SyncPush::handle()
    ];

    public static function success(string $job): void
    {
        SysJobHealthModel::updateOrCreate(
            ['job_name' => $job],
            ['last_tick_at' => now(), 'last_success_at' => now()],
        );
    }

    public static function failed(string $job, string $message): void
    {
        SysJobHealthModel::updateOrCreate(
            ['job_name' => $job],
            [
                'last_tick_at' => now(),
                'last_error_at' => now(),
                'last_error_message' => Str::limit($message, 500),
            ],
        );
    }
}
