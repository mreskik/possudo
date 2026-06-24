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
}
