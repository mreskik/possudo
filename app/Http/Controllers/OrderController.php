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

    // public function viewOrderForPayment()

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

    public function ListTableBySection(Request $request)
    {
        try {
            $table_section_id = $request->tablesection_id;
            $list = OrderServices::listTableBySection($table_section_id);

            return response()->json([
                'code' => 0,
                'data' => $list
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'code' => 100,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function ListTableBySectionAll(Request $request)
    {
        try {
            $table_section_id = $request->tablesection_id;
            $list = OrderServices::listTableBySectionAll($table_section_id);

            return response()->json([
                'code' => 0,
                'data' => $list
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'code' => 100,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function SaveMoveTable(Request $request)
    {
        try {

            $order_number = $request->input("order_number");
            $tablesection_id = $request->input("tablesection_id");
            $table_id = $request->input("table_id");
            $message = OrderServices::SaveMoveTable($order_number, $tablesection_id, $table_id);

            return response()->json([
                'code' => 0,
                'message' => $message
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'code' => 100,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function SaveMoveItem(Request $request)
    {
        try {

            $order_number = $request->input("order_number");
            $visit_purpose_id = $request->input("visit_purpose_id");
            $tablesection_id = $request->input("tablesection_id");
            $table_id = $request->input("table_id");
            $list_item = $request->input("list_item");

            $message = OrderServices::SaveMoveItem($order_number, $visit_purpose_id, $tablesection_id, $table_id, $list_item);

            return response()->json([
                'code' => 0,
                'message' => $message
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'code' => 100,
                'message' => $e->getMessage()
            ]);
        }
    }
}
