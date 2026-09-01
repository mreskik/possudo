<?php

namespace App\Http\Controllers;

use App\Services\PaymentGatewayServices;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// PaymentGatewayController: endpoint payment gateway (QRIS dst) buat device KASIR (POS) --
// pola SAMA kayak KioskController::RequestPayment()/CheckPaymentStatus()/CancelPayment(), reuse
// App\Services\PaymentGatewayServices yang SAMA PERSIS (service-nya emang generic dari awal,
// tabel tr_kiosk_payment_request juga gak ada kolom yang Kiosk-specific). Dipisah dari
// KioskController biar penamaan endpoint jelas ini jalur kasir, bukan Kiosk self-service --
// beda dari Kiosk, di sini `amount` WAJIB dikirim client (dukung split-payment: kasir bisa
// minta QRIS cuma buat SISA outstanding, bukan total tagihan penuh). Disepakati 2026-08-31,
// lihat DOKUMENTASI kalau ada, atau riwayat percakapan soal alur "Request Payment" di
// paymentPage.vue (posv1-vue).
class PaymentGatewayController extends Controller
{
    public function RequestPayment(Request $request)
    {
        try {
            $order_number = $request->input('order_number');
            $payment_method_id = $request->input('payment_method_id');
            $amount = $request->input('amount');

            if (!$order_number || !$payment_method_id || !$amount) {
                return response()->json([
                    'code' => 100,
                    'message' => 'order_number, payment_method_id, dan amount wajib diisi',
                ]);
            }

            $data = (new PaymentGatewayServices())->RequestPayment(
                $order_number,
                (int) $payment_method_id,
                (int) $amount,
            );

            return response()->json([
                'code' => 0,
                'data' => $data,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'code' => 100,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function CheckStatus(Request $request, string $order_number)
    {
        try {
            $data = (new PaymentGatewayServices())->CheckStatus($order_number);

            return response()->json([
                'code' => 0,
                'data' => $data,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'code' => 100,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function CancelPayment(Request $request)
    {
        try {
            $order_number = $request->input('order_number');
            if (!$order_number) {
                return response()->json([
                    'code' => 100,
                    'message' => 'order_number wajib diisi',
                ]);
            }

            $order = DB::table('tr_order')->where('order_number', $order_number)->first();
            if (!$order) {
                return response()->json([
                    'code' => 100,
                    'message' => 'order tidak ditemukan',
                ]);
            }

            // Guard pakai payment_number (bukan status='pending' kayak KioskController::CancelPayment())
            // -- itu invariant yang SAMA dipakai PaymentServices::SavePayment() buat cek "udah
            // dibayar apa belum", lebih pas sama semantik order POS (order POS gak selalu
            // berstatus 'pending' sebelum dibayar kayak Kiosk).
            if ($order->payment_number !== null) {
                return response()->json([
                    'code' => 100,
                    'message' => 'order sudah dibayar, tidak bisa cancel payment request',
                ]);
            }

            $data = (new PaymentGatewayServices())->CancelPendingAttempt($order_number);

            return response()->json([
                'code' => 0,
                'data' => $data,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'code' => 100,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
