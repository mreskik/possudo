<?php

namespace App\Http\Controllers;

use App\Services\BestSellerServices;
use Illuminate\Http\Request;

class BestSellerController extends Controller
{
    public function getBestSellerMenu(Request $request)
    {
        try {
            $date_start = $request->date_start;
            $date_end = $request->date_end;
            $data = BestSellerServices::getBestSellerMenu($date_start, $date_end);
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

    public function getBestSellerMenuByVisitPurpose(Request $request, $visit_purpose_id)
    {
        try {
            $date_start = $request->date_start;
            $date_end = $request->date_end;
            $data = BestSellerServices::getBestSellerMenuByVisitPurpose($date_start, $date_end, $visit_purpose_id);
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

    public function getBestSellerMenuByTableSection(Request $request, $tablesection_id)
    {
        try {
            $date_start = $request->date_start;
            $date_end = $request->date_end;
            $data = BestSellerServices::getBestSellerMenuByTableSection($date_start, $date_end, $tablesection_id);
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
