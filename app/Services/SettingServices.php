<?php

namespace App\Services;

use App\Models\SettingModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SettingServices
{
  public static function Save(Request $request)
  {
    try {
      Log::info($request);
      $default_station = $request->input('defaultStation');
      $use_customer_display = $request->input('useCustomerDisplay');
      $customer_display_name = $request->input('customerDisplay.name');
      $customer_display_left = $request->input('customerDisplay.left');
      $customer_display_top = $request->input('customerDisplay.top');

      SettingModel::where('id', 1)->update([
        'default_station' => $default_station,
        'use_customer_display' => $use_customer_display,
        'customer_display_name' => $customer_display_name,
        'customer_display_left' => $customer_display_left,
        'customer_display_top' => $customer_display_top,
      ]);

      return "Success";
    } catch (\Throwable $e) {
      throw $e;
    }
  }
}
