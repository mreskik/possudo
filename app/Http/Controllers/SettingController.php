<?php

namespace App\Http\Controllers;

use App\Models\SettingModel;
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

    public function customer_display(Request $request)
    {
        $setting = SettingModel::first();

        if ($setting->use_customer_display) {
            //  exec("C:\Users\LENOVO\AppData\Local\Chromium\Application\chrome.exe --start-fullscreen --user-data-dir=C:\POS --window-position=$setting->customer_display_left,$setting->customer_display_top --new-window http://localhost:5173/display_customer");

            exec("C:\Users\LENOVO\AppData\Local\Chromium\Application\chrome.exe --new-window http://localhost:5173/display_customer");
        }

        return response('');
    }
}
