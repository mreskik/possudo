<?php

namespace App\Http\Controllers;

use App\Services\OrderServices;
use App\Services\PrintServices;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class OrderController extends Controller
{

    public function index()
    {
        //
        return response()->json([
            "data" => "ok",
            "message" => "ok",
            "status" => 1,
        ]);
    }

    public function saveOrder(Request $request)
    {
        //
        try {
            $order_number = OrderServices::SaveOrder($request);
            return response()->json([
                'code' => 0,
                'data' => [
                    "orderNumber" => $order_number
                ],
            ]);
        } catch (\Throwable $e) {
            Log::info($e->getMessage());
            return response()->json([
                'code' => 100,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function cancelOrder(Request $request)
    {
        //
        try {
            $order_number = $request->input("order_number");
            $notes = $request->input("notes");

            $message = OrderServices::CancelOrder($order_number, $notes);

            PrintServices::PrintBill($order_number);
            return response()->json([
                'code' => 0,
                'message' => $message
            ]);
        } catch (\Throwable $e) {
            Log::info($e->getMessage());
            return response()->json([
                'code' => 100,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function viewOrder(Request $request)
    {
        try {
            $order_number = $request->route('order_number');
            $data = OrderServices::ViewOrder($order_number);
            return response()->json([
                'code' => 0,
                'data' => $data,
            ]);
        } catch (\Throwable $e) {
            Log::info($e->getMessage());
            return response()->json([
                'code' => 100,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function onlyViewOrder(Request $request)
    {
        try {
            $order_number = $request->route('order_number');
            $data = OrderServices::onlyViewOrder($order_number);

            return response()->json([
                'code' => 0,
                'data' => $data,
            ]);
        } catch (\Throwable $e) {
            Log::info($e->getMessage());
            return response()->json([
                'code' => 100,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function PrintBill(Request $request)
    {
        // $table_section = $request->table_section;
        $order = $request->order;
        // if ($table_section == null || $order == null) {
        //     return 'error';
        // }
        PrintServices::PrintBill($order);
        return '';
    }
}
