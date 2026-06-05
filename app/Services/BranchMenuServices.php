<?php

namespace App\Services;

use App\Models\MasterItemModel;

class BranchMenuServices
{
  static function load()
  {
    try {
      $data = MasterItemModel::get();
      return $data;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  static function saveFlagSoldOut($menu_id, $flag)
  {
    try {
      MasterItemModel::where('id', $menu_id)->update([
        'flag_soldout' => $flag
      ]);
      return 'success';
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  static function saveStokQTY($menu_id, $qty)
  {
    try {
      MasterItemModel::where('id', $menu_id)->update([
        'stok_qty' => $qty
      ]);
      return 'success';
    } catch (\Throwable $e) {
      throw $e;
    }
  }
}
