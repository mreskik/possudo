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
      $use_virtual_keyboard = $data['useVirtualKeyboard'] ?? null;

      SettingModel::where('id', 1)->update([
        'default_station' => $default_station,
        'use_customer_display' => $use_customer_display,
        'use_virtual_keyboard' => $use_virtual_keyboard,
      ]);

      return "Success";
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  // SaveTerminalSetting update table_section_id & receipt_station_id untuk sekumpulan
  // terminal (semua jenis/pos_type, bukan cuma kiosk). $terminals adalah isi key
  // "terminal" dari body (array langsung, bukan nested).
  public static function SaveTerminalSetting(array $terminals)
  {
    // array kosong = memang belum ada terminal di branch ini, bukan error — biarkan
    // no-op supaya saveAllHandler (yang selalu ngirim section terminal) gak ikut gagal.
    if (count($terminals) === 0) {
      return "Success";
    }

    DB::transaction(function () use ($terminals) {
      foreach ($terminals as $terminal) {
        if (empty($terminal['id'])) {
          throw new \Exception('terminal id is required in every terminal row.');
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
