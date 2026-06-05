<?php

namespace App\Http\Controllers;

use App\Models\TrOrderModel;
use App\Services\OrderServices;
use App\Services\PrintServices;
use App\Services\SalesServices;
use Illuminate\Http\Request;
use PhpParser\Node\Expr\FuncCall;

class SalesController extends Controller
{
    //

    public function GetSalesList(Request $request)
    {
        try {

            $data_sales = SalesServices::GetSalesList();
            return response()->json([
                "code" => 0,
                "data" => $data_sales
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                "code" => 100,
                "message" => $e->getMessage()
            ]);
        }
    }

    public function ViewSales(Request $request)
    {
        try {
            $order_number = $request->order_number;
            $data_sales = SalesServices::viewSales($order_number);

            return response()->json([
                "code" => 0,
                "data" => $data_sales
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                "code" => 100,
                "message" => $e->getMessage()
            ]);
        }
    }

    public function Reprint(Request $request)
    {
        try {

            $order_number = $request->order_number;
            $dataorder = TrOrderModel::where("order_number", $order_number)->first();
            if ($dataorder->status == 'cancel') {
                PrintServices::PrintBill($order_number);
            } else if ($dataorder->status == 'paid' || $dataorder->status == 'void') {
                PrintServices::PrintPayment($order_number);
            }

            return response()->json([
                "code" => 0,
                "data" => "done brow!"
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                "code" => 100,
                "message" => $e->getMessage()
            ]);
        }
    }

    public function Void(Request $request)
    {
        try {

            $order_number = $request->input('order_number');
            $notes = $request->input('notes');

            $message = SalesServices::Void($order_number, $notes);

            return response()->json([
                "code" => 0,
                "message" => $message
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                "code" => 100,
                "message" => $e->getMessage()
            ]);
        }
    }
}
