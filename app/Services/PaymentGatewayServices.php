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

    // order yang udah di-cancel (lihat KioskController::CancelOrder()) itu tindakan final --
    // gak boleh diminta payment lagi lewat sini. Beda dari 'expired' (pasif, boleh di-retry).
    if ($order->status === 'cancel') {
      throw new \Exception('order sudah dibatalkan, tidak bisa request payment lagi');
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

    // snapshot expired_at dari vendor -- dipakai buat nampilin payment_expired_at di
    // KIOSK ORDER HISTORY.md / KIOSK ORDER DETAIL.md (biar Kiosk tau kapan QR yang lagi aktif
    // itu mati, tanpa perlu ke service payment lagi).
    $requestRow->update(['expired_at' => $response->json('data.expired_at')]);

    // order yang attempt sebelumnya expired (QR kadaluarsa) -- attempt baru ini valid, jangan
    // biarin order-nya nyangkut status 'expired' padahal QR baru aktif. Guard status = 'expired'
    // doang -- order yang di-cancel lewat KioskController::CancelOrder() sengaja gak ke-reset
    // di sini, itu tindakan eksplisit, beda kasus dari expired yang sifatnya pasif.
    TrOrderModel::where('order_number', $order_number)
      ->where('status', 'expired')
      ->update(['status' => 'pending']);

    return $response->json('data');
  }

  // CheckStatus: dipanggil Kiosk buat polling -- cuma butuh order_number, gak perlu tau
  // order_id (bisa beda-beda tiap attempt gara-gara retry, server yang cari attempt terbaru
  // sendiri). Idempotency guard duluan (cek tr_order.payment_number) biar polling berkali-kali
  // gak dobel proses SavePayment().
  //
  // Status yang DIBALIKIN ke client (pending/paid/cancel/failed/expired) sengaja beda dari
  // status internal gateway (pending/settlement/cancel/failed/expired, "settlement" istilah
  // Midtrans) -- "settlement" di-remap jadi "paid" biar konsisten sama tr_order.status. Kolom
  // tr_kiosk_payment_request.status TETEP nyimpen "settlement" apa adanya (audit trail attempt,
  // harus persis sama payment_gateway di service payment), remap-nya cuma di response ini.
  public function CheckStatus(string $order_number): array
  {
    $order = TrOrderModel::where('order_number', $order_number)->first();
    if (!$order) {
      throw new \Exception('order tidak ditemukan');
    }

    // udah pernah ke-confirm sebelumnya (attempt lain/polling sebelumnya) -- jangan cek ulang
    // ke Midtrans lagi, apalagi sampe manggil SavePayment() dobel.
    if ($order->payment_number !== null) {
      return ['status' => 'paid', 'order_number' => $order_number];
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

    // order yang QR-nya kadaluarsa di Midtrans (gak pernah di-scan) -- samain tr_order.status
    // jadi 'expired' juga, biar gak nyangkut 'pending' selamanya. Guard status = 'pending' di
    // where() ini murni jaga-jaga (idempotency guard di atas udah nutup kasus order paid duluan).
    if ($status === 'expired') {
      TrOrderModel::where('order_number', $order_number)
        ->where('status', 'pending')
        ->update(['status' => 'expired']);
    }

    return ['status' => $status === 'settlement' ? 'paid' : $status, 'order_number' => $order_number];
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

  // CancelPendingAttempt: dipanggil dari KioskController::CancelOrder(), editExistingOrder(),
  // dan endpoint publik KioskController::CancelPayment() -- kalau order yang mau di-cancel/edit
  // masih punya attempt payment 'pending', attempt itu perlu diinvalidate biar gak nyangkut QR
  // aktif yang gak relevan lagi.
  //
  // LIVE-CHECK dulu ke Midtrans sebelum mutusin cancel (bukan blind-cancel) -- jaga-jaga race:
  // customer bisa aja sempet scan & bayar QR itu PERSIS pas mau di-cancel (sistem ini polling,
  // bukan webhook, jadi ada window dimana Midtrans udah settlement tapi Laravel belum tau).
  // Kalau blind-cancel aja terus caller lanjut ubah order (edit-order) atau nge-cancel order
  // (cancel-order), duit yang UDAH beneran kebayar itu bisa ke-orphan -- order nyangkut gak
  // sinkron sama status pembayaran aslinya. Jadi:
  // - Attempt masih pending/udah expired/cancel/failed di Midtrans -> aman, cancel (kalau
  //   masih pending) / sinkronin status lokal, return ['cancelled' => true, 'settled' => false].
  // - Attempt ternyata UDAH settlement (race) -> JANGAN di-cancel, proses confirmPayment()
  //   malah (order jadi 'paid', bener-bener kebayar) -- caller WAJIB cek 'settled' di return
  //   value dan batalin aksi yang lagi dilakuin (edit/cancel) kalau true.
  // - Gak ada attempt pending sama sekali -> no-op, ['cancelled' => false, 'settled' => false].
  public function CancelPendingAttempt(string $order_number): array
  {
    $pendingAttempt = KioskPaymentRequestModel::where('order_number', $order_number)
      ->where('status', 'pending')
      ->orderByDesc('created_at')
      ->first();

    if (!$pendingAttempt) {
      return ['cancelled' => false, 'settled' => false];
    }

    $response = Http::get($this->endpoint . '/payment-gateway/' . $pendingAttempt->order_id);
    $liveStatus = $response->json('code') === 0 ? $response->json('data.status') : null;

    if ($liveStatus === 'settlement') {
      $pendingAttempt->update(['status' => 'settlement']);

      $order = TrOrderModel::where('order_number', $order_number)->first();
      if ($order && $order->payment_number === null) {
        $this->confirmPayment($order, $pendingAttempt);
      }

      return ['cancelled' => false, 'settled' => true];
    }

    if ($liveStatus === 'pending' || $liveStatus === null) {
      // masih pending beneran (atau live-check-nya sendiri gagal, mis. network) -- coba cancel.
      $this->cancelAttempt($pendingAttempt);
    } else {
      // udah expired/cancel/failed duluan di Midtrans (bukan gara-gara kita) -- gak perlu
      // manggil cancel lagi (Midtrans bakal nolak, transaksinya emang udah mati), sinkronin
      // status lokal aja.
      $pendingAttempt->update(['status' => $liveStatus]);
    }

    return ['cancelled' => true, 'settled' => false];
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
