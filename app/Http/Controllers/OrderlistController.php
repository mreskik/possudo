<?php

namespace App\Http\Controllers;

use App\Services\OrderlistServices;
use Illuminate\Http\Request;

class OrderlistController extends Controller
{
    //
    public function getOrderlistTakaway(Request $request)
    {
        try {
            $takawaylist = OrderlistServices::getOrderlistTakaway();
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
