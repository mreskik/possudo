<?php

namespace App\Http\Controllers;

use App\Services\BranchMenuServices;
use Illuminate\Http\Request;
use Throwable;

class BranchMenuController extends Controller
{
    //
    function load()
    {
        try {

            $data = BranchMenuServices::load();

            return response()->json([
                "code" => 0,
                "data" => $data
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                "code" => 100,
                "message" => $e->getMessage()
            ]);
        }
    }

    function saveSoldout(Request $request)
    {
        try {

            $menuid = $request->input('menu_id');
            $flag = $request->input('flag_soldout');

            $response = BranchMenuServices::saveFlagSoldOut($menuid, $flag);

            return response()->json([
                "code" => 0,
                "message" => $response
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                "code" => 100,
                "message" => $e->getMessage()
            ]);
        }
    }

    function saveStokQty(Request $request)
    {
        try {

            $menuid = $request->input('menu_id');
            $qty = $request->input('stok_qty');

            $response = BranchMenuServices::saveStokQTY($menuid, $qty);

            return response()->json([
                "code" => 0,
                "message" => $response
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                "code" => 100,
                "message" => $e->getMessage()
            ]);
        }
    }
}
