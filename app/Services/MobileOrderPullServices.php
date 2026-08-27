<?php

namespace App\Services;

use App\Models\DaySiftModel;
use App\Models\TableSectionModel;
use App\Models\TrOrderDetailModel;
use App\Models\TrOrderDetailPackageModel;
use App\Models\TrOrderModel;
use App\Models\TrOrderPaymentModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

// MobileOrderPullServices: logic inti buat background job `mobile-order:pull` (worker yang narik
// mb_order paid dari sudocore2 lewat APIANDORDER, masuk ke tr_order lokal). Dipisah dari Command
// (App\Console\Commands\PullMobileOrder) biar Command-nya tetap tipis (cuma orkestrasi loop),
// method di sini yang beneran ngerjain -- gampang dipanggil manual/dites terpisah dari loop-nya.
//
// SENGAJA gak reuse OrderServices::SaveOrder()/PaymentServices::SavePayment() -- dua-duanya
// dirancang buat dipanggil dari Request HTTP interaktif (baca bearerToken() buat cashier name,
// throw exception buat nolak order kalau stok kurang/table invalid). Order mobile di sini
// SUDAH DIBAYAR sebelum sampai ke POS (lihat status='paid' filter di get_pending APIANDORDER) --
// gak bisa "ditolak" kayak order baru yang belum bayar, jadi alurnya beda: langsung insert
// status 'paid', gak ada tahap pending/hold, gak ada cashier/session.
class MobileOrderPullServices
{
    // resolveWorkerTerminal: ambil semua terminal lokal (mr_terminal) yang tipenya
    // "Worker Mobile Customer" (join mr_pos_type via device_type, BUKAN hardcode pos_type_id --
    // biar gak nyimpang kalau id-nya beda antar environment, walau di migration awal kita
    // pastiin id=4 di dua sisi). Diurutin id ASC -- kalau kebetulan ada lebih dari 1 terminal
    // worker terdaftar (belum ada aturan "harus cuma 1"), yang id-nya paling kecil (paling
    // lama didaftarin) dianggap yang utama.
    //
    // is_active=true doang yang diambil -- terminal yang dinonaktifin admin gak boleh kepake
    // proses ini.
    public function resolveWorkerTerminal()
    {
        return DB::table('mr_terminal as t')
            ->join('mr_pos_type as pt', 'pt.id', '=', 't.pos_type_id')
            ->where('pt.device_type', 'worker_mobile_customer')
            ->where('t.is_active', true)
            ->select('t.id', 't.name', 't.branch_id', 't.table_section_id', 't.receipt_station_id')
            ->orderBy('t.id', 'asc')
            ->get();
    }

    // fetchPending: GET .../pos/mobile-order/get_pending/:branch_id ke APIANDORDER, token branch
    // yang sama dipakai buat /pos/sync/* & /pos/endday/* (lihat DayShiftServices::EndDay()).
    // Gagal fetch (network/ERP down) BUKAN exception -- balikin array kosong & dicatat log,
    // command yang manggil cukup skip cycle ini & coba lagi 10 detik kemudian (sama semangatnya
    // kayak resolveWorkerTerminal() gak ketemu -- retry loop, bukan crash).
    public function fetchPending(int $branchId, string $branchToken): array
    {
        $endpoint = env('SERVER_ENDPOINT');
        try {
            $response = Http::withToken($branchToken)->get($endpoint . "/pos/mobile-order/get_pending/{$branchId}");
        } catch (\Throwable $e) {
            Log::error("mobile-order:pull: gagal koneksi ke APIANDORDER (get_pending): {$e->getMessage()}");
            return [];
        }

        if ($response->json('code') !== 0) {
            Log::error('mobile-order:pull: get_pending gagal', ['response' => $response->json()]);
            return [];
        }

        return $response->json('data') ?? [];
    }

    // ackOrder: POST .../pos/mobile-order/ack/:order_number ke APIANDORDER -- WAJIB dipanggil
    // SETELAH processOrder() sukses (bukan sebelum), biar order yang gagal di-insert lokal
    // masih nongol lagi di get_pending cycle berikutnya. Idempotent di sisi ERP (lihat
    // mobileorder_service.go Ack()), jadi gagal kirim di sini (network putus pas response balik)
    // aman di-retry -- cukup dicatat log, gak perlu dibikin retry eksplisit di sini.
    public function ackOrder(string $branchToken, string $orderNumber): void
    {
        $endpoint = env('SERVER_ENDPOINT');
        try {
            $response = Http::withToken($branchToken)->post($endpoint . "/pos/mobile-order/ack/{$orderNumber}");
        } catch (\Throwable $e) {
            Log::error("mobile-order:pull: gagal koneksi ke APIANDORDER (ack) order {$orderNumber}: {$e->getMessage()}");
            return;
        }

        if ($response->json('code') !== 0) {
            Log::error('mobile-order:pull: ack gagal', ['order_number' => $orderNumber, 'response' => $response->json()]);
        }
    }

