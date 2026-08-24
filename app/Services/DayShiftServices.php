<?php

namespace App\Services;

use App\Models\BranchModel;
use App\Models\DayShiftDetailModel;
use App\Models\DaySiftModel;
use App\Models\MasterBranchOpsSettingModel;
use App\Models\SessionModel;
use App\Models\TrOrderDetailModel;
use App\Models\TrOrderDetailPackageModel;
use App\Models\TrOrderModel;
use App\Models\TrOrderPaymentModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use stdClass;
use Illuminate\Support\Str;

class DayShiftServices
{

  // getLoggedInUserId ambil id user yang sedang login dari bearer token request -- pola sama
  // seperti OrderServices::getChasierName(), app ini gak pakai Auth::user() Laravel, login
  // state-nya di tabel mr_session (session_id = token, data = json_encode(user) penuh dari login).
  private static function getLoggedInUserId($request): ?int
  {
    try {
      $token = $request?->bearerToken();
      if (!$token) return null;
      $session = SessionModel::where('session_id', $token)->first();
      if (!$session) return null;
      $user = json_decode($session->data);
      return $user->id ?? null;
    } catch (\Throwable $e) {
      return null;
    }
  }

  // NettSalesJoinSql: LEFT JOIN nempel ke tr_order buat kolom nett_sales_real per order --
  // ini net_dpp (dpp SETELAH diskon), dihitung ulang langsung dari price_pos/discount_amount/
  // tax_rate/flag_inclusive_tax (bukan dari kolom dpp/net_dpp yang tersimpan) supaya konsisten
  // juga buat order lama yang kolom itu masih NULL/0.
  // Urutan standar PPN (sama persis recomputeDppTax() di orderPage.vue): pajak dilepas DULU
  // dari price_pos (dpp), BARU discount_amount dipotong dari situ (net_dpp) -- bukan diskon
  // dipotong dari price_pos mentah baru pajak dilepas (itu urutan LAMA, salah -- diskon jadi
  // kepotong dari basis yang masih ada pajak nempel di dalamnya).
  private static function NettSalesJoinSql(): string
  {
    return "
      LEFT JOIN (
        SELECT order_number, SUM(nett) AS nett_sales_real FROM (
          SELECT trod.order_number,
            trod.qty * ((CASE WHEN trod.flag_inclusive_tax = 1
              THEN trod.price_pos / (1 + trod.tax_rate / 100)
              ELSE trod.price_pos END) - trod.discount_amount) AS nett
          FROM tr_order_detail trod
          WHERE trod.cancel_at IS NULL
          UNION ALL
          SELECT trod.order_number,
            (trod.qty * trodp.qty) * ((CASE WHEN trodp.flag_inclusive_tax = 1
              THEN trodp.price_pos / (1 + trodp.tax_rate / 100)
              ELSE trodp.price_pos END) - trodp.discount_amount) AS nett
          FROM tr_order_detail_package trodp
          JOIN tr_order_detail trod ON trod.ulid = trodp.tr_order_detail_ulid
          WHERE trod.cancel_at IS NULL
        ) combined
        GROUP BY order_number
      ) nett_join ON nett_join.order_number = tro.order_number
    ";
  }

