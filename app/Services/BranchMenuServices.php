<?php

namespace App\Services;

use App\Models\MasterItemModel;
use Illuminate\Support\Facades\DB;

class BranchMenuServices
{
  static function load()
  {
    try {
      $data = DB::select("
      SELECT DISTINCT
      mi.*
      from mr_branch_visit_purpose mbvp
      JOIN mr_pricelist_detail mpd on mpd.pricelist_id = mbvp.pricelist_id
      LEFT JOIN mr_item_conv mic on mic.id=mpd.item_conv_detail_id
      JOIN mr_item mi on mi.id = mic.item_id
      ");

      foreach ($data as $item) {
        if ($item->flag_soldout) {
          $item->flag_soldout = true;
        } else {
          $item->flag_soldout = false;
        }
      }

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
