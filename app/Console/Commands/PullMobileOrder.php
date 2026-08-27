<?php

namespace App\Console\Commands;

use App\Models\BranchModel;
use App\Services\MobileOrderPullServices;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use WebSocket\Client as WebSocketClient;
use WebSocket\ConnectionException;
use WebSocket\TimeoutException;

// PullMobileOrder: background job narik mb_order (paid, dari sudomobile lewat sudocore2) masuk
// ke tr_order lokal POS. Pola SAMA kayak KioskCheckPendingPayment -- while(true) di dalam 1
// proses artisan yang jalan terus, BUKAN Task Scheduler. HARUS dijaga tetap hidup dari luar
// (Supervisor/pm2/systemd di production, `php artisan mobile-order:pull` manual buat dev).
//
// Dibangun BERTAHAP, sesuai urutan yang disepakati (2026-08-26):
//  1. [BERES] Resolve terminal worker (preflight) -- kalau gak ketemu/gak lengkap, jangan
//     lanjut connect WS/fetch apa pun ke APIANDORDER, cukup log & retry preflight tiap 10 detik.
//  2. [BERES] Fetch kandidat order dari APIANDORDER (MobileOrderPullServices::fetchPending()).
//  3. [BERES] Proses tiap order (MobileOrderPullServices::processOrder() + ackOrder()).
//  4. [BERES] WebSocket sebagai jalur utama (bukan lagi polling timer) -- connect ke
//     mobilenotify (APIANDORDER), begitu ada sinyal langsung tarik. Reconnect tiap 10 detik
//     kalau putus. 10 detik itu SEKARANG murni reconnect backoff, BUKAN data-poll interval --
//     selama konek, murni event-driven nunggu pesan WS (gak ada timer nge-poll data lagi).
//
// Order gak akan pernah "hilang" walau preconditions (dayshift/terminal) belum siap pas order
// masuk -- order itu tetap nongol di ERP (pulled_at masih NULL) sampai kepull di cycle
// berikutnya (baik dari sinyal WS atau catch-up abis reconnect). processOrder() yang throw
// (mis. dayshift belum dibuka) SENGAJA gak diikuti ackOrder(), jadi order itu otomatis kecoba
// lagi tanpa perlu logic retry terpisah.
class PullMobileOrder extends Command
{
    protected $signature = 'mobile-order:pull';

    protected $description = 'Background job narik order mobile (sudomobile) yang paid ke tr_order lokal, lewat WebSocket ke APIANDORDER';

    private const RECONNECT_DELAY_SECONDS = 10;

    public function handle(): void
    {
        $this->info('mobile-order:pull jalan -- Ctrl+C buat stop.');
        $service = new MobileOrderPullServices();

        while (true) {
            try {
                $terminals = $service->resolveWorkerTerminal();

                if ($terminals->isEmpty()) {
                    Log::warning('mobile-order:pull: belum ada terminal aktif bertipe Worker Mobile Customer, retry.');
                    $this->error('Belum ada terminal aktif bertipe Worker Mobile Customer.');
                    sleep(self::RECONNECT_DELAY_SECONDS);
                    continue;
                }
                $terminal = $terminals->first();

                if (empty($terminal->table_section_id)) {
                    Log::warning("mobile-order:pull: terminal worker '{$terminal->name}' (id={$terminal->id}) belum di-assign table_section_id, retry.");
                    $this->error("Terminal worker '{$terminal->name}' belum di-assign table_section_id (lewat Setting > Terminal).");
                    sleep(self::RECONNECT_DELAY_SECONDS);
                    continue;
                }

                $branch = BranchModel::first();
                if (!$branch || empty($branch->token)) {
                    Log::warning('mobile-order:pull: branch/token lokal belum ke-setup, retry.');
                    $this->error('Branch/token lokal belum ke-setup.');
                    sleep(self::RECONNECT_DELAY_SECONDS);
                    continue;
                }

                $this->listenWebSocket($service, $terminal, $branch->token);
            } catch (\Throwable $e) {
                Log::error("mobile-order:pull: {$e->getMessage()}");
                $this->error("Gagal: {$e->getMessage()}");
                sleep(self::RECONNECT_DELAY_SECONDS);
            }
        }
    }

