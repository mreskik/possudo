<?php

namespace App\Http\Controllers;

use App\Services\OrderNotifServices;
use Illuminate\Http\Request;

// OrderNotifController: 1 API buat 2 sumber (mobile + kiosk) -- lihat OrderNotifServices buat
// detail kenapa digabung.
class OrderNotifController extends Controller
{
    // pendingNotif: GET /order-notif/pending -- dipolling frontend, dipakai buat spawn toast.
    public function pendingNotif()
    {
        try {
            $data = OrderNotifServices::getPendingNotif();
            return response()->json([
                "code" => 0,
                "data" => $data
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                "code" => 100,
                "data" => $e->getMessage()
            ]);
        }
    }

    // confirm: POST /order-notif/confirm { order_number } -- dipanggil pas toast di-dismiss.
    public function confirm(Request $request)
    {
        try {
            $orderNumber = $request->order_number;
            if (empty($orderNumber)) {
                return response()->json([
                    "code" => 100,
                    "data" => "order_number wajib diisi"
                ]);
            }

            OrderNotifServices::confirm($orderNumber);
            return response()->json([
                "code" => 0,
                "data" => "success"
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                "code" => 100,
                "data" => $e->getMessage()
            ]);
        }
    }
}
