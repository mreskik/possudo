<?php

namespace App\Console\Commands;

use App\Models\BranchModel;
use App\Services\JobHealthReporter;
use App\Services\MobileOrderPullServices;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
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
                // DB::reconnect() di awal tiap putaran (2026-09-18, fix) -- listenWebSocket() bisa
                // blocking berjam-jam TANPA nyentuh DB sama sekali selagi nunggu WS. Kalau koneksi
                // MySQL kena wait_timeout server selagi idle situ, query PERTAMA setelah itu
                // (resolveWorkerTerminal() di bawah) bakal gagal "MySQL server has gone away" --
                // Laravel/PDO GAK otomatis reconnect koneksi yang beneran putus dalam 1 proses PHP
                // yang sama. Tanpa fix ini, begitu itu kejadian, SEMUA query berikutnya (termasuk
                // retry check di sini, misal abis table_section_id dibenerin admin) gagal terus
                // pakai koneksi yang SAMA-SAMA mati -- gak pernah pulih sendiri, satu-satunya jalan
                // sebelumnya cuma restart proses (PDO baru). Aman dipanggil tiap putaran -- cuma
                // nutup+buka ulang koneksi PDO, murah, gak masalah walau koneksinya masih sehat.
                DB::reconnect();

                $terminals = $service->resolveWorkerTerminal();

                if ($terminals->isEmpty()) {
                    Log::warning('mobile-order:pull: belum ada terminal aktif bertipe Worker Mobile Customer, retry.');
                    JobHealthReporter::failed('mobile-order:pull', 'belum ada terminal aktif bertipe Worker Mobile Customer');
                    $this->error('Belum ada terminal aktif bertipe Worker Mobile Customer.');
                    sleep(self::RECONNECT_DELAY_SECONDS);
                    continue;
                }
                $terminal = $terminals->first();

                if (empty($terminal->table_section_id)) {
                    Log::warning("mobile-order:pull: terminal worker '{$terminal->name}' (id={$terminal->id}) belum di-assign table_section_id, retry.");
                    JobHealthReporter::failed('mobile-order:pull', "terminal worker '{$terminal->name}' belum di-assign table_section_id");
                    $this->error("Terminal worker '{$terminal->name}' belum di-assign table_section_id (lewat Setting > Terminal).");
                    sleep(self::RECONNECT_DELAY_SECONDS);
                    continue;
                }

                $branch = BranchModel::first();
                if (!$branch || empty($branch->token)) {
                    Log::warning('mobile-order:pull: branch/token lokal belum ke-setup, retry.');
                    JobHealthReporter::failed('mobile-order:pull', 'branch/token lokal belum ke-setup');
                    $this->error('Branch/token lokal belum ke-setup.');
                    sleep(self::RECONNECT_DELAY_SECONDS);
                    continue;
                }

                $this->listenWebSocket($service, $terminal, $branch->token);
            } catch (\Throwable $e) {
                // Reconnect DULU sebelum lapor (2026-09-18, fix) -- kalau $e ini SENDIRI gara-gara
                // koneksi DB mati, JobHealthReporter::failed() di bawah (yang juga nulis ke DB)
                // bakal ikut gagal kalau connection-nya belum di-refresh, exception baru bisa
                // lolos gak ketangkep (JobHealthReporter gak punya try/catch sendiri) & nge-crash
                // seluruh command ini.
                DB::reconnect();
                Log::channel('jobs')->error("mobile-order:pull: {$e->getMessage()}");
                JobHealthReporter::failed('mobile-order:pull', $e->getMessage());
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
            // 'pong' ditambahin ke filter (2026-09-18, fix keepalive -- lihat blok ping di bawah)
            // -- default library ini cuma 'text'/'binary' (ping/pong "ketangkep" & di-absorb
            // otomatis di dalam receive(), gak pernah kebalik ke caller). Ditambahin biar
            // getLastOpcode() bisa dipakai verifikasi eksplisit "pong-nya beneran nyampe apa
            // enggak", bukan cuma nebak dari receive() gak exception.
            'filter' => ['text', 'binary', 'pong'],
            // stream context buat koneksi wss:// (SERVER_ENDPOINT https -> $wsUrl otomatis jadi
            // wss di atas) -- sama toggle HTTP_VERIFY_SSL yang dipakai Http:: client biasa (lihat
            // config/services.php), biar konsisten kalau endpoint-nya https/wss dengan
            // certificate self-signed/internal.
            'context' => stream_context_create([
                'ssl' => [
                    'verify_peer' => config('services.http_verify_ssl'),
                    'verify_peer_name' => config('services.http_verify_ssl'),
                ],
            ]),
        ]);

        $this->line("Connect WS ke branch {$terminal->branch_id}...");
        $this->pullCycle($service, $terminal, $branchToken); // catch-up begitu konek
        $this->info('WS konek, nunggu sinyal order baru...');

        // Keepalive aktif (2026-09-18, fix) -- root cause: NAT/load balancer/firewall di depan
        // server sering nge-drop idle connection tracking-nya abis idle sekian lama (observasi
        // lapangan: 1-3 jam) TANPA ngirim FIN/RST ke client. Dari sisi socket lokal, itu
        // KELIATAN IDENTIK sama idle timeout yang sehat -- sama-sama cuma TimeoutException,
        // gak ada cara bedain tanpa VERIFIKASI AKTIF. Makanya sebelum fix ini, koneksi yang
        // mati diam-diam bisa nyangkut SELAMANYA (loop terus nganggep sehat), butuh restart
        // manual buat balikin -- restart bikin koneksi TCP/WS baru dari nol, makanya "nyembuhin".
        //
        // Caranya: timeout PERTAMA sejak pesan/pong terakhir -> kirim ping, tunggu 1 cycle lagi.
        // Kalau timeout LAGI padahal udah nunggu pong (bukan dapet pong ATAU pesan order beneran)
        // -> koneksi dianggap mati, paksa reconnect (jalur yang SAMA kayak ConnectionException).
        $awaitingPong = false;

        while (true) {
            try {
                $client->receive();

                if ($client->getLastOpcode() === 'pong') {
                    // Balesan ping keepalive kita sendiri -- BUKAN sinyal order, tapi kebukti
                    // koneksi masih beneran idup. Reset, lanjut nunggu seperti biasa.
                    $awaitingPong = false;
                    JobHealthReporter::success('mobile-order:pull');
                    continue;
                }

                // Pesan beneran (sinyal order baru) -- reset state ping juga, gak ada gunanya
                // nunggu pong lama kalau ternyata koneksinya kebukti masih hidup dari jalur lain.
                $awaitingPong = false;
                // Payload pesan (order_number) sengaja gak dipakai langsung -- tarik ULANG semua
                // yang pending (fetchPending()), bukan cuma 1 order dari sinyal ini. Sinyal cuma
                // "bangunin", bukan sumber data (lihat mobilenotify/listener.go di APIANDORDER).
                $this->pullCycle($service, $terminal, $branchToken);
            } catch (TimeoutException $e) {
                if ($awaitingPong) {
                    // Udah kirim ping di cycle sebelumnya & TETEP timeout lagi tanpa pong balik
                    // ataupun pesan beneran -- koneksi kebukti mati diam-diam. Paksa reconnect,
                    // JANGAN dianggap sehat lagi (beda dari timeout pertama di bawah).
                    Log::channel('jobs')->error('mobile-order:pull: ping keepalive gak dibales, koneksi WS dianggap mati diam-diam, reconnect ' . self::RECONNECT_DELAY_SECONDS . ' detik lagi.');
                    JobHealthReporter::failed('mobile-order:pull', 'ping keepalive gak dibales, koneksi WS dianggap mati diam-diam');
                    $this->error('Ping keepalive gak dibales, koneksi dianggap mati, reconnect...');
                    sleep(self::RECONNECT_DELAY_SECONDS);
                    return;
                }

                // Timeout PERTAMA sejak pesan/pong terakhir -- bisa jadi idle sehat, bisa jadi
                // koneksi udah mati diam-diam (identik dari sisi socket). Verifikasi aktif: kirim
                // ping, tunggu 1 cycle lagi lihat apa pong-nya balik.
                try {
                    $client->ping();
                    $awaitingPong = true;
                } catch (\Throwable $pingError) {
                    // Gagal kirim ping sama sekali -- socket-nya kebukti udah gak bisa ditulis,
                    // langsung reconnect, gak perlu nunggu 1 cycle lagi buat mastiin.
                    Log::channel('jobs')->error("mobile-order:pull: gagal kirim ping keepalive: {$pingError->getMessage()}, reconnect " . self::RECONNECT_DELAY_SECONDS . " detik lagi.");
                    JobHealthReporter::failed('mobile-order:pull', "gagal kirim ping keepalive: {$pingError->getMessage()}");
                    $this->error("Gagal kirim ping keepalive: {$pingError->getMessage()}");
                    sleep(self::RECONNECT_DELAY_SECONDS);
                    return;
                }

                // Masih dianggap sehat SEMENTARA nunggu konfirmasi pong -- last_tick_at gak boleh
                // keliatan stale cuma gara-gara lagi proses verifikasi ping, sama semangatnya
                // kayak timeout biasa.
                JobHealthReporter::success('mobile-order:pull');
                continue;
            } catch (ConnectionException $e) {
                Log::channel('jobs')->error("mobile-order:pull: koneksi WS putus: {$e->getMessage()}, reconnect " . self::RECONNECT_DELAY_SECONDS . " detik lagi.");
                JobHealthReporter::failed('mobile-order:pull', "koneksi WS putus: {$e->getMessage()}");
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
            JobHealthReporter::success('mobile-order:pull'); // fetch jalan normal, cuma kosong
            return;
        }

        $this->line(count($orders) . ' order mobile baru ditemukan.');

        $lastError = null;
        foreach ($orders as $order) {
            try {
                $service->processOrder($order, $terminal);
                $service->ackOrder($branchToken, $order['order_number']);
                $this->info("Order {$order['order_number']} berhasil ditarik.");
            } catch (\Throwable $e) {
                // sengaja LANJUT ke order berikutnya (bukan break) -- 1 order gagal (mis. dayshift
                // belum dibuka) gak boleh nahan order lain yang valid. ackOrder() SENGAJA gak
                // dipanggil di sini -- order ini masih harus nongol lagi di cycle berikutnya.
                Log::channel('jobs')->error("mobile-order:pull: gagal proses order {$order['order_number']}: {$e->getMessage()}");
                $this->error("Gagal proses order {$order['order_number']}: {$e->getMessage()}");
                $lastError = "{$order['order_number']}: {$e->getMessage()}";
            }
        }

        if ($lastError === null) {
            JobHealthReporter::success('mobile-order:pull');
        } else {
            JobHealthReporter::failed('mobile-order:pull', $lastError);
        }
    }
}
