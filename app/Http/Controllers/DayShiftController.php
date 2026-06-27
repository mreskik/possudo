<?php

namespace App\Http\Controllers;

use App\Models\DayShiftDetailModel;
use App\Models\TrOrderModel;
use App\Services\DayShiftServices;
use App\Services\PrintServices;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DayShiftController extends Controller
{
    //
    function CurrentDay(Request $request)
    {
        try {
            $data = DayShiftServices::GetDayShift();
            return response()->json([
                "code" => 0,
                "data" => $data
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                "code" => 0,
                "data" => $e->getMessage()
            ]);
        }
    }

    function StartDay(Request $request)
    {
        try {
            $start_cash = $request->input("start_cash");
            //belum filter

            $data = DayShiftServices::StartDay($start_cash);

            return response()->json([
                "code" => 0,
                "message" => $data
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                "code" => 0,
                "message" => $e->getMessage()
            ]);
        }
    }

    function Endshift(Request $request)
    {
        try {

            $dayshift_ulid = $request->dayshift_ulid;
            //belum filter
            $data = DayShiftServices::EndShift($dayshift_ulid);
            return response()->json([
                "code" => 0,
                "message" => $data
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                "code" => 0,
                "message" => $e->getMessage()
            ]);
        }
    }
    function EndDay(Request $request)
    {
        try {
            $result = DayShiftServices::EndDay($request);

            return response()->json([
                "code" => 0,
                "message" => $result
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                "code" => 100,
                "message" => $e->getMessage()
            ]);
        }
    }

    function ListDayShiftDetail(Request $request)
    {
        try {
            $dayshift_ulid = $request->dayshift_ulid;
            $data = DayShiftDetailModel::where('dayshift_ulid', $dayshift_ulid)->get();

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

    function ReportAll(Request $request)
    {
        try {
            $data = DayShiftServices::GetReportAll();

            return response()->json([
                "code" => 0,
                "data" => $data
            ]);
        } catch (\Throwable $e) {
            Log::info($e);
            return response()->json([
                "code" => 100,
                "message" => $e->getMessage()
            ]);
        }
    }

    function Report(Request $request)
    {
        try {

            $data = DayShiftServices::GetReport($request->dayshift_ulid);

            return response()->json([
                "code" => 0,
                "data" => $data
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                "code" => 0,
                "message" => $e->getMessage()
            ]);
        }
    }

    function DayShiftList(Request $request)
    {
        try {
            $dayshiftlist = DayShiftServices::GetDayshiftList();

            return response()->json([
                "code" => 0,
                "data" => $dayshiftlist
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                "code" => 100,
                "message" => $e->getMessage()
            ]);
        }
    }

    function printReport(Request $request)
    {
        try {
            $dayshift_ulid = $request->dayshift_ulid;
            PrintServices::PrintReport($dayshift_ulid);
            return response()->json([
                "code" => 0,
                "message" => "ashiappp"
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                "code" => 100,
                "message" => $e->getMessage()
            ]);
        }
    }

    function printReportByShift(Request $request)
    {
        try {
            $dayshift_detail_id = $request->dayshift_detail_id;
            PrintServices::PrintReport($dayshift_detail_id, true);
            return response()->json([
                "code" => 0,
                "message" => "ashiappp"
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                "code" => 100,
                "message" => $e->getMessage()
            ]);
        }
    }

    function pendingOrder()
    {
        try {
            $data = TrOrderModel::where('status', 'pending')->get();

            return response()->json([
                'code' => 0,
                'data' => $data
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                "code" => 100,
                "message" => $e->getMessage()
            ]);
        }
    }
}
