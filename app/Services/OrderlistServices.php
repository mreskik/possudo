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
}
