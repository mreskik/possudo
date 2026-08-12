<?php

namespace App\Services;

use App\Models\BranchModel;
use App\Models\KioskPaymentRequestModel;
use App\Models\TrOrderModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class PaymentGatewayServices
{
  protected string $endpoint;

  public function __construct()
  {
    $this->endpoint = config('services.payment_gateway_endpoint', '');
  }

  // RequestPayment: validasi payment_method_id dulu (harus punya payment_gateway_code keisi,
  // sama kayak filter di KioskController::GetPaymentMethodList()) sebelum minta QR ke service
  // payment gateway (terpisah dari APIANDORDER, lihat dev/payment/). amount diambil dari
  // tr_order.total_billing (server-side), bukan dari client.
  //
  // Retry-aware: kalau order_number ini masih punya attempt 'pending' di tr_kiosk_payment_request
  // (mis. QR expired/customer minta ulang), attempt lama itu di-cancel dulu (ke Midtrans lewat
  // service payment, biar gak ada 2 QR pending nyangkut bareng), baru attempt baru dibuat dengan
  // order_id baru (order_number-2, -3, dst -- order_id itu PK di payment_gateway, gak boleh
  // dipakai ulang). tr_kiosk_payment_request nyimpen jejak tiap attempt (termasuk
  // payment_method_id-nya) buat dipakai lagi nanti pas payment_check_status confirm ke
  // PaymentServices::SavePayment().
  public function RequestPayment(string $order_number, int $payment_method_id): array
  {
    $paymentMethod = DB::table('mr_payment_method')->where('id', $payment_method_id)->first();
    if (!$paymentMethod) {
      throw new \Exception('payment method tidak ditemukan');
    }

    if (empty($paymentMethod->payment_gateway_code)) {
      throw new \Exception('payment method tidak didukung');
    }

    $order = TrOrderModel::where('order_number', $order_number)->first();
    if (!$order) {
      throw new \Exception('order tidak ditemukan');
    }

    $branch = BranchModel::first();
    $amount = (int) $order->total_billing;

    // cancel attempt lama yang masih pending buat order ini (kalau ada)
    $pendingAttempt = KioskPaymentRequestModel::where('order_number', $order_number)
      ->where('status', 'pending')
      ->first();
    if ($pendingAttempt) {
      $this->cancelAttempt($pendingAttempt);
    }

    // order_id attempt pertama = order_number apa adanya, retry ke-2/3/dst kena suffix --
    // order_id itu PK di payment_gateway (service payment), gak boleh dipakai ulang.
    $attemptCount = KioskPaymentRequestModel::where('order_number', $order_number)->count();
    $orderId = $attemptCount === 0 ? $order_number : $order_number . '-' . ($attemptCount + 1);

    $requestRow = KioskPaymentRequestModel::create([
      'order_id' => $orderId,
      'order_number' => $order_number,
      'payment_method_id' => $payment_method_id,
      'amount' => $amount,
      'status' => 'pending',
    ]);

    // baru MIDTRANS_QRIS yang ada endpoint-nya di service payment -- payment_gateway_code lain
    // nanti nyusul rutenya kalau providernya udah dibikin. company_id SENGAJA gak dikirim --
    // service payment resolve sendiri dari branch_id (lihat catatan di CreateQrisPayment),
    // company_id lokal POS bisa aja basi/gak akurat.
    $response = Http::post($this->endpoint . '/payment-gateway/qris', [
      'order_id' => $orderId,
      'payment_gateway_code' => $paymentMethod->payment_gateway_code,
      'amount' => $amount,
      'branch_id' => $branch->id,
    ]);

    if ($response->json('code') !== 0) {
      // gagal minta QR ke Midtrans -- attempt-nya gak jadi kepakai, jangan nyangkut sebagai
      // 'pending' palsu (bisa keblokir attempt berikutnya ngirim retry).
      $requestRow->update(['status' => 'failed']);
      throw new \Exception($response->json('message'));
    }

    return $response->json('data');
  }

  // CheckStatus: dipanggil Kiosk buat polling -- cuma butuh order_number, gak perlu tau
  // order_id (bisa beda-beda tiap attempt gara-gara retry, server yang cari attempt terbaru
  // sendiri). Idempotency guard duluan (cek tr_order.payment_number) biar polling berkali-kali
  // gak dobel proses SavePayment(). Balikin status apa adanya (pending/settlement/cancel/
  // failed/expired) -- Kiosk yang mutusin UI-nya.
  public function CheckStatus(string $order_number): array
  {
    $order = TrOrderModel::where('order_number', $order_number)->first();
    if (!$order) {
      throw new \Exception('order tidak ditemukan');
    }

    // udah pernah ke-confirm sebelumnya (attempt lain/polling sebelumnya) -- jangan cek ulang
    // ke Midtrans lagi, apalagi sampe manggil SavePayment() dobel.
    if ($order->payment_number !== null) {
      return ['status' => 'settlement', 'order_number' => $order_number];
    }

    $attempt = KioskPaymentRequestModel::where('order_number', $order_number)
      ->orderByDesc('created_at')
      ->first();
    if (!$attempt) {
      throw new \Exception('belum pernah ada request pembayaran buat order ini');
    }

    $response = Http::get($this->endpoint . '/payment-gateway/' . $attempt->order_id);
    if ($response->json('code') !== 0) {
      throw new \Exception($response->json('message'));
    }

    $status = $response->json('data.status');
    $attempt->update(['status' => $status]);

    if ($status === 'settlement') {
      $this->confirmPayment($order, $attempt);
    }

    return ['status' => $status, 'order_number' => $order_number];
  }

  // confirmPayment: manggil PaymentServices::SavePayment() yang SAMA dipakai POS -- payment_detail
  // di-construct dari data yang udah disimpen tr_kiosk_payment_request pas request dibuat
  // (payment_method_id, amount), bukan dari client. card_number/bank_name/verification_code/
  // account_name kosong (gak relevan buat QRIS).
  private function confirmPayment(TrOrderModel $order, KioskPaymentRequestModel $attempt): void
  {
    $fakeRequest = new \Illuminate\Http\Request();
    $fakeRequest->replace([
      'order_number' => $order->order_number,
      'member_id' => $order->member_id,
      'payment_detail' => [
        [
          'payment_method_id' => $attempt->payment_method_id,
          'payment_amount' => $attempt->amount,
          'card_number' => null,
          'bank_name' => null,
          'verification_code' => null,
          'account_name' => null,
        ],
      ],
    ]);

    $response = PaymentServices::SavePayment($fakeRequest);
    if (!$response->success) {
      throw new \Exception($response->message ?? 'gagal konfirmasi pembayaran');
    }

    // SavePayment() (shared logic sama POS) gak tau soal payment_gateway_order_id -- backfill
    // di sini abis sukses, biar tr_order_payment nyambung balik ke attempt spesifik mana yang
    // beneran kebayar (bisa ada beberapa order_id per order_number gara-gara retry).
    DB::table('tr_order_payment')
      ->where('payment_number', $response->paymentNumber)
      ->update(['payment_gateway_order_id' => $attempt->order_id]);
  }

  // cancelAttempt: batalin 1 attempt pending -- ke Midtrans (lewat service payment) dulu, baru
  // tandain lokal. Gagal cancel di Midtrans (mis. race sama settlement) sengaja gak dianggap
  // fatal di sini -- tetap lanjut ke attempt baru, biar customer gak keblokir cuma gara-gara
  // API cancel lagi bermasalah. Kalau attempt lama itu ternyata beneran udah settlement pas
  // giliran dicancel, itu bakal ketauan/ditangani di payment_check_status (baca status aslinya
  // dari sana, bukan dari sini).
  private function cancelAttempt(KioskPaymentRequestModel $attempt): void
  {
    try {
      Http::post($this->endpoint . '/payment-gateway/' . $attempt->order_id . '/cancel');
    } catch (\Throwable) {
      // network/timeout dkk -- diabaikan, lihat catatan di atas.
    }

    $attempt->update(['status' => 'cancel']);
  }
}
