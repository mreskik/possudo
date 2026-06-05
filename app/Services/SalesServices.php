<?php

namespace App\Services;

use App\Models\DaySiftModel;
use App\Models\TrOrderDetailModel;
use App\Models\TrOrderDetailPackageModel;
use App\Models\TrOrderModel;
use App\Models\TrOrderPaymentModel;
use Illuminate\Support\Facades\DB;

class SalesServices
{

  public static function GetSalesList()
  {
    try {

      $dayshift = DaySiftModel::where('dayout_time', null)->orderBy('id', 'desc')->first();

      $data = DB::select("
      SELECT 

      tro.order_number,
      tro.payment_number,
      tro.order_date,
      tro.order_name,
      mrts.name as table_name,
      mvp.name as visit_purpose_name,
      tro.total_billing,
      tro.status,
      GROUP_CONCAT(mpm.name SEPARATOR ', ' )as payment_method,
      SUBSTRING_INDEX(tro.payment_at,' ',-1) as payment_time,
      'JUSE' as payment_by

      FROM tr_order tro
      left JOIN mr_table_section mrts on mrts.id = tro.table_section_id
      left join mr_visit_purpose mvp on mvp.id = tro.visit_purpose_id
      left JOIN tr_order_payment trd on trd.payment_number = tro.payment_number
      left JOIN mr_payment_method mpm on mpm.id = trd.payment_method_id
      WHERE tro.status != 'pending' 
      AND tro.order_in >= ?
      GROUP BY payment_number,order_number

      ORDER BY tro.order_number DESC
    ", [$dayshift->dayin_time]);

      return $data;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public static function viewSales(string $order_number)
  {

    try {
      $data_order = TrOrderModel::where('order_number', $order_number)->first();

      $data_order_payment = TrOrderPaymentModel::where("payment_number", $data_order->payment_number)->get();
      $data_order->payment_detail = DB::select("
      SELECT
        trp.*,
        mpm.NAME AS payment_method_name,
        mpt.NAME AS type 
      FROM
        tr_order_payment trp
        JOIN mr_payment_method mpm ON mpm.id = trp.payment_method_id
        JOIN mr_payment_method_type mpt ON mpt.id = mpm.payment_method_type_id 
      WHERE
        trp.payment_number = ?
      ", [$data_order->payment_number]);

      $data_order_detail = DB::select("
      SELECT
      tro.*,
      mi.name as menu_name,
      mi.short_name as menu_shortname

      FROM tr_order_detail tro
      JOIN mr_item_conv mit on mit.id = tro.menu_id
      JOIN mr_item mi on mi.id = mit.item_id

      WHERE tro.order_number = ?", [$order_number]);


      foreach ($data_order_detail as $item) {
        $data_order_package = DB::select("
          SELECT
          tro.*,
          mi.name as menu_name,
          mi.short_name as menu_shortname

          FROM tr_order_detail_package tro
          JOIN mr_item_conv mit on mit.id = tro.menu_id
          JOIN mr_item mi on mi.id = mit.item_id

          WHERE tro.tr_order_detail_ulid = ?", [$item->ulid]);

        // $data_order_package = TrOrderDetailPackageModel::where("tr_order_detail_ulid", $item->ulid)->get();
        $item->detail_package = $data_order_package;
      }

      $data_order->order_detail = $data_order_detail;

      return $data_order;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public static function ReprintPayment(string $order_number)
  {
    try {
      PrintServices::PrintPayment($order_number);
    } catch (\Throwable $e) {
    }
  }

  public static function Void(string $order_number, string $notes)
  {
    try {

      $data_order = TrOrderModel::where("order_number", $order_number)->first();

      if ($data_order->status != "paid") {
        throw new \Exception("hanya order paid yang bisa di void!");
      }
      if (!$data_order) {
        throw new \Exception("data order tidak ditemukan!");
      }

      DB::beginTransaction();
      TrOrderModel::where("order_number", $order_number)->update([
        "status" => "void",
        "void_notes" => $notes,
        "void_at" => now(),
        "void_print_ke" => 0,
        "sync_at" => null
      ]);
      DB::commit();

      PrintServices::PrintPayment($data_order->order_number);

      return "void $order_number success";
    } catch (\Throwable $e) {
      throw $e;
    }
  }
}
