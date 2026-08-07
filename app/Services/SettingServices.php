<?php

namespace App\Services;

use App\Models\SettingModel;
use App\Models\TerminalModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SettingServices
{
  // Save menyimpan general setting (mr_setting). $data adalah isi key "general_setting" dari body.
  public static function Save(array $data)
  {
    try {
      Log::info($data);
      $default_station = $data['defaultStation'] ?? null;
      $use_customer_display = $data['useCustomerDisplay'] ?? null;
      $customer_display_name = $data['customerDisplay']['name'] ?? null;
      $customer_display_left = $data['customerDisplay']['left'] ?? null;
      $customer_display_top = $data['customerDisplay']['top'] ?? null;
      $use_virtual_keyboard = $data['useVirtualKeyboard'] ?? null;

      SettingModel::where('id', 1)->update([
        'default_station' => $default_station,
        'use_customer_display' => $use_customer_display,
        'customer_display_name' => $customer_display_name,
        'customer_display_left' => $customer_display_left,
        'customer_display_top' => $customer_display_top,
        'use_virtual_keyboard' => $use_virtual_keyboard,
      ]);

      return "Success";
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  // SaveKioskTerminal update table_section_id & receipt_station_id untuk sekumpulan terminal
  // kiosk sekaligus. $terminals adalah isi key "kiosk.terminal" dari body.
  public static function SaveKioskTerminal(array $terminals)
  {
    // array kosong = memang belum ada terminal kiosk di branch ini, bukan error — biarkan
    // no-op supaya saveAllHandler (yang selalu ngirim section kiosk) gak ikut gagal.
    if (count($terminals) === 0) {
      return "Success";
    }

    DB::transaction(function () use ($terminals) {
      foreach ($terminals as $terminal) {
        if (empty($terminal['id'])) {
          throw new \Exception('id terminal wajib diisi di setiap baris kiosk.terminal.');
        }

        TerminalModel::where('id', $terminal['id'])->update([
          'table_section_id' => $terminal['table_section_id'] ?? null,
          'receipt_station_id' => $terminal['receipt_station_id'] ?? null,
        ]);
      }
    });

    return "Success";
  }
}
