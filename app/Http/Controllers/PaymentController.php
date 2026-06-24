<?php

namespace App\Http\Controllers;

use App\Services\PaymentServices;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    //

    public function savePayment(Request $request)
    {
        try {

            $response = PaymentServices::SavePayment($request);

            if ($response->success) {
                return response()->json([
                    'code' => 0,
                    'data' => [
                        "paymentNumber" => $response->paymentNumber
                    ],
                ]);
            } else {
                return response()->json([
                    'code' => 100,
                    // 'data' => $response,
                    'message' => $response->message
                ]);
            }
        } catch (\Throwable $e) {

            return response()->json([
                'code' => 100,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function editPayment(Request $request)
    {
        try {

            $message = PaymentServices::EditPayment($request);

            return response()->json([
                'code' => 0,
                'message' => $message,
            ]);
        } catch (\Throwable $e) {

            return response()->json([
                'code' => 100,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function viewPayment(Request $request)
    {
        try {
            $order_number = $request->order_number;
            $data_payment = PaymentServices::ViewPayment($order_number);
            return response()->json([
                'code' => 0,
                'data' => $data_payment
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'code' => 100,
                'message' => $e->getMessage()
            ]);
        }
    }
}
