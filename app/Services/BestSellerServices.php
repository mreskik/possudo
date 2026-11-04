<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class BestSellerServices
{
  // getBestSellerMenu (2026-11-04): item utama SAJA (tr_order_detail) -- sub-item di dalam
  // package (tr_order_detail_package) SENGAJA TIDAK diikutkan, karena tujuannya liat menu
  // mana yang paling sering di-order langsung, beda konteks dari sales_by_menu di
  // DayShiftServices (itu buat laporan omzet, gabung keduanya). Status order yang dihitung
  // cuma 'paid' (transaksi closed), bukan pending/hold.
  //
  // $extraWhere/$extraBindings: filter tambahan opsional buat 3 versi endpoint (global / by
  // visit purpose / by table section) -- query inti sama, cuma beda 1 kondisi WHERE.
  public static function getBestSellerMenu($date_start, $date_end, $extraWhere = '', $extraBindings = [])
  {
    try {
      $bindings = array_merge([$date_start, $date_end], $extraBindings);

      return DB::select("
        SELECT
          mi.id as item_id,
          mi.name as item_name,
          mi.code as item_code,
          mi.image as item_image,
          mi.category_id,
          mcc.name as category_name,
          mi.subcategory_id,
          mscc.name as subcategory_name,
          SUM(tod.qty) as total_qty,
          SUM(tod.total) as total_sales
        FROM tr_order_detail tod
        JOIN tr_order tro ON tro.order_number = tod.order_number
        JOIN mr_item_conv mic ON mic.id = tod.menu_id
        JOIN mr_item mi ON mi.id = mic.item_id
        LEFT JOIN mr_category mcc ON mcc.id = mi.category_id
        LEFT JOIN mr_subcategory mscc ON mscc.id = mi.subcategory_id
        WHERE tod.cancel_at IS NULL
          AND tro.status = 'paid'
          AND tro.order_date BETWEEN ? AND ?
          " . $extraWhere . "
        GROUP BY mi.id
        ORDER BY total_qty DESC
      ", $bindings);
    } catch (\Throwable $e) {
      return $e;
    }
  }

  public static function getBestSellerMenuByVisitPurpose($date_start, $date_end, $visit_purpose_id)
  {
    return self::getBestSellerMenu($date_start, $date_end, 'AND tro.visit_purpose_id = ?', [$visit_purpose_id]);
  }

  public static function getBestSellerMenuByTableSection($date_start, $date_end, $tablesection_id)
  {
    return self::getBestSellerMenu($date_start, $date_end, 'AND tro.table_section_id = ?', [$tablesection_id]);
  }
}
