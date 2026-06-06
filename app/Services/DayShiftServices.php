<?php

namespace App\Services;

use App\Models\BranchModel;
use App\Models\DayShiftDetailModel;
use App\Models\DaySiftModel;
use App\Models\TrOrderDetailModel;
use App\Models\TrOrderDetailPackageModel;
use App\Models\TrOrderModel;
use App\Models\TrOrderPaymentModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use stdClass;

class DayShiftServices
{


  public static function GetDayShift()
  {
    try {
      $data = DaySiftModel::where('dayout_time', null)->orderBy("id", 'desc')->first();
      return $data;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public static function StartDay($start_cash)
  {
    try {
      $datetimenow = now();
      $branch = BranchModel::first();
      $current_dayshift = self::GetDayShift();
      if ($current_dayshift) {
        if ($current_dayshift->dayout_time == null) {
          throw new \Exception('tidak bisa start day karena belum end day!');
        }
      }

      // else {
      //     throw new \Exception("install dulu aplikasinya !");
      //   }

      DaySiftModel::create([
        "branch_id" => $branch->id,
        "dayin_time" => $datetimenow,
        "dayin_total" => $start_cash,
        "dayin_user_id" => 1,
      ]);

      return "success";
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public static function EndShift(int $id_dayshift)
  {
    try {
      // cek current dayshift 
      $current_dayshift = DaySiftModel::where("id", $id_dayshift)->first();
      if ($current_dayshift) {
        if ($current_dayshift->dayout_time != null) {
          throw new \Exception('tidak bisa end shift karena belum start day!');
        }
      } else {
        throw new \Exception("install dulu aplikasinya !");
      }

      DayShiftDetailModel::create([
        "dayshift_id" => $current_dayshift->id,
        "shift_time" => now(),
        "shift_user_id" => 1,
      ]);
      return 'success';
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public static function EndDay(Request $request)
  {
    try {

      $dayshift_id = $request->input("dayshift_id");
      $aktual_ending_cash = $request->input("aktual_ending_cash");
      $notes = $request->input("notes");

      $current_dayshift = DaySiftModel::where("id", $dayshift_id)->first();
      if (!$current_dayshift) {
        throw new \Exception("tidak pernah start day!");
      }
      if ($current_dayshift->dayout_time != null) {
        throw new \Exception("sudah pernah end day!");
      }

      DaySiftModel::where("id", $dayshift_id)->update([
        "dayout_time" => now(),
        "dayout_total" => $aktual_ending_cash,
        "dayout_notes" => $notes
      ]);

      return "end day success!";
    } catch (\Throwable $e) {
      throw $e;
    }
  }


  public static function GetReportAll()
  {
    try {


      $data_order_list = DB::select("
      SELECT
      tro.* 
      FROM
      tr_order tro
      JOIN ( SELECT * FROM tr_dayshift tr WHERE tr.dayout_time IS NULL LIMIT 1 ) trd ON TRUE 
      WHERE
      tro.order_in >= trd.dayin_time
      ");


      $list_ordernumber = new stdClass;
      $list_ordernumber->pending = [];
      $list_ordernumber->paid = [];
      $list_ordernumber->cancel = [];
      $list_ordernumber->void = [];

      $pendingsales = 0;

      // hitung per order
      $netsales = 0;
      $netsales_dc_total = 0;
      $netsales_of_total = 0;
      $netsales_sc_total = 0;
      $netsales_pf_total = 0;

      // hitung peritem order
      $netsales_pb1_total = 0;
      $netsales_vat_total = 0;

      $pax_total = 0;
      $avg_netsales_per_pax = 0;
      $avg_grosssales_per_pax = 0;
      $number_of_bill = 0;
      $avg_netsales_per_bill = 0;
      $avg_grosssales_per_bill = 0;

      $cancel_total = 0;
      $void_total = 0;

      $list_payment_number = [];
      foreach ($data_order_list as $orderitem) {
        //itungan pending sales   
        if ($orderitem->status == "pending") {
          $pendingsales += $orderitem->total_billing;

          //masukkan ke list order number pending
          $list_ordernumber->pending[] = $orderitem->order_number;
        }
        //sementara pakai subtotal dulu nanti kalau udah implementasi discount baru di cek lagi
        //itungan net sales
        if ($orderitem->status == "paid") {
          $number_of_bill += 1;
          $netsales += $orderitem->sub_total;
          $netsales_dc_total += $orderitem->delivery_cost;
          $netsales_of_total += $orderitem->order_fee;
          $netsales_sc_total += $orderitem->service_charge;
          $netsales_pf_total += $orderitem->platform_fee;
          $pax_total += $orderitem->pax;

          $avg_netsales_per_pax += ($orderitem->sub_total);
          $avg_grosssales_per_pax += ($orderitem->total_billing);
          $avg_netsales_per_bill += $orderitem->sub_total;
          $avg_grosssales_per_bill += $orderitem->total_billing;

          //kita masukkan ke list order number paid
          $list_ordernumber->paid[] = $orderitem->order_number;
          $list_payment_number[] = $orderitem->payment_number;
        }

        if ($orderitem->status == "cancel") {
          $list_ordernumber->cancel[] = $orderitem->order_number;
          $cancel_total += $orderitem->sub_total;
        }
        if ($orderitem->status == "void") {
          $list_ordernumber->void[] = $orderitem->order_number;
          $void_total += $orderitem->sub_total;
        }
      }

      $avg_netsales_per_pax = $avg_netsales_per_pax / $pax_total;
      $avg_grosssales_per_pax = $avg_grosssales_per_pax / $pax_total;
      $avg_netsales_per_bill = $avg_netsales_per_bill / $number_of_bill;
      $avg_grosssales_per_bill = $avg_grosssales_per_bill / $number_of_bill;

      //ORDER DETAIL AND PACKAGE YANG PAID 
      //daftar item order yang paid dan tidak cancel item
      $order_paid_detail = TrOrderDetailModel::whereIn('order_number', $list_ordernumber->paid)->where('cancel_at', null)->get();
      $ulid_order_paid_detail = [];
      foreach ($order_paid_detail as $item) {
        $ulid_order_paid_detail[] = $item->ulid;
      }

      //langsung di setingkat kan aja yang cersi package 
      $order_paid_detail_package = TrOrderDetailPackageModel::whereIn('tr_order_detail_ulid', $ulid_order_paid_detail)->get();

      foreach ($order_paid_detail as $opd) {
        if ($opd->tax_type == 'pb1') {
          $netsales_pb1_total += ($opd->tax_value * $opd->qty);
        } else if ($opd->tax_type == 'vat') {
          $netsales_vat_total += ($opd->tax_value * $opd->qty);
        }
      }

      foreach ($order_paid_detail_package as $opd) {
        if ($opd->tax_type == 'pb1') {
          $netsales_pb1_total += $opd->tax_value * $opd->qty;
        } else if ($opd->tax_type == 'vat') {
          $netsales_vat_total += $opd->tax_value * $opd->qty;
        }
      }


      $gross_sales = $netsales + $netsales_dc_total + $netsales_of_total +
        $netsales_sc_total + $netsales_pf_total + $netsales_pb1_total + $netsales_vat_total;

      $order_number_concat = "";
      $ulid_orderdetail_concat = "";

      foreach ($list_ordernumber->paid as $li) {
        $order_number_concat = $order_number_concat . "'" . $li . "',";
      }
      $order_number_concat = trim($order_number_concat, ",");
      foreach ($ulid_order_paid_detail as $uo) {
        $ulid_orderdetail_concat = $ulid_orderdetail_concat . "'" . $uo . "',";
      }
      $ulid_orderdetail_concat = trim($ulid_orderdetail_concat, ",");


      $sales_by_menu = DB::select("
      SELECT
        gabungan.menu_name,
        sum( gabungan.qty ) as qty,
        sum( gabungan.sub_total ) as sub_total,
        sum( gabungan.discount_value ) as discount_value,
        sum( gabungan.vat_amount ) as vat_amount,
        sum( gabungan.pb1_amount ) as pb1_amount,
        sum( gabungan.grand_total )  as grand_total
      FROM
        (
        SELECT
          mi.NAME AS menu_name,
          tod.qty,
          ( tod.menu_price * tod.qty ) AS sub_total,
          ( tod.discount_value * tod.qty ) AS discount_value,
        IF
          ( tod.tax_type = 'vat', tod.tax_value * tod.qty, 0 ) AS vat_amount,
        IF
          ( tod.tax_type = 'pb1', tod.tax_value * tod.qty, 0 ) AS pb1_amount,
          tod.total AS grand_total 
        FROM
          tr_order_detail tod
          JOIN mr_item_conv mic ON mic.id = tod.menu_id
          JOIN mr_item mi ON mi.id = mic.item_id 
        WHERE
          tod.order_number IN (" . $order_number_concat . ") 
          AND tod.cancel_at IS NULL UNION ALL
        SELECT
          mi.NAME AS menu_name,
          tod.qty,
          ( tod.menu_price * tod.qty ) AS sub_total,
          ( tod.discount_value * tod.qty ) AS discount_value,
        IF
          ( tod.tax_type = 'vat', tod.tax_value * tod.qty, 0 ) AS vat_amount,
        IF
          ( tod.tax_type = 'pb1', tod.tax_value * tod.qty, 0 ) AS pb1_amount,
          tod.total AS grand_total 
        FROM
          tr_order_detail_package tod
          JOIN mr_item_conv mic ON mic.id = tod.menu_id
          JOIN mr_item mi ON mi.id = mic.item_id 
        WHERE
          tod.tr_order_detail_ulid IN (" . $ulid_orderdetail_concat . ") 
        ) AS gabungan 
      GROUP BY
        menu_name
      ");

      $sales_by_category = DB::select("SELECT
				mcc.name as category_name,
        sum( gabungan.qty ) as qty,
        sum( gabungan.sub_total ) as sub_total,
        sum( gabungan.discount_value ) as discount_value,
        sum( gabungan.vat_amount ) as vat_amount,
        sum( gabungan.pb1_amount ) as pb1_amount,
        sum( gabungan.grand_total )  as grand_total
      FROM
        (
        SELECT
					mi.category_id as category_id,
          mi.NAME AS menu_name,
          tod.qty,
          ( tod.menu_price * tod.qty ) AS sub_total,
          ( tod.discount_value * tod.qty ) AS discount_value,
        IF
          ( tod.tax_type = 'vat', tod.tax_value * tod.qty, 0 ) AS vat_amount,
        IF
          ( tod.tax_type = 'pb1', tod.tax_value * tod.qty, 0 ) AS pb1_amount,
          tod.total AS grand_total 
        FROM
          tr_order_detail tod
          JOIN mr_item_conv mic ON mic.id = tod.menu_id
          JOIN mr_item mi ON mi.id = mic.item_id 
        WHERE
          tod.order_number IN (" . $order_number_concat . ") 
          AND tod.cancel_at IS NULL UNION ALL
        SELECT
          mi.category_id as category_id,
					mi.NAME AS menu_name,
          tod.qty,
          ( tod.menu_price * tod.qty ) AS sub_total,
          ( tod.discount_value * tod.qty ) AS discount_value,
        IF
          ( tod.tax_type = 'vat', tod.tax_value * tod.qty, 0 ) AS vat_amount,
        IF
          ( tod.tax_type = 'pb1', tod.tax_value * tod.qty, 0 ) AS pb1_amount,
          tod.total AS grand_total 
        FROM
          tr_order_detail_package tod
          JOIN mr_item_conv mic ON mic.id = tod.menu_id
          JOIN mr_item mi ON mi.id = mic.item_id 
        WHERE
          tod.tr_order_detail_ulid IN (" . $ulid_orderdetail_concat . ") 
        ) AS gabungan 
				
				JOIN mr_category mcc on mcc.id = gabungan.category_id
      GROUP BY
        category_name");




      //payment method detail
      // $payment_detail_list = TrOrderPaymentModel::whereIn('payment_number', $list_payment_number);
      $payment_detail_list = DB::table("tr_order_payment")
        ->join("mr_payment_method", "mr_payment_method.id", "=", "tr_order_payment.payment_method_id")
        ->whereIn("tr_order_payment.payment_number", $list_payment_number)
        ->select(
          "mr_payment_method.name AS payment_method_name",
          DB::raw("SUM(tr_order_payment.payment_amount) AS payment_amount"),
        )->groupBy("payment_method_name")->get();

      $sales_by_table_section = DB::table("tr_order")
        ->join("mr_table_section", "mr_table_section.id", "=", "tr_order.table_section_id")
        ->whereIn("tr_order.order_number", $list_ordernumber->paid)
        ->select(
          "mr_table_section.name as table_name",
          DB::raw("SUM(tr_order.sub_total) as total_amount"),
          DB::raw("COUNT(tr_order.order_number) as total_order")
        )
        ->groupBy("table_name")->get();


      return [
        "sales_recapitulation" => [
          ["pl" => 1, "key" => "Pending Sales", "amount" => $pendingsales],
          ["pl" => 1, "key" => "Net Sales", "amount" => $netsales],
          ["pl" => 40, "key" => "Netsales Delivery Cost", "amount" => $netsales_dc_total],
          ["pl" => 40, "key" => "Netsales Order Fee", "amount" => $netsales_of_total],
          ["pl" => 40, "key" => "Netsales Service Charge", "amount" => $netsales_sc_total],
          ["pl" => 40, "key" => "Netsales Platform Fee", "amount" => $netsales_pf_total],
          ["pl" => 40, "key" => "Netsales PB1 Total", "amount" => $netsales_pb1_total],
          ["pl" => 40, "key" => "Netsales VAT Total", "amount" => $netsales_vat_total],
          ["pl" => 1, "key" => "Gross Sales", "amount" => $gross_sales],
          ["pl" => 1, "key" => "Pax Total", "amount" => $pax_total],
          ["pl" => 1, "key" => "Avg Netsales Per Pax", "amount" => $avg_netsales_per_pax],
          ["pl" => 1, "key" => "Avg Gross Sales Per Pax", "amount" => $avg_grosssales_per_pax],
          ["pl" => 1, "key" => "Number Of Bills", "amount" => $number_of_bill],
          ["pl" => 1, "key" => "Avg Netsales Per Bill", "amount" => $avg_netsales_per_bill],
          ["pl" => 1, "key" => "Avg Gross Sales Per Bill", "amount" => $avg_grosssales_per_bill],
          ["pl" => 1, "key" => "Cancel Total", "amount" => $cancel_total],
          ["pl" => 1, "key" => "Void Total", "amount" => $void_total],
        ],
        "payment_recapitulation" => $payment_detail_list,
        "sales_by_menu" => $sales_by_menu,
        "sales_by_category" => $sales_by_category,
        "sales_by_table" => $sales_by_table_section
      ];
    } catch (\Throwable $e) {
      return $e;
    }
  }

  public static function GetReport($dayshift_id = null)
  {
    try {
      $data_dayshift = DaySiftModel::where('id', $dayshift_id)->first();
      $data_dayshift_detail = DayShiftDetailModel::where('dayshift_id', $dayshift_id)->get();

      $data_order_list = [];
      if ($data_dayshift->dayout_time != null) {
        $data_order_list = DB::select("
        SELECT
        tro.* 
        FROM
        tr_order tro
        WHERE
        tro.order_in >= ? and
        tro.order_out <= ?
        ", [$data_dayshift->dayin_time, $data_dayshift->dayout_time]);
      } else {
        $data_order_list = DB::select("
        SELECT
        tro.* 
        FROM
        tr_order tro
        
        WHERE
        tro.order_in >= ? 
        ", [$data_dayshift->dayin_time]);
      }


      $list_ordernumber = new stdClass;
      $list_ordernumber->pending = [];
      $list_ordernumber->paid = [];
      $list_ordernumber->cancel = [];
      $list_ordernumber->void = [];

      $pendingsales = 0;

      // hitung per order
      $netsales = 0;
      $netsales_dc_total = 0;
      $netsales_of_total = 0;
      $netsales_sc_total = 0;
      $netsales_pf_total = 0;

      // hitung peritem order
      $netsales_pb1_total = 0;
      $netsales_vat_total = 0;

      $pax_total = 0;
      $avg_netsales_per_pax = 0;
      $avg_grosssales_per_pax = 0;
      $number_of_bill = 0;
      $avg_netsales_per_bill = 0;
      $avg_grosssales_per_bill = 0;

      $cancel_total = 0;
      $void_total = 0;

      $list_payment_number = [];
      foreach ($data_order_list as $orderitem) {
        //itungan pending sales   
        if ($orderitem->status == "pending") {
          $pendingsales += $orderitem->total_billing;

          //masukkan ke list order number pending
          $list_ordernumber->pending[] = $orderitem->order_number;
        }
        //sementara pakai subtotal dulu nanti kalau udah implementasi discount baru di cek lagi
        //itungan net sales
        if ($orderitem->status == "paid") {
          $number_of_bill += 1;
          $netsales += $orderitem->sub_total;
          $netsales_dc_total += $orderitem->delivery_cost;
          $netsales_of_total += $orderitem->order_fee;
          $netsales_sc_total += $orderitem->service_charge;
          $netsales_pf_total += $orderitem->platform_fee;
          $pax_total += $orderitem->pax;

          $avg_netsales_per_pax += ($orderitem->sub_total);
          $avg_grosssales_per_pax += ($orderitem->total_billing);
          $avg_netsales_per_bill += $orderitem->sub_total;
          $avg_grosssales_per_bill += $orderitem->total_billing;

          //kita masukkan ke list order number paid
          $list_ordernumber->paid[] = $orderitem->order_number;
          $list_payment_number[] = $orderitem->payment_number;
        }

        if ($orderitem->status == "cancel") {
          $list_ordernumber->cancel[] = $orderitem->order_number;
          $cancel_total += $orderitem->sub_total;
        }
        if ($orderitem->status == "void") {
          $list_ordernumber->void[] = $orderitem->order_number;
          $void_total += $orderitem->sub_total;
        }
      }

      $avg_netsales_per_pax = $pax_total > 0
        ? $avg_netsales_per_pax / $pax_total
        : 0;

      $avg_grosssales_per_pax = $pax_total > 0
        ? $avg_grosssales_per_pax / $pax_total
        : 0;

      $avg_netsales_per_bill = $number_of_bill > 0
        ? $avg_netsales_per_bill / $number_of_bill
        : 0;

      $avg_grosssales_per_bill = $number_of_bill > 0
        ? $avg_grosssales_per_bill / $number_of_bill
        : 0;


      //ORDER DETAIL AND PACKAGE YANG PAID 
      //daftar item order yang paid dan tidak cancel item
      $order_paid_detail = TrOrderDetailModel::whereIn('order_number', $list_ordernumber->paid)->where('cancel_at', null)->get();
      $ulid_order_paid_detail = [];
      foreach ($order_paid_detail as $item) {
        $ulid_order_paid_detail[] = $item->ulid;
      }

      //langsung di setingkat kan aja yang cersi package 
      $order_paid_detail_package = TrOrderDetailPackageModel::whereIn('tr_order_detail_ulid', $ulid_order_paid_detail)->get();

      foreach ($order_paid_detail as $opd) {
        if ($opd->tax_type == 'pb1') {
          $netsales_pb1_total += ($opd->tax_value * $opd->qty);
        } else if ($opd->tax_type == 'vat') {
          $netsales_vat_total += ($opd->tax_value * $opd->qty);
        }
      }

      foreach ($order_paid_detail_package as $opd) {
        if ($opd->tax_type == 'pb1') {
          $netsales_pb1_total += $opd->tax_value * $opd->qty;
        } else if ($opd->tax_type == 'vat') {
          $netsales_vat_total += $opd->tax_value * $opd->qty;
        }
      }


      $gross_sales = $netsales + $netsales_dc_total + $netsales_of_total +
        $netsales_sc_total + $netsales_pf_total + $netsales_pb1_total + $netsales_vat_total;

      $order_number_concat = "";
      $ulid_orderdetail_concat = "";

      foreach ($list_ordernumber->paid as $li) {
        $order_number_concat = $order_number_concat . "'" . $li . "',";
      }
      $order_number_concat = trim($order_number_concat, ",");
      foreach ($ulid_order_paid_detail as $uo) {
        $ulid_orderdetail_concat = $ulid_orderdetail_concat . "'" . $uo . "',";
      }
      $ulid_orderdetail_concat = trim($ulid_orderdetail_concat, ",");


      $sales_by_menu = [];
      $sales_by_category = [];
      if (count($list_ordernumber->paid) > 0) {

        $sales_by_menu = DB::select("
      SELECT
        gabungan.menu_name,
        sum( gabungan.qty ) as qty,
        sum( gabungan.sub_total ) as sub_total,
        sum( gabungan.discount_value ) as discount_value,
        sum( gabungan.vat_amount ) as vat_amount,
        sum( gabungan.pb1_amount ) as pb1_amount,
        sum( gabungan.grand_total )  as grand_total
      FROM
        (
        SELECT
          mi.NAME AS menu_name,
          tod.qty,
          ( tod.menu_price * tod.qty ) AS sub_total,
          ( tod.discount_value * tod.qty ) AS discount_value,
        IF
          ( tod.tax_type = 'vat', tod.tax_value * tod.qty, 0 ) AS vat_amount,
        IF
          ( tod.tax_type = 'pb1', tod.tax_value * tod.qty, 0 ) AS pb1_amount,
          tod.total AS grand_total 
        FROM
          tr_order_detail tod
          JOIN mr_item_conv mic ON mic.id = tod.menu_id
          JOIN mr_item mi ON mi.id = mic.item_id 
        WHERE
          tod.order_number IN (" . $order_number_concat . ") 
          AND tod.cancel_at IS NULL UNION ALL
        SELECT
          mi.NAME AS menu_name,
          tod.qty,
          ( tod.menu_price * tod.qty ) AS sub_total,
          ( tod.discount_value * tod.qty ) AS discount_value,
        IF
          ( tod.tax_type = 'vat', tod.tax_value * tod.qty, 0 ) AS vat_amount,
        IF
          ( tod.tax_type = 'pb1', tod.tax_value * tod.qty, 0 ) AS pb1_amount,
          tod.total AS grand_total 
        FROM
          tr_order_detail_package tod
          JOIN mr_item_conv mic ON mic.id = tod.menu_id
          JOIN mr_item mi ON mi.id = mic.item_id 
        WHERE
          tod.tr_order_detail_ulid IN (" . $ulid_orderdetail_concat . ") 
        ) AS gabungan 
      GROUP BY
        menu_name
      ");

        $sales_by_category = DB::select("SELECT
				mcc.name as category_name,
        sum( gabungan.qty ) as qty,
        sum( gabungan.sub_total ) as sub_total,
        sum( gabungan.discount_value ) as discount_value,
        sum( gabungan.vat_amount ) as vat_amount,
        sum( gabungan.pb1_amount ) as pb1_amount,
        sum( gabungan.grand_total )  as grand_total
      FROM
        (
        SELECT
					mi.category_id as category_id,
          mi.NAME AS menu_name,
          tod.qty,
          ( tod.menu_price * tod.qty ) AS sub_total,
          ( tod.discount_value * tod.qty ) AS discount_value,
        IF
          ( tod.tax_type = 'vat', tod.tax_value * tod.qty, 0 ) AS vat_amount,
        IF
          ( tod.tax_type = 'pb1', tod.tax_value * tod.qty, 0 ) AS pb1_amount,
          tod.total AS grand_total 
        FROM
          tr_order_detail tod
          JOIN mr_item_conv mic ON mic.id = tod.menu_id
          JOIN mr_item mi ON mi.id = mic.item_id 
        WHERE
          tod.order_number IN (" . $order_number_concat . ") 
          AND tod.cancel_at IS NULL UNION ALL
        SELECT
          mi.category_id as category_id,
					mi.NAME AS menu_name,
          tod.qty,
          ( tod.menu_price * tod.qty ) AS sub_total,
          ( tod.discount_value * tod.qty ) AS discount_value,
        IF
          ( tod.tax_type = 'vat', tod.tax_value * tod.qty, 0 ) AS vat_amount,
        IF
          ( tod.tax_type = 'pb1', tod.tax_value * tod.qty, 0 ) AS pb1_amount,
          tod.total AS grand_total 
        FROM
          tr_order_detail_package tod
          JOIN mr_item_conv mic ON mic.id = tod.menu_id
          JOIN mr_item mi ON mi.id = mic.item_id 
        WHERE
          tod.tr_order_detail_ulid IN (" . $ulid_orderdetail_concat . ") 
        ) AS gabungan 
				
				JOIN mr_category mcc on mcc.id = gabungan.category_id
      GROUP BY
        category_name");
      }

      //payment method detail
      // $payment_detail_list = TrOrderPaymentModel::whereIn('payment_number', $list_payment_number);
      $payment_detail_list = DB::table("tr_order_payment")
        ->join("mr_payment_method", "mr_payment_method.id", "=", "tr_order_payment.payment_method_id")
        ->whereIn("tr_order_payment.payment_number", $list_payment_number)
        ->select(
          "mr_payment_method.name AS payment_method_name",
          DB::raw("SUM(tr_order_payment.payment_amount) AS payment_amount"),
        )->groupBy("payment_method_name")->get();

      $sales_by_table_section = DB::table("tr_order")
        ->join("mr_table_section", "mr_table_section.id", "=", "tr_order.table_section_id")
        ->whereIn("tr_order.order_number", $list_ordernumber->paid)
        ->select(
          "mr_table_section.name as table_name",
          DB::raw("SUM(tr_order.sub_total) as total_amount"),
          DB::raw("COUNT(tr_order.order_number) as total_order")
        )
        ->groupBy("table_name")->get();


      return [
        "dayshift" => $data_dayshift,
        "dayshift_detail" => $data_dayshift_detail,
        "sales_recapitulation" => [
          ["pl" => 1, "key" => "Pending Sales", "amount" => $pendingsales],
          ["pl" => 1, "key" => "Net Sales", "amount" => $netsales],
          ["pl" => 40, "key" => "Netsales Delivery Cost", "amount" => $netsales_dc_total],
          ["pl" => 40, "key" => "Netsales Order Fee", "amount" => $netsales_of_total],
          ["pl" => 40, "key" => "Netsales Service Charge", "amount" => $netsales_sc_total],
          ["pl" => 40, "key" => "Netsales Platform Fee", "amount" => $netsales_pf_total],
          ["pl" => 40, "key" => "Netsales PB1 Total", "amount" => $netsales_pb1_total],
          ["pl" => 40, "key" => "Netsales VAT Total", "amount" => $netsales_vat_total],
          ["pl" => 1, "key" => "Gross Sales", "amount" => $gross_sales],
          ["pl" => 1, "key" => "Pax Total", "amount" => $pax_total],
          ["pl" => 1, "key" => "Avg Netsales Per Pax", "amount" => $avg_netsales_per_pax],
          ["pl" => 1, "key" => "Avg Gross Sales Per Pax", "amount" => $avg_grosssales_per_pax],
          ["pl" => 1, "key" => "Number Of Bills", "amount" => $number_of_bill],
          ["pl" => 1, "key" => "Avg Netsales Per Bill", "amount" => $avg_netsales_per_bill],
          ["pl" => 1, "key" => "Avg Gross Sales Per Bill", "amount" => $avg_grosssales_per_bill],
          ["pl" => 1, "key" => "Cancel Total", "amount" => $cancel_total],
          ["pl" => 1, "key" => "Void Total", "amount" => $void_total],
        ],
        "payment_recapitulation" => $payment_detail_list,
        "sales_by_menu" => $sales_by_menu,
        "sales_by_category" => $sales_by_category,
        "sales_by_table" => $sales_by_table_section
      ];
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public static function GetReportByShiftDetail($shiftdetail_id = null)
  {
    try {
      $data_dayshift_detail = DayShiftDetailModel::where('id', $shiftdetail_id)->first();
      $data_dayshift = DaySiftModel::where('id', $data_dayshift_detail->dayshift_id)->first();


      $starttime = $data_dayshift->dayin_time;
      $endtime = $data_dayshift_detail->shift_time;

      $dayshift_detail = DayShiftDetailModel::where('dayshift_id', $data_dayshift_detail->dayshift_id)
        ->orderBy('id', 'asc')->get();

      $data_dayshift->shift_queue = 1;

      //untuk ngecek juka ada shift lebih dari 1
      if (count($dayshift_detail) > 1) {
        $index = 0;
        foreach ($dayshift_detail as $ite) {
          if ($ite->id == $shiftdetail_id) {
            break;
          }
          $index = $index + 1;
        }

        //ngereplace jika ada 2 shift buat ngambil data
        if ($index > 0) {
          $idx = $index - 1;
          $starttime = $dayshift_detail[$idx]->shift_time;
          // Log::info($dayshift_detail[$idx]);
        }
        $data_dayshift->shift_queue = $index + 1;
      }

      // Log::info($starttime . "==============" . $endtime);


      $data_dayshift->start_time = $starttime;
      $data_dayshift->end_time = $endtime;


      //////bawahnya ini sama dengan report biasa 

      $data_order_list = [];
      if ($data_dayshift_detail) {
        $data_order_list = DB::select("
        SELECT
        tro.* 
        FROM
        tr_order tro
        WHERE
        tro.order_in >= ? and
        tro.order_out <= ?
        ", [$starttime, $endtime]);
      }

      // if (count($data_order_list) == 0) {
      //   throw new \Exception('tidak ada transaksi !');
      // }

      // Log::info($data_dayshift);
      // Log::info($data_order_list);

      $list_ordernumber = new stdClass;
      $list_ordernumber->pending = [];
      $list_ordernumber->paid = [];
      $list_ordernumber->cancel = [];
      $list_ordernumber->void = [];

      $pendingsales = 0;

      // hitung per order
      $netsales = 0;
      $netsales_dc_total = 0;
      $netsales_of_total = 0;
      $netsales_sc_total = 0;
      $netsales_pf_total = 0;

      // hitung peritem order
      $netsales_pb1_total = 0;
      $netsales_vat_total = 0;

      $pax_total = 0;
      $avg_netsales_per_pax = 0;
      $avg_grosssales_per_pax = 0;
      $number_of_bill = 0;
      $avg_netsales_per_bill = 0;
      $avg_grosssales_per_bill = 0;

      $cancel_total = 0;
      $void_total = 0;

      $list_payment_number = [];
      foreach ($data_order_list as $orderitem) {
        //itungan pending sales   
        if ($orderitem->status == "pending") {
          $pendingsales += $orderitem->total_billing;

          //masukkan ke list order number pending
          $list_ordernumber->pending[] = $orderitem->order_number;
        }
        //sementara pakai subtotal dulu nanti kalau udah implementasi discount baru di cek lagi
        //itungan net sales
        if ($orderitem->status == "paid") {
          $number_of_bill += 1;
          $netsales += $orderitem->sub_total;
          $netsales_dc_total += $orderitem->delivery_cost;
          $netsales_of_total += $orderitem->order_fee;
          $netsales_sc_total += $orderitem->service_charge;
          $netsales_pf_total += $orderitem->platform_fee;
          $pax_total += $orderitem->pax;

          $avg_netsales_per_pax += ($orderitem->sub_total);
          $avg_grosssales_per_pax += ($orderitem->total_billing);
          $avg_netsales_per_bill += $orderitem->sub_total;
          $avg_grosssales_per_bill += $orderitem->total_billing;

          //kita masukkan ke list order number paid
          $list_ordernumber->paid[] = $orderitem->order_number;
          $list_payment_number[] = $orderitem->payment_number;
        }

        if ($orderitem->status == "cancel") {
          $list_ordernumber->cancel[] = $orderitem->order_number;
          $cancel_total += $orderitem->sub_total;
        }
        if ($orderitem->status == "void") {
          $list_ordernumber->void[] = $orderitem->order_number;
          $void_total += $orderitem->sub_total;
        }
      }

      $avg_netsales_per_pax = $pax_total > 0
        ? $avg_netsales_per_pax / $pax_total
        : 0;

      $avg_grosssales_per_pax = $pax_total > 0
        ? $avg_grosssales_per_pax / $pax_total
        : 0;

      $avg_netsales_per_bill = $number_of_bill > 0
        ? $avg_netsales_per_bill / $number_of_bill
        : 0;

      $avg_grosssales_per_bill = $number_of_bill > 0
        ? $avg_grosssales_per_bill / $number_of_bill
        : 0;


      //ORDER DETAIL AND PACKAGE YANG PAID 
      //daftar item order yang paid dan tidak cancel item
      $order_paid_detail = TrOrderDetailModel::whereIn('order_number', $list_ordernumber->paid)->where('cancel_at', null)->get();
      $ulid_order_paid_detail = [];
      foreach ($order_paid_detail as $item) {
        $ulid_order_paid_detail[] = $item->ulid;
      }

      //langsung di setingkat kan aja yang cersi package 
      $order_paid_detail_package = TrOrderDetailPackageModel::whereIn('tr_order_detail_ulid', $ulid_order_paid_detail)->get();

      foreach ($order_paid_detail as $opd) {
        if ($opd->tax_type == 'pb1') {
          $netsales_pb1_total += ($opd->tax_value * $opd->qty);
        } else if ($opd->tax_type == 'vat') {
          $netsales_vat_total += ($opd->tax_value * $opd->qty);
        }
      }

      foreach ($order_paid_detail_package as $opd) {
        if ($opd->tax_type == 'pb1') {
          $netsales_pb1_total += $opd->tax_value * $opd->qty;
        } else if ($opd->tax_type == 'vat') {
          $netsales_vat_total += $opd->tax_value * $opd->qty;
        }
      }


      $gross_sales = $netsales + $netsales_dc_total + $netsales_of_total +
        $netsales_sc_total + $netsales_pf_total + $netsales_pb1_total + $netsales_vat_total;

      $order_number_concat = "";
      $ulid_orderdetail_concat = "";

      foreach ($list_ordernumber->paid as $li) {
        $order_number_concat = $order_number_concat . "'" . $li . "',";
      }
      $order_number_concat = trim($order_number_concat, ",");
      foreach ($ulid_order_paid_detail as $uo) {
        $ulid_orderdetail_concat = $ulid_orderdetail_concat . "'" . $uo . "',";
      }
      $ulid_orderdetail_concat = trim($ulid_orderdetail_concat, ",");

      $sales_by_menu = [];
      $sales_by_category = [];
      if (count($list_ordernumber->paid) > 0) {

        $sales_by_menu = DB::select("
      SELECT
        gabungan.menu_name,
        sum( gabungan.qty ) as qty,
        sum( gabungan.sub_total ) as sub_total,
        sum( gabungan.discount_value ) as discount_value,
        sum( gabungan.vat_amount ) as vat_amount,
        sum( gabungan.pb1_amount ) as pb1_amount,
        sum( gabungan.grand_total )  as grand_total
      FROM
        (
        SELECT
          mi.NAME AS menu_name,
          tod.qty,
          ( tod.menu_price * tod.qty ) AS sub_total,
          ( tod.discount_value * tod.qty ) AS discount_value,
        IF
          ( tod.tax_type = 'vat', tod.tax_value * tod.qty, 0 ) AS vat_amount,
        IF
          ( tod.tax_type = 'pb1', tod.tax_value * tod.qty, 0 ) AS pb1_amount,
          tod.total AS grand_total 
        FROM
          tr_order_detail tod
          JOIN mr_item_conv mic ON mic.id = tod.menu_id
          JOIN mr_item mi ON mi.id = mic.item_id 
        WHERE
          tod.order_number IN (" . $order_number_concat . ") 
          AND tod.cancel_at IS NULL UNION ALL
        SELECT
          mi.NAME AS menu_name,
          tod.qty,
          ( tod.menu_price * tod.qty ) AS sub_total,
          ( tod.discount_value * tod.qty ) AS discount_value,
        IF
          ( tod.tax_type = 'vat', tod.tax_value * tod.qty, 0 ) AS vat_amount,
        IF
          ( tod.tax_type = 'pb1', tod.tax_value * tod.qty, 0 ) AS pb1_amount,
          tod.total AS grand_total 
        FROM
          tr_order_detail_package tod
          JOIN mr_item_conv mic ON mic.id = tod.menu_id
          JOIN mr_item mi ON mi.id = mic.item_id 
        WHERE
          tod.tr_order_detail_ulid IN (" . $ulid_orderdetail_concat . ") 
        ) AS gabungan 
      GROUP BY
        menu_name
      ");

        $sales_by_category = DB::select("SELECT
				mcc.name as category_name,
        sum( gabungan.qty ) as qty,
        sum( gabungan.sub_total ) as sub_total,
        sum( gabungan.discount_value ) as discount_value,
        sum( gabungan.vat_amount ) as vat_amount,
        sum( gabungan.pb1_amount ) as pb1_amount,
        sum( gabungan.grand_total )  as grand_total
      FROM
        (
        SELECT
					mi.category_id as category_id,
          mi.NAME AS menu_name,
          tod.qty,
          ( tod.menu_price * tod.qty ) AS sub_total,
          ( tod.discount_value * tod.qty ) AS discount_value,
        IF
          ( tod.tax_type = 'vat', tod.tax_value * tod.qty, 0 ) AS vat_amount,
        IF
          ( tod.tax_type = 'pb1', tod.tax_value * tod.qty, 0 ) AS pb1_amount,
          tod.total AS grand_total 
        FROM
          tr_order_detail tod
          JOIN mr_item_conv mic ON mic.id = tod.menu_id
          JOIN mr_item mi ON mi.id = mic.item_id 
        WHERE
          tod.order_number IN (" . $order_number_concat . ") 
          AND tod.cancel_at IS NULL UNION ALL
        SELECT
          mi.category_id as category_id,
					mi.NAME AS menu_name,
          tod.qty,
          ( tod.menu_price * tod.qty ) AS sub_total,
          ( tod.discount_value * tod.qty ) AS discount_value,
        IF
          ( tod.tax_type = 'vat', tod.tax_value * tod.qty, 0 ) AS vat_amount,
        IF
          ( tod.tax_type = 'pb1', tod.tax_value * tod.qty, 0 ) AS pb1_amount,
          tod.total AS grand_total 
        FROM
          tr_order_detail_package tod
          JOIN mr_item_conv mic ON mic.id = tod.menu_id
          JOIN mr_item mi ON mi.id = mic.item_id 
        WHERE
          tod.tr_order_detail_ulid IN (" . $ulid_orderdetail_concat . ") 
        ) AS gabungan 
				
				JOIN mr_category mcc on mcc.id = gabungan.category_id
      GROUP BY
        category_name");
      }


      //payment method detail
      // $payment_detail_list = TrOrderPaymentModel::whereIn('payment_number', $list_payment_number);
      $payment_detail_list = DB::table("tr_order_payment")
        ->join("mr_payment_method", "mr_payment_method.id", "=", "tr_order_payment.payment_method_id")
        ->whereIn("tr_order_payment.payment_number", $list_payment_number)
        ->select(
          "mr_payment_method.name AS payment_method_name",
          DB::raw("SUM(tr_order_payment.payment_amount) AS payment_amount"),
        )->groupBy("payment_method_name")->get();

      $sales_by_table_section = DB::table("tr_order")
        ->join("mr_table_section", "mr_table_section.id", "=", "tr_order.table_section_id")
        ->whereIn("tr_order.order_number", $list_ordernumber->paid)
        ->select(
          "mr_table_section.name as table_name",
          DB::raw("SUM(tr_order.sub_total) as total_amount"),
          DB::raw("COUNT(tr_order.order_number) as total_order")
        )
        ->groupBy("table_name")->get();


      return [

        "dayshift" => $data_dayshift,
        "sales_recapitulation" => [
          ["pl" => 1, "key" => "Pending Sales", "amount" => $pendingsales],
          ["pl" => 1, "key" => "Net Sales", "amount" => $netsales],
          ["pl" => 40, "key" => "Netsales Delivery Cost", "amount" => $netsales_dc_total],
          ["pl" => 40, "key" => "Netsales Order Fee", "amount" => $netsales_of_total],
          ["pl" => 40, "key" => "Netsales Service Charge", "amount" => $netsales_sc_total],
          ["pl" => 40, "key" => "Netsales Platform Fee", "amount" => $netsales_pf_total],
          ["pl" => 40, "key" => "Netsales PB1 Total", "amount" => $netsales_pb1_total],
          ["pl" => 40, "key" => "Netsales VAT Total", "amount" => $netsales_vat_total],
          ["pl" => 1, "key" => "Gross Sales", "amount" => $gross_sales],
          ["pl" => 1, "key" => "Pax Total", "amount" => $pax_total],
          ["pl" => 1, "key" => "Avg Netsales Per Pax", "amount" => $avg_netsales_per_pax],
          ["pl" => 1, "key" => "Avg Gross Sales Per Pax", "amount" => $avg_grosssales_per_pax],
          ["pl" => 1, "key" => "Number Of Bills", "amount" => $number_of_bill],
          ["pl" => 1, "key" => "Avg Netsales Per Bill", "amount" => $avg_netsales_per_bill],
          ["pl" => 1, "key" => "Avg Gross Sales Per Bill", "amount" => $avg_grosssales_per_bill],
          ["pl" => 1, "key" => "Cancel Total", "amount" => $cancel_total],
          ["pl" => 1, "key" => "Void Total", "amount" => $void_total],
        ],
        "payment_recapitulation" => $payment_detail_list,
        "sales_by_menu" => $sales_by_menu,
        "sales_by_category" => $sales_by_category,
        "sales_by_table" => $sales_by_table_section
      ];
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public static function GetDayshiftList()
  {
    try {
      $dayshift_list = DaySiftModel::orderBy('id', 'desc')->get();
      return $dayshift_list;
    } catch (\Throwable $e) {
      throw $e;
    }
  }
}
