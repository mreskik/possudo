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
    // save adalah endpoint tunggal untuk semua tab di halaman Setting — body dipisah per
    // section (general_setting, kiosk, dst nanti), tiap section opsional, cuma yang
    // dikirim aja yang diproses.
    public function save(Request $request)
    {
        try {
            $messages = [];

            if ($request->has('general_setting')) {
                $messages[] = SettingServices::Save($request->input('general_setting'));
            }

            if ($request->has('kiosk.terminal')) {
                $messages[] = SettingServices::SaveKioskTerminal($request->input('kiosk.terminal'));
            }

            if (count($messages) === 0) {
                return response()->json([
                    'code' => 100,
                    'message' => 'Tidak ada section yang dikirim (general_setting/kiosk).',
                ]);
            }

            return response()->json([
                'code' => 0,
                "message" => "Success"
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
