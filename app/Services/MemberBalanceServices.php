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
// pegang/percaya member_id sama sekali. payment_gateway_code tetap di-resolve di sini dari
// mr_payment_method lokal (itu emang cuma ada di DB lokal POS), sama pola kayak
// PaymentGatewayServices::RequestPayment().
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

    $paymentMethod = DB::table('mr_payment_method')->where('id', $payment_method_id)->first();
    if (!$paymentMethod) {
      throw new \Exception('payment method tidak ditemukan');
    }
    if (empty($paymentMethod->payment_gateway_code)) {
      throw new \Exception('payment method tidak didukung');
    }
    $paymentGatewayCode = $paymentMethod->payment_gateway_code;

    $branch = BranchModel::first();
    if (!$branch) {
      throw new \Exception('branch belum dipilih/disimpan, lakukan setup dulu');
    }

    $response = Http::withToken($branch->token)
      ->post($this->endpoint . '/pos/member-topup/' . $branch->id, [
        'phone_number' => $phone_number,
        'amount' => $amount,
        'source' => $source,
        'payment_gateway_code' => $paymentGatewayCode,
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
      ->get($this->endpoint . '/pos/member-topup/' . $branch->id . '/check-status/' . $reference_number);

    if ($response->json('code') !== 0) {
      throw new \Exception($response->json('message'));
    }

    return $response->json('data');
  }
}
