<?php

namespace App\Console\Commands;

use App\Models\BranchModel;
use App\Models\DaySiftModel;
use App\Services\JobHealthReporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// SendHeartbeat: "kabarin ERP kalau branch ini masih hidup & lagi buka" -- dipakai sudomobile
// buat barrier keras nolak order kalau branch-nya offline (belum dibangun, lihat
// APIANDORDER/backend/modules/apipos/heartbeat/heartbeat_handler.go).
//
// SENGAJA jadi command SENDIRI (bukan numpang ke mobile-order:pull) walau keduanya sama-sama
// "background job POS", disepakati 2026-08-27 -- heartbeat dipakai buat NGE-BLOCK order
// customer, jadi harus jadi proses PALING SIMPEL/PALING RELIABLE (cuma HTTP call + cek
// dayshift, gak ada WS/reconnect logic yang lebih rawan nyangkut). Kalau digabung ke proses
// yang lebih kompleks, bug di bagian lain proses itu bisa ikut nunda/berhentiin heartbeat,
// nolak order customer padahal branch-nya sendiri baik-baik aja.
//
// SENGAJA gak kirim ping kalau dayshift belum/gak lagi kebuka -- "branch hidup" doang gak
// cukup, yang dicek sudomobile itu "branch SIAP nerima order" (ada dayshift aktif buat proses
// order-nya, sama guard yang udah ada di MobileOrderPullServices::processOrder()). Kalau
// dayshift ketutup, biarin branch_heartbeat basi (expired) dengan sendirinya di ERP.
class SendHeartbeat extends Command
{
    protected $signature = 'heartbeat:send';

    protected $description = 'Kirim heartbeat branch ke ERP (lewat APIANDORDER) tiap 30 detik, kalau dayshift lagi kebuka';

    private const INTERVAL_SECONDS = 30;

    public function handle(): void
    {
        $this->info('heartbeat:send jalan -- Ctrl+C buat stop.');

        while (true) {
            try {
                $this->tick();
            } catch (\Throwable $e) {
                Log::channel('jobs')->error("heartbeat:send: {$e->getMessage()}");
                JobHealthReporter::failed('heartbeat:send', $e->getMessage());
                $this->error("Gagal: {$e->getMessage()}");
            }

            sleep(self::INTERVAL_SECONDS);
        }
    }

    private function tick(): void
    {
        $branch = BranchModel::first();
        if (!$branch || empty($branch->token)) {
            $this->error('Branch/token lokal belum ke-setup, skip.');
            JobHealthReporter::success('heartbeat:send'); // skip yang disengaja, bukan error
            return;
        }

        $dayshiftOpen = DaySiftModel::whereNull('dayout_time')->exists();
        if (!$dayshiftOpen) {
            $this->line('Dayshift belum/gak lagi kebuka, skip kirim heartbeat.');
            JobHealthReporter::success('heartbeat:send'); // skip yang disengaja, bukan error
            return;
        }

        try {
            $response = Http::withToken($branch->token)
                ->post(env('SERVER_ENDPOINT') . "/pos/heartbeat/{$branch->id}");
        } catch (\Throwable $e) {
            Log::channel('jobs')->error("heartbeat:send: gagal koneksi ke APIANDORDER: {$e->getMessage()}");
            JobHealthReporter::failed('heartbeat:send', "gagal koneksi ke APIANDORDER: {$e->getMessage()}");
            $this->error("Gagal koneksi: {$e->getMessage()}");
            return;
        }

        if ($response->json('code') !== 0) {
            Log::channel('jobs')->error('heartbeat:send: gagal', ['response' => $response->json()]);
            JobHealthReporter::failed('heartbeat:send', 'ditolak server: ' . json_encode($response->json()));
            $this->error('Heartbeat ditolak server: ' . json_encode($response->json()));
            return;
        }

        JobHealthReporter::success('heartbeat:send');
        $this->line('Heartbeat terkirim.');
    }
}
