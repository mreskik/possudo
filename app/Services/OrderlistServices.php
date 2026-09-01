<?php

namespace App\Services;

use App\Models\TableSectionModel;
use App\Models\TrOrderModel;
use Illuminate\Support\Facades\DB;

class OrderlistServices
{
  public static function getOrderList($tablesection_id)
  {
    try {

      $data_tablesection = TableSectionModel::where('id', $tablesection_id)->first();

      $orderList = [];

      if ($data_tablesection->type == 'takeaway') {
        $orderList = TrOrderModel::where('order_source', 'pos')
          ->where('table_section_id', $tablesection_id)
          ->whereIn('status', ['pending', 'hold'])
          ->orderBy('created_at', 'desc')->get();
      } else if ($data_tablesection->type == 'dinein') {
        $orderList = DB::select("
                    SELECT
                    mt.*,
                    tro.order_number,
                    tro.order_in,
                    tro.status

                    FROM mr_table mt
                    LEFT JOIN tr_order tro on tro.table_id = mt.id AND tro.status IN ('pending', 'hold')
                    WHERE mt.table_section_id = ?", [$data_tablesection->id]);
      }


      return $orderList;
    } catch (\Throwable $e) {
      return $e;
    }
  }

  // getAllPendingOrders: SEMUA order pending/hold lintas table section -- BEDA dari
  // getOrderList() di atas (yang WAJIB scoped ke 1 table_section_id). Dipakai lock screen
  // (LockscreenPage.vue) biar staff bisa liat sekilas order yang lagi nyangkut TANPA perlu
  // login/pilih table section dulu -- disepakati 2026-08-31, sebelumnya lock screen malah
  // nyalin utuh UI table-section-tabs dari listTablePage.vue yang gak relevan buat konteks
  // pra-login.
  public static function getAllPendingOrders()
  {
    try {
      return TrOrderModel::where('order_source', 'pos')
        ->whereIn('status', ['pending', 'hold'])
        ->orderBy('created_at', 'desc')
        ->get();
    } catch (\Throwable $e) {
      return $e;
    }
  }
}
