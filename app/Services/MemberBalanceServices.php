<?php

namespace App\Services;

use App\Models\BranchModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

// MemberBalanceServices: wrapper topup saldo member dari Kiosk (nyusul: POS/Mobile) ke
// APIANDORDER (backend/modules/apipos/membertopup). Kiosk cuma pegang phone_number +
// payment_method_id (konsep lokal POS) -- phone_number diterusin APA ADANYA ke APIANDORDER,
// resolve ke member_id dilakuin DI SANA (konek langsung ke master_member), BUKAN di Laravel --
// biar cuma 1 request per aksi (bukan by-phone dulu baru topup terpisah), dan Laravel gak perlu
// pegang/percaya member_id sama sekali.
//
// payment_method_id (2026-09-22, migration 221 sudocore2) DITERUSKAN APA ADANYA ke APIANDORDER --
// SEBELUMNYA cuma payment_gateway_code hasil resolve lokal yang dikirim (payment_method_id
// dibuang di sini), TERNYATA BUG: payment_gateway_code gak unik (gak ada constraint di
// master_payment_method), memberbalancejurnal.resolveTopupSourceCoa() bisa salah akun COA kalau
// ada >1 payment method beda pakai kode gateway yang sama. mr_payment_method.id lokal itu = SAMA
// PERSIS master_payment_method.id pusat (mr_payment_method di-sync PULL, id dipertahankan apa
// adanya, BUKAN auto_increment lokal) -- jadi aman diteruskan langsung. payment_gateway_code
// TETAP di-resolve+divalidasi di sini (fail-fast di Kiosk, gak perlu round-trip network buat tau
// "payment method tidak didukung"), tapi APIANDORDER SEKARANG resolve ulang sendiri dari
// payment_method_id (server-side, gak percaya kode dari body lagi) -- validasi di sini murni UX,
// bukan satu-satunya lapisan.
class MemberBalanceServices
{
  protected string $endpoint;

  public function __construct()
  {
    $this->endpoint = config('services.server_endpoint', '');
  }

  // TopupBalance: amount wajib > 0. payment_method_id WAJIB dan wajib py payment_gateway_code
  // keisi -- SAMA PERSIS validasi RequestPayment() (gak ada fallback diam-diam ke tunai kalau
  // gateway_code-nya kosong, langsung ditolak). terminal_id diterusin apa adanya ke APIANDORDER
  // (disimpen ke member_topup_online.terminal_id + member_balance_ledger.terminal_id di sana,
  // di-echo balik pas CheckTopupStatus buat resolve receipt_station pas print struk).
  public function TopupBalance(string $phone_number, float $amount, ?int $payment_method_id, string $source, ?int $terminal_id): array
  {
    if ($amount <= 0) {
      throw new \Exception('amount wajib lebih dari 0');
    }
    if (!$payment_method_id) {
      throw new \Exception('payment_method_id wajib diisi');
    }

    // Fail-fast lokal (UX doang) -- APIANDORDER resolve ulang payment_gateway_code SENDIRI dari
    // payment_method_id (server-side), validasi di sini bukan satu-satunya lapisan.
    $paymentMethod = DB::table('mr_payment_method')->where('id', $payment_method_id)->first();
    if (!$paymentMethod) {
      throw new \Exception('payment method tidak ditemukan');
    }
    if (empty($paymentMethod->payment_gateway_code)) {
      throw new \Exception('payment method tidak didukung');
    }

    $branch = BranchModel::first();
    if (!$branch) {
      throw new \Exception('branch belum dipilih/disimpan, lakukan setup dulu');
    }

    $response = Http::withToken($branch->token)
      ->withOptions(['verify' => config('services.http_verify_ssl')])
      ->post($this->endpoint . '/pos/member-topup/' . $branch->id, [
        'phone_number' => $phone_number,
        'amount' => $amount,
        'source' => $source,
        'payment_method_id' => $payment_method_id,
        'terminal_id' => $terminal_id,
      ]);

    if ($response->json('code') !== 0) {
      throw new \Exception($response->json('message'));
    }

    return $response->json('data');
  }

  // CheckTopupStatus: polling status topup gateway -- dipanggil sambil nunggu customer scan QR.
  public function CheckTopupStatus(string $reference_number): array
  {
    $branch = BranchModel::first();
    if (!$branch) {
      throw new \Exception('branch belum dipilih/disimpan, lakukan setup dulu');
    }

    $response = Http::withToken($branch->token)
      ->withOptions(['verify' => config('services.http_verify_ssl')])
      ->get($this->endpoint . '/pos/member-topup/' . $branch->id . '/check-status/' . $reference_number);

    if ($response->json('code') !== 0) {
      throw new \Exception($response->json('message'));
    }

    return $response->json('data');
  }
}
