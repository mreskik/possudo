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
}
