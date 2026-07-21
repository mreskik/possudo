<?php

namespace App\Http\Controllers;

use App\Models\SettingModel;
use App\Models\StationModel;
use App\Services\PrintServices;
use App\Services\SettingServices;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    //
    public function save(Request $request)
    {
        try {

            $message = SettingServices::Save($request);

            return response()->json([
                'code' => 0,
                "message" => $message
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'code' => 100,
                "message" => $e->getMessage()
            ]);
        }
    }

    public function load(Request $request)
    {
        try {
            $data = SettingModel::first();

            return response()->json([
                'code' => 0,
                "data" => $data,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'code' => 100,
                "message" => $e->getMessage()
            ]);
        }
    }

    public function testPrintAll()
    {
        try {
            $stations = StationModel::all();
            $results = [];
            foreach ($stations as $station) {
                $msg = PrintServices::PrintTest($station->id);
                $results[] = [
                    'station_id'   => $station->id,
                    'station_name' => $station->name,
                    'printer_name' => $station->printer_name,
                    'result'       => $msg,
                ];
            }
            return response()->json(['code' => 0, 'data' => $results]);
        } catch (\Throwable $e) {
            return response()->json(['code' => 100, 'message' => $e->getMessage()]);
        }
    }

    public function testPrint(int $station_id)
    {
        try {
            $result = PrintServices::PrintTest($station_id);
            $code = $result === 'OK' ? 0 : 100;
            return response()->json(['code' => $code, 'message' => $result]);
        } catch (\Throwable $e) {
            return response()->json(['code' => 100, 'message' => $e->getMessage()]);
        }
    }

    public function customer_display(Request $request)
    {
        $setting = SettingModel::first();

        if ($setting->use_customer_display) {
            //  exec("C:\Users\LENOVO\AppData\Local\Chromium\Application\chrome.exe --start-fullscreen --user-data-dir=C:\POS --window-position=$setting->customer_display_left,$setting->customer_display_top --new-window http://localhost:5173/display_customer");

            exec("chrome.exe --new-window http://localhost:5173/display_customer");
        }

        return response('');
    }
}
