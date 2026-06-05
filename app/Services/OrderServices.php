<?php

namespace App\Services;

use App\Models\BranchModel;
use App\Models\MasterItemModel;
use App\Models\MasterPricelistDetailModel;
use App\Models\TableSectionModel;
use App\Models\TerminalModel;
use App\Models\TrOrderDetailModel;
use App\Models\TrOrderDetailPackageModel;
use App\Models\TrOrderModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OrderServices
{


  public static function GenerateOrderNumber()
  {
    // ORDER NUMBER KOMPOSISI
    // <MODUL><TERMINAL ID><BRANCH CODE><time order in >

    // <modul>
    try {
      $branch_data = BranchModel::first();

      // di implementasi ketika udah bikin sesi login by terminal 
      // $terminal = TerminalModel::where('id')


      // MODULE TAKING ORDER = TO
      $kode_modul = "NO";
      $formatnumber = now()->format("YmdHi");

      $komposisi = $kode_modul . $branch_data->branch_code . $formatnumber;
      return $komposisi;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public static function SaveOrder(Request $datajson)
  {
    $batch = 0;
    try {

      if ($datajson->orderNumber == '') {

        $date_now = now()->toDateString();

        $datetime_now = now();
        $order_number = self::GenerateOrderNumber();
        $branch = BranchModel::first();
        $last_order =  TrOrderModel::where('order_date', $date_now)->orderBy('order_queue', 'desc')->first();

        $order_queue = 1;
        if ($last_order != null) {
          $order_queue = $last_order->order_queue + 1;
        }

        // 
        $tablesectionnow = TableSectionModel::where('id', $datajson->tableSectionId)->first();
        if (!$tablesectionnow) {
          throw new \Exception("table Section tidak valid!");
        }
        // 

        $order = [
          "terminal_id" => 1,
          "order_name" => $datajson->orderName,
          "branch_id" => $branch->id,
          "customer_phone_number" =>  $datajson->customerPhoneNumber,
          "order_source" => $datajson->orderSource,
          "table_section_id" => $tablesectionnow->id,

          "order_type" => $tablesectionnow->type,
          "order_date" => $date_now,
          "order_queue" => $order_queue,
          "order_in" => $datetime_now,

          "total_batch" => $batch,
          "member_id" => $datajson->memberId,
          "visit_purpose_id" => $datajson->visitPurposeId,
          "pax" => $datajson->orderPax,
          "status" => 'pending',

          "waiter_name" => 'JUSE',
          "sender_name" => 'JUSE',
          // "pricelist_id" => $datajson->priceListId,

          "total_item" => $datajson->totalItem,
          "sub_total" => $datajson->subTotal,
          "total_tax" => $datajson->totalTax,
          "total_billing" => $datajson->totalBilling,
        ];

        // cek orderan baru
        // cek batch dan ordernumber 
        if ($datajson->orderNumber == "") {
          $order["order_number"] = $order_number;
          $order["total_batch"] = $order["total_batch"] + 1;
        }


        //pengecekan stok
        $datagrouping_ = [];



        $order_detail = [];
        $order_detail_package = [];

        //pengecekan stok
        $datagrouping_ = [];

        foreach ($datajson->listOrder as $item) {

          $ida = $item['menuPricelistId'];
          $datagrouping_[$ida] = ($datagrouping_[$ida] ?? 0) + $item['qty'];


          // Log::info($item);
          $mumu = [
            'ulid' => (string)Str::ulid(),
            'order_number' => $order['order_number'],

            'pricelist_detail_id' => $item['menuPricelistId'],
            'menu_id' => $item['menuId'],
            'qty' => $item['qty'],
            'flag_inclusive_tax' => $item['flagInclusiveTax'],
            'base_price' => $item['price'],
            'menu_price' => $item['menuPrice'],

            'tax_id' => $item['taxId'],
            'tax_type' => $item['taxType'],
            'tax_rate' => $item['taxRate'],
            'tax_value' => $item['taxValue'],

            'promo_id' => $item['promoId'],
            'discount_rate' => $item['discountRate'],
            'discount_value' => $item['discountValue'],

            'total' => $item['total'],

            'notes' => $item['notes'],
            'batch' => $order['total_batch'],
          ];

          $order_detail[] = $mumu;

          foreach ($item['menuPackageList'] as $item_package) {
            $order_detail_package[] = [
              'ulid' => (string)Str::ulid(),
              'tr_order_detail_ulid' => $mumu['ulid'],

              'menu_package_id' => $item_package['menuPackageId'],
              'menu_id' => $item_package['menuId'],
              'qty' => $item_package['qty'],
              'flag_inclusive_tax' => $item_package['flagInclusiveTax'],
              'base_price' => $item_package['price'],
              'menu_price' => $item_package['menuPrice'],

              'tax_id' => $item_package['taxId'],
              'tax_type' => $item_package['taxType'],
              'tax_rate' => $item_package['taxRate'],
              'tax_value' => $item_package['taxValue'],

              'promo_id' => $item_package['promoId'],
              'discount_rate' => $item_package['discountRate'],
              'discount_value' => $item_package['discountValue'],
              'total' => $item_package['total'],

              'notes' => $item_package['notes'],
            ];
          }
        }

        DB::beginTransaction();


        foreach ($datagrouping_ as $inded => $qtye) {
          $menueee = DB::select("SELECT
                      mi.*
                      FROM mr_pricelist_detail mpd 
                      join mr_item_conv mic on mic.id = mpd.item_conv_detail_id
                      JOIN mr_item mi on mi.id = mic.item_id

                      WHERE mpd.id = ?", [$inded]);
          if ($qtye > $menueee[0]->stok_qty) {
            throw new \Exception("Stok yang tersedia untuk " . $menueee[0]->name . " hanya " . $menueee[0]->stok_qty);
          }

          MasterItemModel::where('id', $menueee[0]->id)->update([
            'stok_qty' => ($menueee[0]->stok_qty - $qtye),
            'flag_soldout' => (($menueee[0]->stok_qty - $qtye) === 0)

          ]);
        }

        TrOrderModel::create($order);
        TrOrderDetailModel::insert($order_detail);
        TrOrderDetailPackageModel::insert($order_detail_package);
        DB::commit();


        // $data_order_query = TrOrderModel::where('order_number', $order_number)->first();

        PrintServices::PrintTableChecker2($tablesectionnow->id, $order_number);
        PrintServices::PrintMainChecker2($tablesectionnow->id, $order_number);
        PrintServices::PrintPriparationStation($tablesectionnow->id, $order_number);

        TrOrderDetailModel::where('order_number', $order_number)->update([
          "done_print" => true,
        ]);




        return $order_number;
      } else { //versi tambah pesanan tapi masih belum bisa cancel menu



        $order_number = $datajson->orderNumber;

        $current_table_order = TrOrderModel::where('order_number', $order_number)->where('status', 'pending')
          ->first();
        $batch = $current_table_order->total_batch;
        // Log::info($datajson);
        $order_update = [
          "order_name" => $datajson->orderName,
          "pax" => $datajson->orderPax,
          'total_batch' => $batch + 1,
          "total_item" => $datajson->totalItem,
          "sub_total" => $datajson->subTotal,
          "total_tax" => $datajson->totalTax,
          "total_billing" => $datajson->totalBilling,
        ];

        $order_detail = [];
        $order_detail_package = [];

        //pengecekan stok
        $datagrouping_ = [];
        foreach ($datajson->listOrder as $item) {
          $ida = $item['menuPricelistId'];
          $datagrouping_[$ida] = ($datagrouping_[$ida] ?? 0) + $item['qty'];


          // Log::info($item);
          //oder detail yang tidak punya ulid
          if (!isset($item['ulid'])) {
            $mumu = [
              'ulid' => (string)Str::ulid(),
              'order_number' => $order_number,

              'pricelist_detail_id' => $item['menuPricelistId'],
              'menu_id' => $item['menuId'],
              'qty' => $item['qty'],
              'flag_inclusive_tax' => $item['flagInclusiveTax'],
              'base_price' => $item['price'],
              'menu_price' => $item['menuPrice'],

              'tax_id' => $item['taxId'],
              'tax_type' => $item['taxType'],
              'tax_rate' => $item['taxRate'],
              'tax_value' => $item['taxValue'],

              'promo_id' => $item['promoId'],
              'discount_rate' => $item['discountRate'],
              'discount_value' => $item['discountValue'],

              'total' => $item['total'],

              'notes' => $item['notes'],
              'batch' => $batch + 1,
            ];
            $order_detail[] = $mumu;

            foreach ($item['menuPackageList'] as $item_package) {
              $order_detail_package[] = [
                'ulid' => (string)Str::ulid(),
                'tr_order_detail_ulid' => $mumu['ulid'],

                'menu_package_id' => $item_package['menuPackageId'],
                'menu_id' => $item_package['menuId'],
                'qty' => $item_package['qty'],
                'flag_inclusive_tax' => $item_package['flagInclusiveTax'],
                'base_price' => $item_package['price'],
                'menu_price' => $item_package['menuPrice'],

                'tax_id' => $item_package['taxId'],
                'tax_type' => $item_package['taxType'],
                'tax_rate' => $item_package['taxRate'],
                'tax_value' => $item_package['taxValue'],

                'promo_id' => $item_package['promoId'],
                'discount_rate' => $item_package['discountRate'],
                'discount_value' => $item_package['discountValue'],
                'total' => $item_package['total'],

                'notes' => $item_package['notes'],
              ];
            }
          }
        }

        // Log::info("batch sebelumnya :" . $batch);
        DB::beginTransaction();

        foreach ($datagrouping_ as $inded => $qtye) {
          $menueee = DB::select("SELECT
                      mi.*
                      FROM mr_pricelist_detail mpd 
                      join mr_item_conv mic on mic.id = mpd.item_conv_detail_id
                      JOIN mr_item mi on mi.id = mic.item_id

                      WHERE mpd.id = ?", [$inded]);
          if ($qtye > $menueee[0]->stok_qty) {
            throw new \Exception("Stok yang tersedia untuk " . $menueee[0]->name . " hanya " . $menueee[0]->stok_qty);
          }

          MasterItemModel::where('id', $menueee[0]->id)->update([
            'stok_qty' => ($menueee[0]->stok_qty - $qtye),
            'flag_soldout' => (($menueee[0]->stok_qty - $qtye) === 0)
          ]);
        }

        TrOrderModel::where('order_number', $order_number)
          ->update($order_update);
        if (count($order_detail) > 0) {

          TrOrderDetailModel::insert($order_detail);
          TrOrderDetailPackageModel::insert($order_detail_package);

          PrintServices::PrintTableChecker2($current_table_order->table_section_id, $current_table_order->order_number);
          PrintServices::PrintMainChecker2($current_table_order->table_section_id, $current_table_order->order_number);
          PrintServices::PrintPriparationStation($current_table_order->table_section_id, $current_table_order->order_number);

          TrOrderDetailModel::where('order_number', $current_table_order->order_number)->update([
            "done_print" => true,
          ]);
        }
        DB::commit();

        return $order_number;
      }
    } catch (\Throwable $e) {
      DB::rollBack();
      throw $e;
    }
  }

  public static function ViewOrder(string $order_number)
  {
    try {
      //get data order
      $data_order = DB::select("
      SELECT
      tro.order_number as orderNumber,
      tro.order_name as orderName,
      tro.customer_phone_number as customerPhoneNumber,
      tro.order_type as typeOrder,
      tro.visit_purpose_id as visitPurposeId,
      mvp.name as visitPurposeName,
      tro.pax as orderPax,
      tro.total_item as totalItem,
      tro.sub_total as subTotal,
      tro.total_tax as totalTax,
      tro.total_billing as totalBilling,
      tro.table_section_id as tableSectionId

      FROM tr_order tro
      JOIN mr_visit_purpose mvp on mvp.id = tro.visit_purpose_id
      WHERE tro.order_number = ? and tro.status = ?", [$order_number, 'pending']);

      $data_order_detail = DB::select("
      SELECT
      trod.ulid,
      trod.pricelist_detail_id as menuPricelistId,
      trod.menu_id as menuId,
      mri.name as menuName,
      mri.short_name as menuShortName,
      trod.batch as orderBatch,
      trod.qty,
      trod.notes,
      trod.flag_inclusive_tax as flagInclusiveTax,
      trod.base_price as price,
      trod.menu_price as menuPrice,
      trod.tax_id as taxId,
      trod.tax_type as taxType,
      trod.tax_rate as taxRate,
      trod.tax_value as taxValue,
      trod.promo_id as promoId,
      trod.discount_rate as discountRate,
      trod.discount_value as discountValue,
      trod.total

      FROM tr_order_detail trod
      JOIN mr_item_conv mric on mric.id = trod.menu_id
      JOIN mr_item mri on mri.id = mric.item_id

      WHERE trod.order_number = ?
      ", [$order_number]);

      foreach ($data_order_detail as $itemdetail) {
        $data_order_detail_package = [];
        $data_order_detail_package = DB::select("
        SELECT
        trodp.ulid,
        trodp.menu_package_id as menuPackageId,
        trodp.menu_id as menuId,
        mri.name as menuName,
        mri.short_name as menuShortName,
        trodp.qty,
        trodp.notes,
        trodp.flag_inclusive_tax as flagInclusiveTax,
        trodp.base_price as price,
        trodp.menu_price as menuPrice,
        trodp.tax_id as taxId,
        trodp.tax_type as taxType,
        trodp.tax_rate as taxRate,
        trodp.tax_value as taxValue,
        trodp.promo_id as promoId,
        trodp.discount_rate as discountRate,
        trodp.discount_value as discountValue,
        trodp.total

        FROM tr_order_detail_package trodp
        JOIN mr_item_conv mric on mric.id = trodp.menu_id
        JOIN mr_item mri on mri.id = mric.item_id

        WHERE trodp.tr_order_detail_ulid = ?", [$itemdetail->ulid]);

        $itemdetail->menuPackageList = $data_order_detail_package;
      }


      $data_order[0]->listOrder = $data_order_detail;

      return $data_order[0];
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public static function OnlyViewOrder(string $order_number)
  {

    try {
      //get data order
      $data_order = DB::select("
      SELECT
      tro.order_number as orderNumber,
      tro.order_name as orderName,
      tro.customer_phone_number as customerPhoneNumber,
      tro.order_type as typeOrder,
      tro.visit_purpose_id as visitPurposeId,
      mvp.name as visitPurposeName,
      tro.pax as orderPax,
      tro.total_item as totalItem,
      tro.sub_total as subTotal,
      tro.total_tax as totalTax,
      tro.total_billing as totalBilling

      FROM tr_order tro
      JOIN mr_visit_purpose mvp on mvp.id = tro.visit_purpose_id
      WHERE tro.order_number = ? and tro.status = ?", [$order_number, 'pending']);

      return $data_order[0];
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public static function CancelOrder(string $order_number, string $notes)
  {
    try {
      $order = TrOrderModel::where("order_number", $order_number)->first();

      if (!$order) {
        throw new \Exception('data order tidak ditemukan');
      }

      if ($order->status != 'pending') {
        throw new \Exception('status order bukan pending');
      }

      TrOrderModel::where('order_number', $order_number)->update([
        "status" => "cancel",
        "cancel_at" => now(),
        "cancel_notes" => $notes
      ]);

      return "cancel order success!";
    } catch (\Throwable $e) {
      throw $e;
    }
  }
}
