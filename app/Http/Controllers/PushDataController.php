<?php

namespace App\Http\Controllers;

use App\Services\PushDataServices;
use Illuminate\Http\Request;

class PushDataController extends Controller
{
    //
    function PushDataOrder(Request $request)
    {


        try {
            $services = new PushDataServices;

            $result = $services->pushDataOrder();

            return response()->json([
                "code" => 0,
                "data" => $result
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                "code" => 100,
                "message" => $e->getMessage()
            ]);
        }
    }

    function PushDataOrderDetail(Request $request)
    {


        try {
            $services = new PushDataServices;

            $result = $services->pushDataOrderDetail();

            return response()->json([
                "code" => 0,
                "data" => $result
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                "code" => 100,
                "message" => $e->getMessage()
            ]);
        }
    }

    function PushDataOrderDetailPackage(Request $request)
    {


        try {
            $services = new PushDataServices;

            $result = $services->pushDataOrderDetailPackage();

            return response()->json([
                "code" => 0,
                "data" => $result
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                "code" => 100,
                "message" => $e->getMessage()
            ]);
        }
    }

    function PushDataOrderPayment(Request $request)
    {


        try {
            $services = new PushDataServices;

            $result = $services->PushDataOrderPayment();

            return response()->json([
                "code" => 0,
                "data" => $result
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                "code" => 100,
                "message" => $e->getMessage()
            ]);
        }
    }

    function PushDataDayShift(Request $request)
    {
        try {
            $services = new PushDataServices;

            $result = $services->pushDataDayShift();

            return response()->json([
                "code" => 0,
                "data" => $result
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                "code" => 100,
                "message" => $e->getMessage()
            ]);
        }
    }

    function PushDataDayShiftDetail(Request $request)
    {
        try {
            $services = new PushDataServices;

            $result = $services->pushDataDayShiftDetail();

            return response()->json([
                "code" => 0,
                "data" => $result
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                "code" => 100,
                "message" => $e->getMessage()
            ]);
        }
    }
}