    // processOrder: 1 order hasil fetchPending() -> insert mb_order lokal (staging minimal) +
    // tr_order/tr_order_detail/tr_order_detail_package/tr_order_payment. $terminal adalah 1 baris
    // hasil resolveWorkerTerminal() (id, branch_id, table_section_id) -- SEMUA order yang ditarik
    // di 1 cycle pakai terminal & table_section yang SAMA (1 terminal worker = 1 table section
    // tetap, disepakati 2026-08-26 -- mobile order gak punya konsep pilih meja fisik).
    //
    // order_type diambil dari table_section.type (BUKAN dari payload order_type) -- table_section
    // itu yang jadi sumber kebenaran type di POS, payload order_type cuma referensi dari sisi
    // mobile, gak dipakai buat nulis tr_order.
    //
    // Kalau order ini SUDAH pernah punya baris mb_order lokal (guard di awal), berarti cycle
    // sebelumnya sukses insert tapi gagal ack ke ERP -- return normal (BUKAN exception) biar
    // caller tetap lanjut ackOrder(), bukan diulang insert-nya (dobel PK error).
    public function processOrder(array $order, object $terminal): void
    {
        if (DB::table('mb_order')->where('order_number', $order['order_number'])->exists()) {
            Log::warning('mobile-order:pull: order sudah pernah diproses lokal, tinggal di-ack ulang', [
                'order_number' => $order['order_number'],
            ]);
            return;
        }

        $tableSection = TableSectionModel::where('id', $terminal->table_section_id)->first();
        if (!$tableSection) {
            throw new \Exception("table_section_id {$terminal->table_section_id} (dari terminal worker) tidak ditemukan");
        }

        $dayshift = DaySiftModel::where('dayout_time', null)->first();
        if (!$dayshift) {
            throw new \Exception('belum ada dayshift aktif (dayin) di branch ini');
        }

        $dateNow = now()->toDateString();
        $lastOrder = TrOrderModel::where('order_date', $dateNow)->orderBy('order_queue', 'desc')->first();
        $orderQueue = $lastOrder ? $lastOrder->order_queue + 1 : 1;

        $totalItem = 0;
        foreach ($order['detail'] as $d) {
            $totalItem += $d['qty'];
        }

        DB::beginTransaction();
        try {
            DB::table('mb_order')->insert([
                'order_number' => $order['order_number'],
                'status' => 'paid',
                'flag_confirm' => false,
            ]);

            TrOrderModel::create([
                'order_number' => $order['order_number'],
                'payment_number' => $order['payment_number'],
                'dayshift_ulid' => $dayshift->ulid,
                'branch_id' => $terminal->branch_id,
                'terminal_id' => $terminal->id,
                // member_name di-JOIN live ke master_member di APIANDORDER (get_pending),
                // BUKAN denormalisasi ke mb_order -- selalu fresh, gak gantung POS udah sinkron
                // member itu apa belum. Kosong string kalau order dari guest/member ke-hapus.
                'order_name' => $order['member_name'] ?? '',
                'customer_phone_number' => $order['customer_phone_number'] ?? null,
                'order_source' => 'mobile',
                'order_type' => $tableSection->type,
                'table_section_id' => $tableSection->id,
                'table_id' => null,
                'total_batch' => 1,
                'order_date' => $dateNow,
                'order_queue' => $orderQueue,
                'order_in' => now(),
                'order_out' => now(),
                'member_id' => $order['member_id'] ?? null,
                'visit_purpose_id' => $order['visit_purpose_id'],
                'pax' => $order['pax'] ?? 1,
                'status' => 'paid',
                'delivery_cost' => $order['delivery_cost'] ?? 0,
                'order_fee' => $order['order_fee'] ?? 0,
                'service_charge' => $order['service_charge'] ?? 0,
                'platform_fee' => $order['platform_fee'] ?? 0,
                'total_item' => $totalItem,
                'sub_total' => $order['sub_total'],
                'total_discount' => $order['total_discount'] ?? 0,
                'total_tax' => $order['total_tax'],
                'total_billing' => $order['total_billing'],
                'flag_inclusive_tax' => $order['flag_inclusive_tax'] ?? false,
                'payment_at' => $order['payment_at'] ?? now(),
                'payment_notes' => $order['payment_notes'] ?? null,
            ]);

            $orderDetailRows = [];
            $orderDetailPackageRows = [];
            foreach ($order['detail'] as $d) {
                $ulid = (string) Str::ulid();
                $orderDetailRows[] = [
                    'ulid' => $ulid,
                    'order_number' => $order['order_number'],
                    'pricelist_detail_id' => $d['pricelist_detail_id'],
                    'menu_id' => $d['menu_id'],
                    'category_id' => $d['category_id'] ?? null,
                    'subcategory_id' => $d['subcategory_id'] ?? null,
                    'qty' => $d['qty'],
                    'flag_inclusive_tax' => $d['flag_inclusive_tax'],
                    'price_pos' => $d['price'],
                    'tax_id' => $d['tax_id'] ?? null,
                    'tax_type' => $d['tax_type'] ?? null,
                    'tax_rate' => $d['tax_rate'] ?? 0,
                    'tax_amount' => $d['tax_amount'] ?? 0,
                    'dpp' => $d['dpp'] ?? null,
                    'net_dpp' => $d['net_dpp'] ?? null,
                    'promo_id' => $d['promo_id'] ?? null,
                    'discount_percent' => $d['discount_percent'] ?? 0,
                    'discount_amount' => $d['discount_amount'] ?? 0,
                    'total' => $d['total'],
                    'notes' => $d['notes'] ?? null,
                    'batch' => 1,
                    'done_print' => false,
                ];

                foreach (($d['package'] ?? []) as $p) {
                    $orderDetailPackageRows[] = [
                        'ulid' => (string) Str::ulid(),
                        'tr_order_detail_ulid' => $ulid,
                        'menu_package_id' => $p['menu_package_id'],
                        'menu_id' => $p['menu_id'],
                        'category_id' => $p['category_id'] ?? null,
                        'subcategory_id' => $p['subcategory_id'] ?? null,
                        'qty' => $p['qty'],
                        'flag_inclusive_tax' => $p['flag_inclusive_tax'],
                        'price_pos' => $p['price'],
                        'tax_id' => $p['tax_id'] ?? null,
                        'tax_type' => $p['tax_type'] ?? null,
                        'tax_rate' => $p['tax_rate'] ?? 0,
                        'tax_amount' => $p['tax_amount'] ?? 0,
                        'dpp' => $p['dpp'] ?? null,
                        'net_dpp' => $p['net_dpp'] ?? null,
                        'promo_id' => $p['promo_id'] ?? null,
                        'discount_percent' => $p['discount_percent'] ?? 0,
                        'discount_amount' => $p['discount_amount'] ?? 0,
                        'total' => $p['total'],
                        'notes' => $p['notes'] ?? null,
                    ];
                }
            }

            if (!empty($orderDetailRows)) {
                TrOrderDetailModel::insert($orderDetailRows);
            }
            if (!empty($orderDetailPackageRows)) {
                TrOrderDetailPackageModel::insert($orderDetailPackageRows);
            }

            if (!empty($order['payment'])) {
                TrOrderPaymentModel::insert([
                    'ulid' => (string) Str::ulid(),
                    'payment_number' => $order['payment_number'],
                    'payment_method_id' => $order['payment']['payment_method_id'],
                    'payment_gateway_order_id' => $order['payment']['payment_gateway_order_id'] ?? null,
                    'payment_amount' => $order['payment']['payment_amount'],
                    'voucher_code' => $order['payment']['voucher_code'] ?? null,
                ]);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        // Print DI LUAR transaksi -- printer offline gak boleh nge-rollback order yang udah
        // valid & sah dibayar (sama semangatnya kayak SaveOrder()/SavePayment()).
        //
        // SETIAP print call try/catch SENDIRI-SENDIRI (2026-08-27, fix) -- sebelumnya ke-4
        // panggilan ini digabung dalam 1 try/catch, jadi kalau salah SATU gagal (mis. printer
        // stasiun kitchen offline/kertas abis), SISANYA (termasuk PrintPayment -- struk buat
        // customer, yang paling penting buat order yang udah dibayar) ikut kelewat, gak pernah
        // dicoba sama sekali. Sekarang 1 printer bermasalah gak nge-block printer lain yang
        // masih sehat.
        $this->printOne($order['order_number'], 'table_checker', fn() => PrintServices::PrintTableChecker2($tableSection->id, $order['order_number']));
        $this->printOne($order['order_number'], 'main_checker', fn() => PrintServices::PrintMainChecker2($tableSection->id, $order['order_number']));
        $this->printOne($order['order_number'], 'preparation_station', fn() => PrintServices::PrintPriparationStation($tableSection->id, $order['order_number']));
        $this->printOne($order['order_number'], 'payment', fn() => PrintServices::PrintPayment($order['order_number']));

        TrOrderDetailModel::where('order_number', $order['order_number'])->update(['done_print' => true]);
    }

    // printOne: 1 panggilan print, gagal-nya SENDIRI (gak nular ke print lain di batch yang
    // sama) -- lihat catatan di processOrder().
    private function printOne(string $orderNumber, string $label, callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::error("mobile-order:pull: gagal print [{$label}] order {$orderNumber}: {$e->getMessage()}");
        }
    }
}
