<?php

namespace App\Services;

use App\Models\BranchModel;
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

    // baru MIDTRANS_QRIS yang ada endpoint-nya di service payment -- payment_gateway_code lain
    // nanti nyusul rutenya kalau providernya udah dibikin.
    $response = Http::post($this->endpoint . '/payment-gateway/qris', [
      'order_id' => $order->order_number,
      'payment_gateway_code' => $paymentMethod->payment_gateway_code,
      'amount' => (int) $order->total_billing,
      'branch_id' => $branch->id,
      'company_id' => $branch->company_id,
    ]);

    if ($response->json('code') !== 0) {
      throw new \Exception($response->json('message'));
    }

    return $response->json('data');
  }
}
