<?php

namespace App\Http\Controllers;

use App\Models\SysJobHealthModel;
use App\Services\JobHealthReporter;
use Illuminate\Http\Request;

class SystemController extends Controller
{
    // "Berapa kali interval yang diharapkan boleh kelewat sebelum dianggap 'stale'" -- sama
    // ambang yang dipakai heartbeat:send -> sudomobile (30 detik interval, 90 detik threshold),
    // lihat SEND HEARTBEAT.md. Dipakai bareng buat semua job biar konsisten.
    private const STALE_MULTIPLIER = 3;

    // JobsHealth: baca sys_job_health (diisi App\Services\JobHealthReporter dari dalam loop
    // tiap background job), balikin status per job + overall_status. Lihat
    // DOKUMENTASI BACKGROUND JOB/POLA UMUM.md buat konteks lengkapnya.
    public function JobsHealth(Request $request)
    {
        try {
            $rows = SysJobHealthModel::all()->keyBy('job_name');
            $now = now();

            $jobs = [];
            $worstSeverity = 0;

            foreach (array_keys(JobHealthReporter::EXPECTED_INTERVAL_SECONDS) as $jobName) {
                $row = $rows->get($jobName);
                $expectedInterval = $this->resolveExpectedInterval($jobName);

                if (!$row || !$row->last_tick_at) {
                    $status = 'unknown';
                    $secondsSinceLastTick = null;
                } else {
                    // diffInSeconds($abs = true) eksplisit -- versi Carbon yang lebih baru
                    // defaultnya bisa balikin selisih SIGNED (negatif kalau $row->last_tick_at
                    // "lebih baru" dari $now menurut Carbon, walau practically gak mungkin di
                    // sini), jangan diasumsikan selalu positif.
                    $secondsSinceLastTick = (int) round($now->diffInSeconds($row->last_tick_at, true));
                    $status = $this->resolveStatus($row, $secondsSinceLastTick, $expectedInterval);
                }

                $worstSeverity = max($worstSeverity, $this->severity($status));

                $jobs[] = [
                    'job_name' => $jobName,
                    'status' => $status,
                    'last_tick_at' => $row?->last_tick_at?->toIso8601String(),
                    'last_success_at' => $row?->last_success_at?->toIso8601String(),
                    'seconds_since_last_tick' => $secondsSinceLastTick,
                    'expected_interval_seconds' => $expectedInterval,
                    'last_error_at' => $row?->last_error_at?->toIso8601String(),
                    'last_error_message' => $row?->last_error_message,
                ];
            }

            return response()->json([
                'code' => 0,
                'data' => [
                    'overall_status' => $this->severityLabel($worstSeverity),
                    'checked_at' => $now->toIso8601String(),
                    'jobs' => $jobs,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'code' => 100,
                'message' => $e->getMessage(),
            ]);
        }
    }

    // sync:push interval-nya SENGAJA gak hardcode (lihat SyncPush::handle()), jadi diambil dari
    // .env yang sama biar threshold stale-nya ikut nyesuain kalau interval-nya diubah.
    private function resolveExpectedInterval(string $jobName): ?int
    {
        if ($jobName === 'sync:push') {
            return (int) env('SYNC_PUSH_INTERVAL_SECONDS', 120);
        }

        return JobHealthReporter::EXPECTED_INTERVAL_SECONDS[$jobName] ?? null;
    }

    private function resolveStatus(SysJobHealthModel $row, int $secondsSinceLastTick, ?int $expectedInterval): string
    {
        if ($expectedInterval !== null && $secondsSinceLastTick > $expectedInterval * self::STALE_MULTIPLIER) {
            return 'stale';
        }

        // degraded: proses HIDUP & tick jalan, tapi kegagalan TERAKHIR lebih baru dari sukses
        // TERAKHIR -- berarti tiap putaran belakangan ini gagal terus. Ini yang gak kedeteksi
        // NSSM (cuma tau PID alive).
        if ($row->last_error_at && (!$row->last_success_at || $row->last_error_at->gt($row->last_success_at))) {
            return 'degraded';
        }

        return 'ok';
    }

    private function severity(string $status): int
    {
        return match ($status) {
            'stale' => 3,
            'degraded' => 2,
            'unknown' => 1,
            default => 0, // ok
        };
    }

    private function severityLabel(int $severity): string
    {
        return match ($severity) {
            3 => 'stale',
            2 => 'degraded',
            1 => 'unknown',
            default => 'ok',
        };
    }
}
