<?php

namespace App\Http\Controllers;

use App\Services\OrderlistServices;
use Illuminate\Http\Request;

class OrderlistController extends Controller
{
    //
    public function getOrderList(Request $request)
    {
        try {
            $tablesection_id = $request->tablesection_id;
            $takawaylist = OrderlistServices::getOrderList($tablesection_id);
            return response()->json([
                "code" => 0,
                "data" => $takawaylist
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                "code" => 100,
                "data" => $e->getMessage()
            ]);
        }
    }

    // getOrderlistNew (2026-11-04): endpoint BARU terpisah (by-tablesection-new), TIDAK
    // menggantikan getOrderlist() di atas -- lihat catatan lengkap di
    // OrderlistServices::getOrderListNew().
    public function getOrderlistNew(Request $request)
    {
        try {
            $tablesection_id = $request->tablesection_id;
            $data = OrderlistServices::getOrderListNew($tablesection_id);
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

    public function getAllPendingOrders(Request $request)
    {
        try {
            $data = OrderlistServices::getAllPendingOrders();
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
}