    // listenWebSocket: connect ke mobilenotify APIANDORDER, catch-up pull sekali begitu konek
    // (jaga-jaga ada order yang masuk pas worker ini offline), lalu blocking nunggu pesan. Balik
    // (return) ke handle() kalau koneksi bener-bener putus (bukan cuma idle timeout) -- preflight
    // dicek ulang dari awal sebelum reconnect, jaga-jaga konfigurasi terminal berubah selagi
    // command ini hidup.
    private function listenWebSocket(MobileOrderPullServices $service, object $terminal, string $branchToken): void
    {
        $wsUrl = preg_replace('#^http#', 'ws', env('SERVER_ENDPOINT')) . "/pos/ws/mobile-order/{$terminal->branch_id}";

        $client = new WebSocketClient($wsUrl, [
            'headers' => ['Authorization' => 'Bearer ' . $branchToken],
            // timeout PANJANG -- ini idle timeout receive() (dilempar tiap gak ada pesan masuk
            // dalam N detik, itu NORMAL/gak berarti putus, lihat catch TimeoutException di
            // bawah), BUKAN batas waktu tunggu koneksi awal.
            'timeout' => 60,
        ]);

        $this->line("Connect WS ke branch {$terminal->branch_id}...");
        $this->pullCycle($service, $terminal, $branchToken); // catch-up begitu konek
        $this->info('WS konek, nunggu sinyal order baru...');

        while (true) {
            try {
                $client->receive();
                // Payload pesan (order_number) sengaja gak dipakai langsung -- tarik ULANG semua
                // yang pending (fetchPending()), bukan cuma 1 order dari sinyal ini. Sinyal cuma
                // "bangunin", bukan sumber data (lihat mobilenotify/listener.go di APIANDORDER).
                $this->pullCycle($service, $terminal, $branchToken);
            } catch (TimeoutException $e) {
                // idle timeout -- koneksi socket MASIH HIDUP (textalk/websocket gak nutup socket
                // buat timeout, cuma exception buat balikin kontrol ke sini), lanjut nunggu lagi.
                // BUKAN error, gak perlu di-log/reconnect.
                continue;
            } catch (ConnectionException $e) {
                Log::warning("mobile-order:pull: koneksi WS putus: {$e->getMessage()}, reconnect " . self::RECONNECT_DELAY_SECONDS . " detik lagi.");
                $this->error("WS putus: {$e->getMessage()}");
                sleep(self::RECONNECT_DELAY_SECONDS);
                return;
            }
        }
    }

    private function pullCycle(MobileOrderPullServices $service, object $terminal, string $branchToken): void
    {
        $orders = $service->fetchPending($terminal->branch_id, $branchToken);

        if (count($orders) === 0) {
            return;
        }

        $this->line(count($orders) . ' order mobile baru ditemukan.');

        foreach ($orders as $order) {
            try {
                $service->processOrder($order, $terminal);
                $service->ackOrder($branchToken, $order['order_number']);
                $this->info("Order {$order['order_number']} berhasil ditarik.");
            } catch (\Throwable $e) {
                // sengaja LANJUT ke order berikutnya (bukan break) -- 1 order gagal (mis. dayshift
                // belum dibuka) gak boleh nahan order lain yang valid. ackOrder() SENGAJA gak
                // dipanggil di sini -- order ini masih harus nongol lagi di cycle berikutnya.
                Log::error("mobile-order:pull: gagal proses order {$order['order_number']}: {$e->getMessage()}");
                $this->error("Gagal proses order {$order['order_number']}: {$e->getMessage()}");
            }
        }
    }
}