  public static function GetDayShift()
  {
    try {
      $data = DaySiftModel::where('dayout_time', null)->orderBy("ulid", 'desc')->first();
      Log::info($data);
      return $data;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  // GetKioskDayStatus: dipakai khusus buat Kiosk (KioskController::GetDayStatus) -- beda dari
  // GetDayShift() yang murni dayshift, ini juga mempertimbangkan jam operasional branch
  // (mr_branch_ops_setting) supaya kiosk otomatis "tutup" begitu lewat jam operasional, gak
  // nunggu kasir manual dayout (kadang dayshift sengaja dibiarin kebuka lama buat urus selisih
  // kas, tapi customer tetap gak boleh order lewat kiosk kalau udah lewat jam tutup).
  //
  // Urutan cek (berhenti di step pertama yang nentuin hasil):
  // 1. mr_branch_ops_setting gak ada baris buat hari ini -> throw (belum di-pull/di-setting,
  //    error, bukan is_open:false -- gak bisa dijawab beneran buka/tutup tanpa data ini).
  // 2. status 'closed' -> is_open false, gak peduli dayshift.
  // 3. status 'open' & sekarang di luar [open_time, closed_time] -> is_open false, gak peduli
  //    dayshift (prioritas di atas status dayshift).
  // 4. sisanya (status 'always_open', atau 'open' & masih dalam jam) -> is_open ikutin
  //    dayshift (dayin_time keisi, dayout_time NULL).
  public static function GetKioskDayStatus(): array
  {
    $today = strtolower(now()->format('l')); // 'monday'..'sunday', cocok sama kolom `day`

    $opsSetting = MasterBranchOpsSettingModel::where('day', $today)->first();
    if (!$opsSetting) {
      throw new \Exception('branch ops setting belum di-pull, tidak bisa cek jam operasional');
    }

    $dayshift = self::GetDayShift();

    if ($opsSetting->status === 'closed') {
      return self::buildKioskDayStatus(false, $dayshift);
    }

    if ($opsSetting->status === 'open') {
      $now = now()->format('H:i:s');
      if ($now < $opsSetting->open_time || $now > $opsSetting->closed_time) {
        return self::buildKioskDayStatus(false, $dayshift);
      }
    }

    // status 'always_open', atau 'open' & masih dalam jam operasional
    return self::buildKioskDayStatus($dayshift !== null, $dayshift);
  }

  private static function buildKioskDayStatus(bool $isOpen, $dayshift): array
  {
    return [
      'is_open' => $isOpen,
      'dayin_time' => $dayshift->dayin_time ?? null,
      'ulid' => $dayshift->ulid ?? null,
    ];
  }

  // GetOperationalHoursToday: MURNI jam operasional branch (mr_branch_ops_setting) hari ini,
  // BEDA dari GetKioskDayStatus() -- gak ikut mempertimbangkan status dayshift sama sekali.
  // Dipakai KioskController::GetTerminalDetail() buat nampilin info toko di layar terminal
  // (device-level), bukan buat gerbang boleh/gak-nya self-order (itu tetap lewat endpoint
  // /kiosk/day-status yang gabung dayshift).
  // Balikin null (bukan throw) kalau ops setting hari ini belum di-setting -- Terminal Detail
  // tetap harus bisa kebuka walau data jam operasional belum lengkap, field ini cuma info
  // tambahan, bukan syarat wajib kayak di GetKioskDayStatus().
  public static function GetOperationalHoursToday(): ?array
  {
    $today = strtolower(now()->format('l'));

    $opsSetting = MasterBranchOpsSettingModel::where('day', $today)->first();
    if (!$opsSetting) {
      return null;
    }

    $isOpen = false;
    if ($opsSetting->status === 'always_open') {
      $isOpen = true;
    } elseif ($opsSetting->status === 'open') {
      $now = now()->format('H:i:s');
      $isOpen = $now >= $opsSetting->open_time && $now <= $opsSetting->closed_time;
    }

    return [
      'day' => $opsSetting->day,
      'status' => $opsSetting->status,
      'open_time' => $opsSetting->open_time,
      'closed_time' => $opsSetting->closed_time,
      'is_open' => $isOpen,
    ];
  }

  public static function StartDay($start_cash)
  {
    try {
      // lockForUpdate di row branch -- dijadiin "kunci" biar 2 request StartDay yang nembak
      // barengan buat branch yang sama antre (bukan dua-duanya lolos cek GetDayShift() sebelum
      // salah satu sempat insert). Tanpa ini ada celah race condition kecil yang bisa bikin
      // 2 dayshift aktif sekaligus -- MySQL gak dukung partial unique index (WHERE dayout_time
      // IS NULL) kayak Postgres, jadi diamanin lewat transaction + row lock di sini.
      return DB::transaction(function () use ($start_cash) {
        $datetimenow = now();
        $branch = BranchModel::lockForUpdate()->first();
        $current_dayshift = self::GetDayShift();
        if ($current_dayshift) {
          if ($current_dayshift->dayout_time == null) {
            throw new \Exception('tidak bisa start day karena belum end day!');
          }
        }

        // DAYSHIFT ULID KOMPOSISI (kolom tetap "ulid", isinya bukan ULID lagi)
        // <MODUL><BRANCH CODE><waktu start day>
        // sama pola kayak OrderServices::GenerateOrderNumber(), cuma gak per-terminal
        // (dayshift itu konsep per-branch, bukan per-terminal)
        $kode_modul = "POS";
        $daydetail = $datetimenow->format("YmdHis");
        $dayshift_ulid = $kode_modul . $branch->branch_code . $daydetail;

        DaySiftModel::create([
          "ulid" => $dayshift_ulid,
          "branch_id" => $branch->id,
          "dayin_time" => $datetimenow,
          "dayin_total" => $start_cash,
          "dayin_user_id" => 1,
        ]);

        return "success";
      });
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public static function EndShift(string $ulid_dayshift, $request = null)
  {
    try {
      // cek current dayshift
      $current_dayshift = DaySiftModel::where("ulid", $ulid_dayshift)->first();
      if ($current_dayshift) {
        if ($current_dayshift->dayout_time != null) {
          throw new \Exception('tidak bisa end shift karena belum start day!');
        }
      } else {
        throw new \Exception("install dulu aplikasinya !");
      }

      // shift_number: urutan shift ke berapa dalam dayshift ini (dipakai buat label "Shift N"
      // di Navbar.vue: globalstore.shiftlist.length + 1) -- kolomnya NOT NULL tanpa default,
      // wajib diisi eksplisit, gak bisa diserahkan ke MySQL.
      $shift_number = DayShiftDetailModel::where('dayshift_ulid', $current_dayshift->ulid)->count() + 1;

      DayShiftDetailModel::create([
        "ulid" => (string)Str::ulid(),
        "dayshift_ulid" => $current_dayshift->ulid,
        "shift_time" => now(),
        "shift_number" => $shift_number,
        "shift_user_id" => self::getLoggedInUserId($request) ?? 1,
      ]);
      return 'success';
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public static function EndDay(Request $request)
  {
    try {

      $dayshift_ulid = $request->input("dayshift_ulid");
      $aktual_ending_cash = $request->input("aktual_ending_cash");
      $notes = $request->input("notes");

      $current_dayshift = DaySiftModel::where("ulid", $dayshift_ulid)->first();
      if (!$current_dayshift) {
        throw new \Exception("tidak pernah start day!");
      }
      if ($current_dayshift->dayout_time != null) {
        throw new \Exception("sudah pernah end day!");
      }
      DB::beginTransaction();

      DaySiftModel::where("ulid", $dayshift_ulid)->update([
        "dayout_time" => now(),
        "dayout_total" => $aktual_ending_cash,
        "dayout_notes" => $notes,
        "dayout_user_id" => self::getLoggedInUserId($request) ?? 1,
      ]);
      PrintServices::PrintEndDay($dayshift_ulid);

      // PUSH DATA DAYSHIFT DULU -- wajib duluan sebelum request jurnal, karena endpoint jurnal
      // di ERP (EndDayExec) sekarang nolak kalau baris pos_dayshift-nya belum ada di sana
      // (guard ditambah bareng flag_jurnal_run, lihat MASTER DAYSHIFT JURNAL.md).
      $pushService = new PushDataServices;
      $pushService->pushDataDayShift();
      $pushService->pushDataDayShiftDetail();

      // PUSH DATA ORDER DAN DETAIL SERTA PAYMENT
      $pushService->pushDataOrder();
      $pushService->pushDataOrderDetail();
      $pushService->pushDataOrderDetailPackage();
      $pushService->pushDataOrderPayment();

      // JURNAL END DAY -- token branch yang sama dipakai buat auth /pos/sync/*, sekarang
      // wajib juga buat /pos/endday/* (lihat middleware.BranchTokenAuth di APIANDORDER dan
      // midleware.BranchTokenAuth di sudocore2, keduanya validasi token yang sama).
      $branch = BranchModel::first();
      $resc = Http::withToken($branch->token)->get(env('SERVER_ENDPOINT') . "/pos/endday/jurnal/" . $branch->id . "/" . $dayshift_ulid);
      if ($resc->json('code') !== 0) {
        // sengaja gak throw -- dayout tetap harus sukses di lokal walau jurnal ERP gagal
        // (bisa di-retry manual lewat modul Dayshift Jurnal di ERP), tapi kegagalannya dicatat
        // biar ketauan, gak lagi diam-diam kelewat kayak sebelumnya.
        Log::error('gagal request jurnal endday ke ERP', [
          'dayshift_ulid' => $dayshift_ulid,
          'branch_id' => $branch->id,
          'response' => $resc->json(),
        ]);
      }
      DB::commit();
      return "end day success!";
    } catch (\Throwable $e) {
      DB::rollBack();
      throw $e;
    }
  }


  public static function GetReportAll()
  {
    try {


      $data_order_list = DB::select("
      SELECT
      tro.*,
      COALESCE(nett_join.nett_sales_real, 0) AS nett_sales_real
      FROM
      tr_order tro
      JOIN ( SELECT * FROM tr_dayshift tr WHERE tr.dayout_time IS NULL LIMIT 1 ) trd ON TRUE " .
        self::NettSalesJoinSql() . "
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
      $discount_total = 0;

      $list_payment_number = [];
      foreach ($data_order_list as $orderitem) {
        //itungan pending sales   
        if ($orderitem->status == "pending") {
          $pendingsales += $orderitem->total_billing;

          //masukkan ke list order number pending
          $list_ordernumber->pending[] = $orderitem->order_number;
        }
        //itungan net sales -- pakai nett_sales_real (SUM qty*dpp per order, lihat NettSalesJoinSql()),
        //BUKAN sub_total - total_discount (basisnya nyampur net-of-tax vs gross, understated buat inclusive tax + diskon)
        if ($orderitem->status == "paid") {
          $number_of_bill += 1;
          $discount_total += $orderitem->total_discount;
          $netsales += ($orderitem->nett_sales_real ?? 0);
          $netsales_dc_total += $orderitem->delivery_cost;
          $netsales_of_total += $orderitem->order_fee;
          $netsales_sc_total += $orderitem->service_charge;
          $netsales_pf_total += $orderitem->platform_fee;
          $pax_total += $orderitem->pax;

          $avg_netsales_per_pax += ($orderitem->nett_sales_real ?? 0);
          $avg_grosssales_per_pax += ($orderitem->total_billing);
          $avg_netsales_per_bill += ($orderitem->nett_sales_real ?? 0);
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
          $netsales_pb1_total += ($opd->tax_amount * $opd->qty);
        } else if ($opd->tax_type == 'vat') {
          $netsales_vat_total += ($opd->tax_amount * $opd->qty);
        }
      }

      foreach ($order_paid_detail_package as $opd) {
        if ($opd->tax_type == 'pb1') {
          $netsales_pb1_total += $opd->tax_amount * $opd->qty;
        } else if ($opd->tax_type == 'vat') {
          $netsales_vat_total += $opd->tax_amount * $opd->qty;
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
        sum( gabungan.discount_amount ) as discount_amount,
        sum( gabungan.vat_amount ) as vat_amount,
        sum( gabungan.pb1_amount ) as pb1_amount,
        sum( gabungan.grand_total )  as grand_total
      FROM
        (
        SELECT
          mi.NAME AS menu_name,
          tod.qty,
          ( (CASE WHEN tod.flag_inclusive_tax = 1 THEN tod.price_pos / (1 + tod.tax_rate/100) ELSE tod.price_pos END) * tod.qty ) AS sub_total,
          ( tod.discount_amount * tod.qty ) AS discount_amount,
        IF
          ( tod.tax_type = 'vat', tod.tax_amount * tod.qty, 0 ) AS vat_amount,
        IF
          ( tod.tax_type = 'pb1', tod.tax_amount * tod.qty, 0 ) AS pb1_amount,
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
          ( (CASE WHEN tod.flag_inclusive_tax = 1 THEN tod.price_pos / (1 + tod.tax_rate/100) ELSE tod.price_pos END) * tod.qty ) AS sub_total,
          ( tod.discount_amount * tod.qty ) AS discount_amount,
        IF
          ( tod.tax_type = 'vat', tod.tax_amount * tod.qty, 0 ) AS vat_amount,
        IF
          ( tod.tax_type = 'pb1', tod.tax_amount * tod.qty, 0 ) AS pb1_amount,
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
        sum( gabungan.discount_amount ) as discount_amount,
        sum( gabungan.vat_amount ) as vat_amount,
        sum( gabungan.pb1_amount ) as pb1_amount,
        sum( gabungan.grand_total )  as grand_total
      FROM
        (
        SELECT
					mi.category_id as category_id,
          mi.NAME AS menu_name,
          tod.qty,
          ( (CASE WHEN tod.flag_inclusive_tax = 1 THEN tod.price_pos / (1 + tod.tax_rate/100) ELSE tod.price_pos END) * tod.qty ) AS sub_total,
          ( tod.discount_amount * tod.qty ) AS discount_amount,
        IF
          ( tod.tax_type = 'vat', tod.tax_amount * tod.qty, 0 ) AS vat_amount,
        IF
          ( tod.tax_type = 'pb1', tod.tax_amount * tod.qty, 0 ) AS pb1_amount,
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
          ( (CASE WHEN tod.flag_inclusive_tax = 1 THEN tod.price_pos / (1 + tod.tax_rate/100) ELSE tod.price_pos END) * tod.qty ) AS sub_total,
          ( tod.discount_amount * tod.qty ) AS discount_amount,
        IF
          ( tod.tax_type = 'vat', tod.tax_amount * tod.qty, 0 ) AS vat_amount,
        IF
          ( tod.tax_type = 'pb1', tod.tax_amount * tod.qty, 0 ) AS pb1_amount,
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
          ["pl" => 1, "key" => "Discount Total", "amount" => $discount_total],
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



  public static function GetReportByShiftDetail($shiftdetail_ulid = null)
  {
    try {
      $data_dayshift_detail = DayShiftDetailModel::where('ulid', $shiftdetail_ulid)->first();
      $data_dayshift = DaySiftModel::where('ulid', $data_dayshift_detail->dayshift_ulid)->first();


      $starttime = $data_dayshift->dayin_time;
      $endtime = $data_dayshift_detail->shift_time;

      $dayshift_detail = DayShiftDetailModel::where('dayshift_ulid', $data_dayshift_detail->dayshift_ulid)
        ->orderBy('ulid', 'asc')->get();

      $data_dayshift->shift_queue = 1;

      //untuk ngecek juka ada shift lebih dari 1
      if (count($dayshift_detail) > 1) {
        $index = 0;
        foreach ($dayshift_detail as $ite) {
          if ($ite->id == $shiftdetail_ulid) {
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
        tro.*,
        COALESCE(nett_join.nett_sales_real, 0) AS nett_sales_real
        FROM
        tr_order tro " .
          self::NettSalesJoinSql() . "
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
      $holdsales = 0;

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
      $discount_total = 0;


      $orderpendinghold = TrOrderModel::whereIn('status', ['pending', 'hold'])->get();
      foreach ($orderpendinghold as $orderitem) {
        //itungan pending sales   
        if ($orderitem->status == "pending") {
          $pendingsales += $orderitem->total_billing;
          //masukkan ke list order number pending
          $list_ordernumber->pending[] = $orderitem->order_number;
        }

        //itungan hold sales
        if ($orderitem->status == "hold") {
          $holdsales += $orderitem->total_billing;
          //masukkan ke list order number pending
          // $list_ordernumber->pending[] = $orderitem->order_number;
        }
      }


      $list_payment_number = [];
      foreach ($data_order_list as $orderitem) {

        //itungan net sales -- pakai nett_sales_real (SUM qty*dpp per order, lihat NettSalesJoinSql()),
        //BUKAN sub_total - total_discount (basisnya nyampur net-of-tax vs gross, understated buat inclusive tax + diskon)
        if ($orderitem->status == "paid") {
          $number_of_bill += 1;
          $discount_total += $orderitem->total_discount;
          $netsales += ($orderitem->nett_sales_real ?? 0);
          $netsales_dc_total += $orderitem->delivery_cost;
          $netsales_of_total += $orderitem->order_fee;
          $netsales_sc_total += $orderitem->service_charge;
          $netsales_pf_total += $orderitem->platform_fee;
          $pax_total += $orderitem->pax;

          $avg_netsales_per_pax += ($orderitem->nett_sales_real ?? 0);
          $avg_grosssales_per_pax += ($orderitem->total_billing);
          $avg_netsales_per_bill += ($orderitem->nett_sales_real ?? 0);
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
          $netsales_pb1_total += ($opd->tax_amount * $opd->qty);
        } else if ($opd->tax_type == 'vat') {
          $netsales_vat_total += ($opd->tax_amount * $opd->qty);
        }
      }

      foreach ($order_paid_detail_package as $opd) {
        if ($opd->tax_type == 'pb1') {
          $netsales_pb1_total += $opd->tax_amount * $opd->qty;
        } else if ($opd->tax_type == 'vat') {
          $netsales_vat_total += $opd->tax_amount * $opd->qty;
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
        sum( gabungan.discount_amount ) as discount_amount,
        sum( gabungan.vat_amount ) as vat_amount,
        sum( gabungan.pb1_amount ) as pb1_amount,
        sum( gabungan.grand_total )  as grand_total
      FROM
        (
        SELECT
          mi.NAME AS menu_name,
          tod.qty,
          ( (CASE WHEN tod.flag_inclusive_tax = 1 THEN tod.price_pos / (1 + tod.tax_rate/100) ELSE tod.price_pos END) * tod.qty ) AS sub_total,
          ( tod.discount_amount * tod.qty ) AS discount_amount,
        IF
          ( tod.tax_type = 'vat', tod.tax_amount * tod.qty, 0 ) AS vat_amount,
        IF
          ( tod.tax_type = 'pb1', tod.tax_amount * tod.qty, 0 ) AS pb1_amount,
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
          ( (CASE WHEN tod.flag_inclusive_tax = 1 THEN tod.price_pos / (1 + tod.tax_rate/100) ELSE tod.price_pos END) * tod.qty ) AS sub_total,
          ( tod.discount_amount * tod.qty ) AS discount_amount,
        IF
          ( tod.tax_type = 'vat', tod.tax_amount * tod.qty, 0 ) AS vat_amount,
        IF
          ( tod.tax_type = 'pb1', tod.tax_amount * tod.qty, 0 ) AS pb1_amount,
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
        sum( gabungan.discount_amount ) as discount_amount,
        sum( gabungan.vat_amount ) as vat_amount,
        sum( gabungan.pb1_amount ) as pb1_amount,
        sum( gabungan.grand_total )  as grand_total
      FROM
        (
        SELECT
					mi.category_id as category_id,
          mi.NAME AS menu_name,
          tod.qty,
          ( (CASE WHEN tod.flag_inclusive_tax = 1 THEN tod.price_pos / (1 + tod.tax_rate/100) ELSE tod.price_pos END) * tod.qty ) AS sub_total,
          ( tod.discount_amount * tod.qty ) AS discount_amount,
        IF
          ( tod.tax_type = 'vat', tod.tax_amount * tod.qty, 0 ) AS vat_amount,
        IF
          ( tod.tax_type = 'pb1', tod.tax_amount * tod.qty, 0 ) AS pb1_amount,
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
          ( (CASE WHEN tod.flag_inclusive_tax = 1 THEN tod.price_pos / (1 + tod.tax_rate/100) ELSE tod.price_pos END) * tod.qty ) AS sub_total,
          ( tod.discount_amount * tod.qty ) AS discount_amount,
        IF
          ( tod.tax_type = 'vat', tod.tax_amount * tod.qty, 0 ) AS vat_amount,
        IF
          ( tod.tax_type = 'pb1', tod.tax_amount * tod.qty, 0 ) AS pb1_amount,
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
          ["pl" => 1, "key" => "Hold Sales", "amount" => $holdsales],
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
          ["pl" => 1, "key" => "Discount Total", "amount" => $discount_total],
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
      $dayshift_list = DaySiftModel::orderBy('ulid', 'desc')->get();
      return $dayshift_list;
    } catch (\Throwable $e) {
      throw $e;
    }
  }


  public static function GetReport($dayshift_ulid = null)
  {
    try {
      $data_dayshift = DaySiftModel::where('ulid', $dayshift_ulid)->first();
      $data_dayshift_detail = DayShiftDetailModel::where('dayshift_ulid', $dayshift_ulid)->get();

      $data_order_list = [];
      if ($data_dayshift->dayout_time != null) {
        $data_order_list = DB::select("
        SELECT
        tro.*,
        COALESCE(nett_join.nett_sales_real, 0) AS nett_sales_real
        FROM
        tr_order tro " .
          self::NettSalesJoinSql() . "
        WHERE
        tro.order_in >= ? and
        tro.order_out <= ?
        ", [$data_dayshift->dayin_time, $data_dayshift->dayout_time]);
      } else {
        $data_order_list = DB::select("
        SELECT
        tro.*,
        COALESCE(nett_join.nett_sales_real, 0) AS nett_sales_real
        FROM
        tr_order tro " .
          self::NettSalesJoinSql() . "
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
      $holdsales = 0;

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
      $discount_total = 0;

      $list_payment_number = [];

      // 

      $orderpendinghold = TrOrderModel::whereIn('status', ['pending', 'hold'])->get();
      foreach ($orderpendinghold as $orderitem) {
        //itungan pending sales   
        if ($orderitem->status == "pending") {
          $pendingsales += $orderitem->total_billing;
          //masukkan ke list order number pending
          $list_ordernumber->pending[] = $orderitem->order_number;
        }

        //itungan hold sales
        if ($orderitem->status == "hold") {
          $holdsales += $orderitem->total_billing;
          //masukkan ke list order number pending
          // $list_ordernumber->pending[] = $orderitem->order_number;
        }
      }


      // itungan data fix (paid, cancel, void)
      foreach ($data_order_list as $orderitem) {

        //itungan net sales -- pakai nett_sales_real (SUM qty*dpp per order, lihat NettSalesJoinSql()),
        //BUKAN sub_total - total_discount (basisnya nyampur net-of-tax vs gross, understated buat inclusive tax + diskon)
        if ($orderitem->status == "paid") {
          $number_of_bill += 1;
          $discount_total += $orderitem->total_discount;
          $netsales += ($orderitem->nett_sales_real ?? 0);
          $netsales_dc_total += $orderitem->delivery_cost;
          $netsales_of_total += $orderitem->order_fee;
          $netsales_sc_total += $orderitem->service_charge;
          $netsales_pf_total += $orderitem->platform_fee;
          $pax_total += $orderitem->pax;

          $avg_netsales_per_pax += ($orderitem->nett_sales_real ?? 0);
          $avg_grosssales_per_pax += ($orderitem->total_billing);
          $avg_netsales_per_bill += ($orderitem->nett_sales_real ?? 0);
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
          $netsales_pb1_total += ($opd->tax_amount * $opd->qty);
        } else if ($opd->tax_type == 'vat') {
          $netsales_vat_total += ($opd->tax_amount * $opd->qty);
        }
      }

      foreach ($order_paid_detail_package as $opd) {
        if ($opd->tax_type == 'pb1') {
          $netsales_pb1_total += $opd->tax_amount * $opd->qty;
        } else if ($opd->tax_type == 'vat') {
          $netsales_vat_total += $opd->tax_amount * $opd->qty;
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
        sum( gabungan.discount_amount ) as discount_amount,
        sum( gabungan.vat_amount ) as vat_amount,
        sum( gabungan.pb1_amount ) as pb1_amount,
        sum( gabungan.grand_total )  as grand_total
      FROM
        (
        SELECT
          mi.NAME AS menu_name,
          tod.qty,
          ( (CASE WHEN tod.flag_inclusive_tax = 1 THEN tod.price_pos / (1 + tod.tax_rate/100) ELSE tod.price_pos END) * tod.qty ) AS sub_total,
          ( tod.discount_amount * tod.qty ) AS discount_amount,
        IF
          ( tod.tax_type = 'vat', tod.tax_amount * tod.qty, 0 ) AS vat_amount,
        IF
          ( tod.tax_type = 'pb1', tod.tax_amount * tod.qty, 0 ) AS pb1_amount,
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
          ( (CASE WHEN tod.flag_inclusive_tax = 1 THEN tod.price_pos / (1 + tod.tax_rate/100) ELSE tod.price_pos END) * tod.qty ) AS sub_total,
          ( tod.discount_amount * tod.qty ) AS discount_amount,
        IF
          ( tod.tax_type = 'vat', tod.tax_amount * tod.qty, 0 ) AS vat_amount,
        IF
          ( tod.tax_type = 'pb1', tod.tax_amount * tod.qty, 0 ) AS pb1_amount,
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
        sum( gabungan.discount_amount ) as discount_amount,
        sum( gabungan.vat_amount ) as vat_amount,
        sum( gabungan.pb1_amount ) as pb1_amount,
        sum( gabungan.grand_total )  as grand_total
      FROM
        (
        SELECT
					mi.category_id as category_id,
          mi.NAME AS menu_name,
          tod.qty,
          ( (CASE WHEN tod.flag_inclusive_tax = 1 THEN tod.price_pos / (1 + tod.tax_rate/100) ELSE tod.price_pos END) * tod.qty ) AS sub_total,
          ( tod.discount_amount * tod.qty ) AS discount_amount,
        IF
          ( tod.tax_type = 'vat', tod.tax_amount * tod.qty, 0 ) AS vat_amount,
        IF
          ( tod.tax_type = 'pb1', tod.tax_amount * tod.qty, 0 ) AS pb1_amount,
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
          ( (CASE WHEN tod.flag_inclusive_tax = 1 THEN tod.price_pos / (1 + tod.tax_rate/100) ELSE tod.price_pos END) * tod.qty ) AS sub_total,
          ( tod.discount_amount * tod.qty ) AS discount_amount,
        IF
          ( tod.tax_type = 'vat', tod.tax_amount * tod.qty, 0 ) AS vat_amount,
        IF
          ( tod.tax_type = 'pb1', tod.tax_amount * tod.qty, 0 ) AS pb1_amount,
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
          ["pl" => 1, "key" => "Hold Sales", "amount" => $holdsales],
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
          ["pl" => 1, "key" => "Discount Total", "amount" => $discount_total],
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

  static function GetReportCurrentShiftforTampilan($dayshift_ulid)
  {
    try {

      $pakai_header = true;
      $starttime = null;
      // $endtime = ; karena current berarti batasa atas aja 
      $daftar_dayshift_detail = DayShiftDetailModel::where('dayshift_ulid', $dayshift_ulid)->get();
      $data_dayshift = DaySiftModel::where('ulid', $dayshift_ulid)->first();
      $data_dayshift_detail = DayShiftDetailModel::where('dayshift_ulid', $dayshift_ulid)
        ->orderBy('ulid', 'desc')->first();
      if ($data_dayshift_detail) {
        $pakai_header = false;
      }

      if ($pakai_header) {
        $starttime = $data_dayshift->dayin_time;
      } else {
        $starttime = $data_dayshift_detail->shift_time;
      }

      // ambil data 
      //////bawahnya ini sama dengan report biasa 

      // kueri ambil data start time sampai sekarang 

      $data_order_list = DB::select("
        SELECT
        tro.*,
        COALESCE(nett_join.nett_sales_real, 0) AS nett_sales_real
        FROM
        tr_order tro " .
          self::NettSalesJoinSql() . "
        WHERE
        tro.order_in >= ?

        ", [$starttime]);

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
      // $list_ordernumber->hold = [];

      $pendingsales = 0;
      $holdsales = 0;

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
      $discount_total = 0;


      $orderpendinghold = TrOrderModel::whereIn('status', ['pending', 'hold'])->get();
      foreach ($orderpendinghold as $orderitem) {
        //itungan pending sales   
        if ($orderitem->status == "pending") {
          $pendingsales += $orderitem->total_billing;
          //masukkan ke list order number pending
          $list_ordernumber->pending[] = $orderitem->order_number;
        }

        //itungan hold sales
        if ($orderitem->status == "hold") {
          $holdsales += $orderitem->total_billing;
          //masukkan ke list order number pending
          // $list_ordernumber->pending[] = $orderitem->order_number;
        }
      }


      $list_payment_number = [];
      foreach ($data_order_list as $orderitem) {

        //itungan net sales -- pakai nett_sales_real (SUM qty*dpp per order, lihat NettSalesJoinSql()),
        //BUKAN sub_total - total_discount (basisnya nyampur net-of-tax vs gross, understated buat inclusive tax + diskon)
        if ($orderitem->status == "paid") {
          $number_of_bill += 1;
          $discount_total += $orderitem->total_discount;
          $netsales += ($orderitem->nett_sales_real ?? 0);
          $netsales_dc_total += $orderitem->delivery_cost;
          $netsales_of_total += $orderitem->order_fee;
          $netsales_sc_total += $orderitem->service_charge;
          $netsales_pf_total += $orderitem->platform_fee;
          $pax_total += $orderitem->pax;

          $avg_netsales_per_pax += ($orderitem->nett_sales_real ?? 0);
          $avg_grosssales_per_pax += ($orderitem->total_billing);
          $avg_netsales_per_bill += ($orderitem->nett_sales_real ?? 0);
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
          $netsales_pb1_total += ($opd->tax_amount * $opd->qty);
        } else if ($opd->tax_type == 'vat') {
          $netsales_vat_total += ($opd->tax_amount * $opd->qty);
        }
      }

      foreach ($order_paid_detail_package as $opd) {
        if ($opd->tax_type == 'pb1') {
          $netsales_pb1_total += $opd->tax_amount * $opd->qty;
        } else if ($opd->tax_type == 'vat') {
          $netsales_vat_total += $opd->tax_amount * $opd->qty;
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
        sum( gabungan.discount_amount ) as discount_amount,
        sum( gabungan.vat_amount ) as vat_amount,
        sum( gabungan.pb1_amount ) as pb1_amount,
        sum( gabungan.grand_total )  as grand_total
      FROM
        (
        SELECT
          mi.NAME AS menu_name,
          tod.qty,
          ( (CASE WHEN tod.flag_inclusive_tax = 1 THEN tod.price_pos / (1 + tod.tax_rate/100) ELSE tod.price_pos END) * tod.qty ) AS sub_total,
          ( tod.discount_amount * tod.qty ) AS discount_amount,
        IF
          ( tod.tax_type = 'vat', tod.tax_amount * tod.qty, 0 ) AS vat_amount,
        IF
          ( tod.tax_type = 'pb1', tod.tax_amount * tod.qty, 0 ) AS pb1_amount,
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
          ( (CASE WHEN tod.flag_inclusive_tax = 1 THEN tod.price_pos / (1 + tod.tax_rate/100) ELSE tod.price_pos END) * tod.qty ) AS sub_total,
          ( tod.discount_amount * tod.qty ) AS discount_amount,
        IF
          ( tod.tax_type = 'vat', tod.tax_amount * tod.qty, 0 ) AS vat_amount,
        IF
          ( tod.tax_type = 'pb1', tod.tax_amount * tod.qty, 0 ) AS pb1_amount,
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
        sum( gabungan.discount_amount ) as discount_amount,
        sum( gabungan.vat_amount ) as vat_amount,
        sum( gabungan.pb1_amount ) as pb1_amount,
        sum( gabungan.grand_total )  as grand_total
      FROM
        (
        SELECT
					mi.category_id as category_id,
          mi.NAME AS menu_name,
          tod.qty,
          ( (CASE WHEN tod.flag_inclusive_tax = 1 THEN tod.price_pos / (1 + tod.tax_rate/100) ELSE tod.price_pos END) * tod.qty ) AS sub_total,
          ( tod.discount_amount * tod.qty ) AS discount_amount,
        IF
          ( tod.tax_type = 'vat', tod.tax_amount * tod.qty, 0 ) AS vat_amount,
        IF
          ( tod.tax_type = 'pb1', tod.tax_amount * tod.qty, 0 ) AS pb1_amount,
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
          ( (CASE WHEN tod.flag_inclusive_tax = 1 THEN tod.price_pos / (1 + tod.tax_rate/100) ELSE tod.price_pos END) * tod.qty ) AS sub_total,
          ( tod.discount_amount * tod.qty ) AS discount_amount,
        IF
          ( tod.tax_type = 'vat', tod.tax_amount * tod.qty, 0 ) AS vat_amount,
        IF
          ( tod.tax_type = 'pb1', tod.tax_amount * tod.qty, 0 ) AS pb1_amount,
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
        "dayshift_detail" => $daftar_dayshift_detail,
        "sales_recapitulation" => [
          ["pl" => 1, "key" => "Hold Sales", "amount" => $holdsales],
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
          ["pl" => 1, "key" => "Discount Total", "amount" => $discount_total],
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


  static function GetReportDayshiftorEndDay($dayshift_ulid)
  {
    try {

      $pakai_header = true;
      $starttime = null;
      // $endtime = ; karena current berarti batasa atas aja 

      $data_dayshift = DaySiftModel::where('ulid', $dayshift_ulid)->first();
      $daftar_dayshift_detail = DayShiftDetailModel::where('dayshift_ulid', $dayshift_ulid)->get();
      $data_dayshift_detail = DayShiftDetailModel::where('dayshift_ulid', $dayshift_ulid)
        ->orderBy('ulid', 'desc')->first();
      if ($data_dayshift_detail) {
        $pakai_header = false;
      }

      // if ($pakai_header) {
      $starttime = $data_dayshift->dayin_time;
      $endtime = $data_dayshift->dayout_time;
      if (!$endtime) {
        throw new \Exception('belum end day !');
      }
      // } else {
      //   $starttime = $data_dayshift_detail->shift_time;
      // }

      // ambil data 
      //////bawahnya ini sama dengan report biasa 

      // kueri ambil data start time sampai sekarang 

      $data_order_list = DB::select("
        SELECT
        tro.*,
        COALESCE(nett_join.nett_sales_real, 0) AS nett_sales_real
        FROM
        tr_order tro " .
          self::NettSalesJoinSql() . "
        WHERE
        tro.order_in >= ? AND
        tro.order_out <= ?

        ", [$starttime, $endtime]);

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
      // $list_ordernumber->hold = [];

      $pendingsales = 0;
      $holdsales = 0;

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
      $discount_total = 0;


      $orderpendinghold = TrOrderModel::whereIn('status', ['pending', 'hold'])->get();
      foreach ($orderpendinghold as $orderitem) {
        //itungan pending sales   
        if ($orderitem->status == "pending") {
          $pendingsales += $orderitem->total_billing;
          //masukkan ke list order number pending
          $list_ordernumber->pending[] = $orderitem->order_number;
        }

        //itungan hold sales
        if ($orderitem->status == "hold") {
          $holdsales += $orderitem->total_billing;
          //masukkan ke list order number pending
          // $list_ordernumber->pending[] = $orderitem->order_number;
        }
      }


      $list_payment_number = [];
      foreach ($data_order_list as $orderitem) {

        //itungan net sales -- pakai nett_sales_real (SUM qty*dpp per order, lihat NettSalesJoinSql()),
        //BUKAN sub_total - total_discount (basisnya nyampur net-of-tax vs gross, understated buat inclusive tax + diskon)
        if ($orderitem->status == "paid") {
          $number_of_bill += 1;
          $discount_total += $orderitem->total_discount;
          $netsales += ($orderitem->nett_sales_real ?? 0);
          $netsales_dc_total += $orderitem->delivery_cost;
          $netsales_of_total += $orderitem->order_fee;
          $netsales_sc_total += $orderitem->service_charge;
          $netsales_pf_total += $orderitem->platform_fee;
          $pax_total += $orderitem->pax;

          $avg_netsales_per_pax += ($orderitem->nett_sales_real ?? 0);
          $avg_grosssales_per_pax += ($orderitem->total_billing);
          $avg_netsales_per_bill += ($orderitem->nett_sales_real ?? 0);
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
          $netsales_pb1_total += ($opd->tax_amount * $opd->qty);
        } else if ($opd->tax_type == 'vat') {
          $netsales_vat_total += ($opd->tax_amount * $opd->qty);
        }
      }

      foreach ($order_paid_detail_package as $opd) {
        if ($opd->tax_type == 'pb1') {
          $netsales_pb1_total += $opd->tax_amount * $opd->qty;
        } else if ($opd->tax_type == 'vat') {
          $netsales_vat_total += $opd->tax_amount * $opd->qty;
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
        sum( gabungan.discount_amount ) as discount_amount,
        sum( gabungan.vat_amount ) as vat_amount,
        sum( gabungan.pb1_amount ) as pb1_amount,
        sum( gabungan.grand_total )  as grand_total
      FROM
        (
        SELECT
          mi.NAME AS menu_name,
          tod.qty,
          ( (CASE WHEN tod.flag_inclusive_tax = 1 THEN tod.price_pos / (1 + tod.tax_rate/100) ELSE tod.price_pos END) * tod.qty ) AS sub_total,
          ( tod.discount_amount * tod.qty ) AS discount_amount,
        IF
          ( tod.tax_type = 'vat', tod.tax_amount * tod.qty, 0 ) AS vat_amount,
        IF
          ( tod.tax_type = 'pb1', tod.tax_amount * tod.qty, 0 ) AS pb1_amount,
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
          ( (CASE WHEN tod.flag_inclusive_tax = 1 THEN tod.price_pos / (1 + tod.tax_rate/100) ELSE tod.price_pos END) * tod.qty ) AS sub_total,
          ( tod.discount_amount * tod.qty ) AS discount_amount,
        IF
          ( tod.tax_type = 'vat', tod.tax_amount * tod.qty, 0 ) AS vat_amount,
        IF
          ( tod.tax_type = 'pb1', tod.tax_amount * tod.qty, 0 ) AS pb1_amount,
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
        sum( gabungan.discount_amount ) as discount_amount,
        sum( gabungan.vat_amount ) as vat_amount,
        sum( gabungan.pb1_amount ) as pb1_amount,
        sum( gabungan.grand_total )  as grand_total
      FROM
        (
        SELECT
					mi.category_id as category_id,
          mi.NAME AS menu_name,
          tod.qty,
          ( (CASE WHEN tod.flag_inclusive_tax = 1 THEN tod.price_pos / (1 + tod.tax_rate/100) ELSE tod.price_pos END) * tod.qty ) AS sub_total,
          ( tod.discount_amount * tod.qty ) AS discount_amount,
        IF
          ( tod.tax_type = 'vat', tod.tax_amount * tod.qty, 0 ) AS vat_amount,
        IF
          ( tod.tax_type = 'pb1', tod.tax_amount * tod.qty, 0 ) AS pb1_amount,
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
          ( (CASE WHEN tod.flag_inclusive_tax = 1 THEN tod.price_pos / (1 + tod.tax_rate/100) ELSE tod.price_pos END) * tod.qty ) AS sub_total,
          ( tod.discount_amount * tod.qty ) AS discount_amount,
        IF
          ( tod.tax_type = 'vat', tod.tax_amount * tod.qty, 0 ) AS vat_amount,
        IF
          ( tod.tax_type = 'pb1', tod.tax_amount * tod.qty, 0 ) AS pb1_amount,
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
          DB::raw("COUNT(*) AS qty"),
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
        "dayshift_detail" => $daftar_dayshift_detail,
        "sales_recapitulation" => [
          ["pl" => 1, "key" => "Hold Sales", "amount" => $holdsales],
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
          ["pl" => 1, "key" => "Discount Total", "amount" => $discount_total],
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
  static function GetReportCurrentShift($dayshift_ulid)
  {
    try {

      $pakai_header = true;
      $starttime = null;
      // $endtime = ; karena current berarti batasa atas aja 

      $data_dayshift = DaySiftModel::where('ulid', $dayshift_ulid)->first();
      $daftar_dayshift_detail = DayShiftDetailModel::where('dayshift_ulid', $dayshift_ulid)->get();
      $data_dayshift_detail = DayShiftDetailModel::where('dayshift_ulid', $dayshift_ulid)
        ->orderBy('ulid', 'desc')->first();
      if ($data_dayshift_detail) {
        $pakai_header = false;
      }

      if ($pakai_header) {
        $starttime = $data_dayshift->dayin_time;
      } else {
        $starttime = $data_dayshift_detail->shift_time;
      }

      // ambil data 
      //////bawahnya ini sama dengan report biasa 

      // kueri ambil data start time sampai sekarang 

      $data_order_list = DB::select("
        SELECT
        tro.*,
        COALESCE(nett_join.nett_sales_real, 0) AS nett_sales_real
        FROM
        tr_order tro " .
          self::NettSalesJoinSql() . "
        WHERE
        tro.order_in >= ?

        ", [$starttime]);

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
      // $list_ordernumber->hold = [];

      $pendingsales = 0;
      $holdsales = 0;

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
      $discount_total = 0;


      $orderpendinghold = TrOrderModel::whereIn('status', ['pending', 'hold'])->get();
      foreach ($orderpendinghold as $orderitem) {
        //itungan pending sales   
        if ($orderitem->status == "pending") {
          $pendingsales += $orderitem->total_billing;
          //masukkan ke list order number pending
          $list_ordernumber->pending[] = $orderitem->order_number;
        }

        //itungan hold sales
        if ($orderitem->status == "hold") {
          $holdsales += $orderitem->total_billing;
          //masukkan ke list order number pending
          // $list_ordernumber->pending[] = $orderitem->order_number;
        }
      }


      $list_payment_number = [];
      foreach ($data_order_list as $orderitem) {

        //itungan net sales -- pakai nett_sales_real (SUM qty*dpp per order, lihat NettSalesJoinSql()),
        //BUKAN sub_total - total_discount (basisnya nyampur net-of-tax vs gross, understated buat inclusive tax + diskon)
        if ($orderitem->status == "paid") {
          $number_of_bill += 1;
          $discount_total += $orderitem->total_discount;
          $netsales += ($orderitem->nett_sales_real ?? 0);
          $netsales_dc_total += $orderitem->delivery_cost;
          $netsales_of_total += $orderitem->order_fee;
          $netsales_sc_total += $orderitem->service_charge;
          $netsales_pf_total += $orderitem->platform_fee;
          $pax_total += $orderitem->pax;

          $avg_netsales_per_pax += ($orderitem->nett_sales_real ?? 0);
          $avg_grosssales_per_pax += ($orderitem->total_billing);
          $avg_netsales_per_bill += ($orderitem->nett_sales_real ?? 0);
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
          $netsales_pb1_total += ($opd->tax_amount * $opd->qty);
        } else if ($opd->tax_type == 'vat') {
          $netsales_vat_total += ($opd->tax_amount * $opd->qty);
        }
      }

      foreach ($order_paid_detail_package as $opd) {
        if ($opd->tax_type == 'pb1') {
          $netsales_pb1_total += $opd->tax_amount * $opd->qty;
        } else if ($opd->tax_type == 'vat') {
          $netsales_vat_total += $opd->tax_amount * $opd->qty;
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
        sum( gabungan.discount_amount ) as discount_amount,
        sum( gabungan.vat_amount ) as vat_amount,
        sum( gabungan.pb1_amount ) as pb1_amount,
        sum( gabungan.grand_total )  as grand_total
      FROM
        (
        SELECT
          mi.NAME AS menu_name,
          tod.qty,
          ( (CASE WHEN tod.flag_inclusive_tax = 1 THEN tod.price_pos / (1 + tod.tax_rate/100) ELSE tod.price_pos END) * tod.qty ) AS sub_total,
          ( tod.discount_amount * tod.qty ) AS discount_amount,
        IF
          ( tod.tax_type = 'vat', tod.tax_amount * tod.qty, 0 ) AS vat_amount,
        IF
          ( tod.tax_type = 'pb1', tod.tax_amount * tod.qty, 0 ) AS pb1_amount,
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
          ( (CASE WHEN tod.flag_inclusive_tax = 1 THEN tod.price_pos / (1 + tod.tax_rate/100) ELSE tod.price_pos END) * tod.qty ) AS sub_total,
          ( tod.discount_amount * tod.qty ) AS discount_amount,
        IF
          ( tod.tax_type = 'vat', tod.tax_amount * tod.qty, 0 ) AS vat_amount,
        IF
          ( tod.tax_type = 'pb1', tod.tax_amount * tod.qty, 0 ) AS pb1_amount,
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
        sum( gabungan.discount_amount ) as discount_amount,
        sum( gabungan.vat_amount ) as vat_amount,
        sum( gabungan.pb1_amount ) as pb1_amount,
        sum( gabungan.grand_total )  as grand_total
      FROM
        (
        SELECT
					mi.category_id as category_id,
          mi.NAME AS menu_name,
          tod.qty,
          ( (CASE WHEN tod.flag_inclusive_tax = 1 THEN tod.price_pos / (1 + tod.tax_rate/100) ELSE tod.price_pos END) * tod.qty ) AS sub_total,
          ( tod.discount_amount * tod.qty ) AS discount_amount,
        IF
          ( tod.tax_type = 'vat', tod.tax_amount * tod.qty, 0 ) AS vat_amount,
        IF
          ( tod.tax_type = 'pb1', tod.tax_amount * tod.qty, 0 ) AS pb1_amount,
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
          ( (CASE WHEN tod.flag_inclusive_tax = 1 THEN tod.price_pos / (1 + tod.tax_rate/100) ELSE tod.price_pos END) * tod.qty ) AS sub_total,
          ( tod.discount_amount * tod.qty ) AS discount_amount,
        IF
          ( tod.tax_type = 'vat', tod.tax_amount * tod.qty, 0 ) AS vat_amount,
        IF
          ( tod.tax_type = 'pb1', tod.tax_amount * tod.qty, 0 ) AS pb1_amount,
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
          DB::raw("COUNT(*) AS qty"),
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
        "dayshift_detail" => $daftar_dayshift_detail,
        "sales_recapitulation" => [
          ["pl" => 1, "key" => "Hold Sales", "amount" => $holdsales],
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
          ["pl" => 1, "key" => "Discount Total", "amount" => $discount_total],
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


  static function GetReportPerShift($dayshift_detail_ulid)
  {
    try {

      $starttime = null;
      $endtime = null;
      $data_dayshift = null;
      $data_dayshift_detail = null;

      $data_dayshift_detail_curent = DayShiftDetailModel::where('ulid', $dayshift_detail_ulid)->first();
      $data_dayshift_detail_many = DayShiftDetailModel::where('dayshift_ulid', $data_dayshift_detail_curent->dayshift_ulid)->orderBy('shift_time', 'desc')->get();
      $daftar_dayshift_detail = DayShiftDetailModel::where('dayshift_ulid', $data_dayshift_detail_curent->dayshift_ulid)->get();

      // Log::info($data_dayshift_detail_many);

      if (count($data_dayshift_detail_many) == 0) {
        throw new \Exception('data dayshift detail tidak ditemukan !');
      } else if ($data_dayshift_detail_many->last()->ulid == $data_dayshift_detail_curent->ulid) {
        $data_dayshift_detail = $data_dayshift_detail_curent;
        $data_dayshift = DaySiftModel::where('ulid', $data_dayshift_detail->dayshift_ulid)->first();
        $starttime = $data_dayshift->dayin_time;
        $endtime = $data_dayshift_detail->shift_time;
      } else {
        // Log::info("ini yang di eksekusi");

        $currentIndex = $data_dayshift_detail_many->search(function ($item) use ($data_dayshift_detail_curent) {
          return $item->ulid == $data_dayshift_detail_curent->ulid;
        });

        $data_dayshift = DaySiftModel::where('ulid', $data_dayshift_detail_many[$currentIndex]->dayshift_ulid)->first();
        $starttime = $data_dayshift_detail_many[$currentIndex + 1]->shift_time;
        $endtime = $data_dayshift_detail_many[$currentIndex]->shift_time;
      }

      // $starttime = $data_dayshift_detail->shift_time;

      // ambil data 
      //////bawahnya ini sama dengan report biasa 

      // kueri ambil data start time sampai sekarang 

      $data_order_list = DB::select("
        SELECT
        tro.*,
        COALESCE(nett_join.nett_sales_real, 0) AS nett_sales_real
        FROM
        tr_order tro " .
          self::NettSalesJoinSql() . "
        WHERE
        tro.order_in >= ? AND
        tro.order_out <= ?

        ", [$starttime, $endtime]);

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
      // $list_ordernumber->hold = [];

      $pendingsales = 0;
      $holdsales = 0;

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
      $discount_total = 0;


      $orderpendinghold = TrOrderModel::whereIn('status', ['pending', 'hold'])->get();
      foreach ($orderpendinghold as $orderitem) {
        //itungan pending sales   
        if ($orderitem->status == "pending") {
          $pendingsales += $orderitem->total_billing;
          //masukkan ke list order number pending
          $list_ordernumber->pending[] = $orderitem->order_number;
        }

        //itungan hold sales
        if ($orderitem->status == "hold") {
          $holdsales += $orderitem->total_billing;
          //masukkan ke list order number pending
          // $list_ordernumber->pending[] = $orderitem->order_number;
        }
      }


      $list_payment_number = [];
      foreach ($data_order_list as $orderitem) {

        //itungan net sales -- pakai nett_sales_real (SUM qty*dpp per order, lihat NettSalesJoinSql()),
        //BUKAN sub_total - total_discount (basisnya nyampur net-of-tax vs gross, understated buat inclusive tax + diskon)
        if ($orderitem->status == "paid") {
          $number_of_bill += 1;
          $discount_total += $orderitem->total_discount;
          $netsales += ($orderitem->nett_sales_real ?? 0);
          $netsales_dc_total += $orderitem->delivery_cost;
          $netsales_of_total += $orderitem->order_fee;
          $netsales_sc_total += $orderitem->service_charge;
          $netsales_pf_total += $orderitem->platform_fee;
          $pax_total += $orderitem->pax;

          $avg_netsales_per_pax += ($orderitem->nett_sales_real ?? 0);
          $avg_grosssales_per_pax += ($orderitem->total_billing);
          $avg_netsales_per_bill += ($orderitem->nett_sales_real ?? 0);
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
          $netsales_pb1_total += ($opd->tax_amount * $opd->qty);
        } else if ($opd->tax_type == 'vat') {
          $netsales_vat_total += ($opd->tax_amount * $opd->qty);
        }
      }

      foreach ($order_paid_detail_package as $opd) {
        if ($opd->tax_type == 'pb1') {
          $netsales_pb1_total += $opd->tax_amount * $opd->qty;
        } else if ($opd->tax_type == 'vat') {
          $netsales_vat_total += $opd->tax_amount * $opd->qty;
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
        sum( gabungan.discount_amount ) as discount_amount,
        sum( gabungan.vat_amount ) as vat_amount,
        sum( gabungan.pb1_amount ) as pb1_amount,
        sum( gabungan.grand_total )  as grand_total
      FROM
        (
        SELECT
          mi.NAME AS menu_name,
          tod.qty,
          ( (CASE WHEN tod.flag_inclusive_tax = 1 THEN tod.price_pos / (1 + tod.tax_rate/100) ELSE tod.price_pos END) * tod.qty ) AS sub_total,
          ( tod.discount_amount * tod.qty ) AS discount_amount,
        IF
          ( tod.tax_type = 'vat', tod.tax_amount * tod.qty, 0 ) AS vat_amount,
        IF
          ( tod.tax_type = 'pb1', tod.tax_amount * tod.qty, 0 ) AS pb1_amount,
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
          ( (CASE WHEN tod.flag_inclusive_tax = 1 THEN tod.price_pos / (1 + tod.tax_rate/100) ELSE tod.price_pos END) * tod.qty ) AS sub_total,
          ( tod.discount_amount * tod.qty ) AS discount_amount,
        IF
          ( tod.tax_type = 'vat', tod.tax_amount * tod.qty, 0 ) AS vat_amount,
        IF
          ( tod.tax_type = 'pb1', tod.tax_amount * tod.qty, 0 ) AS pb1_amount,
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
        sum( gabungan.discount_amount ) as discount_amount,
        sum( gabungan.vat_amount ) as vat_amount,
        sum( gabungan.pb1_amount ) as pb1_amount,
        sum( gabungan.grand_total )  as grand_total
      FROM
        (
        SELECT
					mi.category_id as category_id,
          mi.NAME AS menu_name,
          tod.qty,
          ( (CASE WHEN tod.flag_inclusive_tax = 1 THEN tod.price_pos / (1 + tod.tax_rate/100) ELSE tod.price_pos END) * tod.qty ) AS sub_total,
          ( tod.discount_amount * tod.qty ) AS discount_amount,
        IF
          ( tod.tax_type = 'vat', tod.tax_amount * tod.qty, 0 ) AS vat_amount,
        IF
          ( tod.tax_type = 'pb1', tod.tax_amount * tod.qty, 0 ) AS pb1_amount,
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
          ( (CASE WHEN tod.flag_inclusive_tax = 1 THEN tod.price_pos / (1 + tod.tax_rate/100) ELSE tod.price_pos END) * tod.qty ) AS sub_total,
          ( tod.discount_amount * tod.qty ) AS discount_amount,
        IF
          ( tod.tax_type = 'vat', tod.tax_amount * tod.qty, 0 ) AS vat_amount,
        IF
          ( tod.tax_type = 'pb1', tod.tax_amount * tod.qty, 0 ) AS pb1_amount,
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
          DB::raw("COUNT(*) AS qty"),
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
        "dayshift_detail" => $daftar_dayshift_detail,
        "sales_recapitulation" => [
          ["pl" => 1, "key" => "Hold Sales", "amount" => $holdsales],
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
          ["pl" => 1, "key" => "Discount Total", "amount" => $discount_total],
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
}
