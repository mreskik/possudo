<?php

namespace App\Services;

use App\Models\BranchModel;
use App\Models\DayShiftDetailModel;
use App\Models\DaySiftModel;
use App\Models\MasterBranchSettingModel;
use App\Models\MasterItemModel;
use App\Models\MasterPricelistDetailModel;
use App\Models\SessionModel;
use App\Models\TableSectionModel;
use App\Models\TerminalModel;
use App\Models\TrOrderDetailModel;
use App\Models\TrOrderDetailPackageModel;
use App\Models\TrOrderModel;
use App\Models\TrRemoveItemBeforeSaveModel;
use App\Models\TrRemoveItemBeforeSavePackageModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use stdClass;

class OrderServices
{


  public static function GenerateOrderNumber($terminal_id)
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
      $daydetail = now()->format("YmdHis");

      //lama $komposisi = $kode_modul . $branch_data->branch_code . $branch_data->company_id . $formatnumber;
      $komposisi = $kode_modul . $terminal_id . $branch_data->branch_code . $daydetail;
      return $komposisi;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  // ResolveTaxRate (2026-11-18) -- lookup rate SERVER-SIDE dari mr_tax by id, bukan percaya
  // taxRate/serviceChargeRate mentah dari FE lagi (keputusan user -- FE cukup kirim taxId/
  // serviceChargeId, rate-nya di-resolve di sini). $taxId null (item tanpa pajak/service
  // charge, valid -- lihat contoh "tax_id": null di dokumentasi Kiosk) -> rate 0, BUKAN error.
  // $taxId terisi tapi gak ketemu di mr_tax -> Exception (data gak konsisten, ditolak keras).
  // public (bukan private) -- 2026-11-18, dipakai juga dari KioskController::recomputeKioskUnit()
  // supaya rate resolution 1 sumber kebenaran (sama alasan CalculateLineItem()/
  // RecalculateOrderTotals() di atas).
  public static function ResolveTaxRate(?int $taxId): float
  {
    if (empty($taxId)) {
      return 0;
    }

    $tax = \App\Models\MasterTaxModel::find($taxId);
    if (!$tax) {
      throw new \Exception("tax/service charge dengan id {$taxId} tidak ditemukan");
    }

    return (float) $tax->rate;
  }

  // CalculateLineItem (2026-11-18) -- hitung ulang netPrice/dpp/taxAmount/serviceChargeAmount/
  // total SERVER-SIDE, bukan percaya mentah dari FE lagi (keputusan user -- field itu BOLEH
  // tetap dikirim FE di request, tapi DIABAIKAN total, server override pakai hasil fungsi ini).
  // Input yang TETAP dipercaya dari FE: price, taxRate, serviceChargeRate, flagInclusiveTax,
  // discountAmount -- discountAmount SENGAJA gak ikut di-re-derive (gak dicoba hitung ulang
  // dari discountPercent/promo rules, itu kompleks & alur promo FE sendiri di luar scope ini).
  //
  // Formula (5 langkah, urutan standar PPN -- pajak+service charge dilepas dulu dari harga
  // inclusive, BARU diskon dipotong, BARU pajak+service charge final dihitung ulang dari basis
  // setelah diskon):
  //   1. netPrice = flagInclusiveTax ? price / (1 + (taxRate+serviceChargeRate)/100) : price
  //   2. dpp = netPrice - discountAmount
  //   3. taxAmount = dpp * taxRate/100
  //   4. serviceChargeAmount = dpp * serviceChargeRate/100
  //   5. total = dpp + taxAmount + serviceChargeAmount
  //
  // BEDA dari RecalculateOrderTotals() (fungsi lama, dipakai SaveMoveItem()) -- fungsi itu
  // formulanya cuma melepas taxRate (belum ikut serviceChargeRate) dari harga inclusive, gap
  // ini SENGAJA dibiarkan untuk sekarang (scope perubahan ini cuma SaveOrder()), lihat
  // "Formula Kalkulasi Harga" di SAVE ORDER.md buat detail lengkap + contoh simulasi.
  // public (bukan private) -- 2026-11-18, dipakai juga dari KioskController::recomputeKioskUnit()
  // supaya rumus ini 1 sumber kebenaran (gak diduplikasi, ikut update otomatis kalau rumus ini
  // berubah lagi nanti).
  public static function CalculateLineItem(
    float $price,
    float $taxRate,
    float $serviceChargeRate,
    bool $flagInclusiveTax,
    float $discountAmount
  ): array {
    $netPrice = $flagInclusiveTax
      ? $price / (1 + ($taxRate + $serviceChargeRate) / 100)
      : $price;

    $dpp = $netPrice - $discountAmount;
    $taxAmount = $dpp * ($taxRate / 100);
    $serviceChargeAmount = $dpp * ($serviceChargeRate / 100);
    $total = $dpp + $taxAmount + $serviceChargeAmount;

    return [
      'netPrice' => round($netPrice, 2),
      'dpp' => round($dpp, 2),
      'taxAmount' => round($taxAmount, 2),
      'serviceChargeAmount' => round($serviceChargeAmount, 2),
      'total' => round($total, 2),
    ];
  }

  // ValidatePromoTierBarrier (2026-09-30) -- promo yang mr_promo.flag_all_tiers=false WAJIB
  // member (kalau ada) tier-nya cocok ke salah satu baris mr_promo_tier promo itu. Beda dari
  // flag_required_member (yang nolak "gak ada member sama sekali") -- ini nolak "ada member,
  // tapi tier-nya gak sesuai promo". Kalau member_id kosong, barrier ini gak nolak apa-apa (itu
  // udah tanggung jawab flag_required_member terpisah) -- promo yang flag_all_tiers=false tapi
  // member_id kosong lolos ke sini APA ADANYA, murni buat kasus flag_required_member=false
  // (dianggap gak wajib member) yang somehow juga punya batasan tier -- kombinasi itu sendiri
  // seharusnya udah ditolak keras di sisi admin (validatePromoRequiredMember() ERP), jadi ini
  // murni pengaman tambahan.
  private static function ValidatePromoTierBarrier(array $listOrder, ?int $memberId): void
  {
    if (empty($memberId)) {
      return;
    }

    $usedPromoIds = [];
    foreach ($listOrder as $item) {
      if (!empty($item['promoId'])) {
        $usedPromoIds[] = $item['promoId'];
      }
      foreach (($item['menuPackageList'] ?? []) as $pkg) {
        if (!empty($pkg['promoId'])) {
          $usedPromoIds[] = $pkg['promoId'];
        }
      }
    }
    if (empty($usedPromoIds)) {
      return;
    }

    $member = \App\Models\MasterMemberModel::find($memberId);
    $tierLevel = $member->tier_level ?? 1;

    $mismatchedPromo = \App\Models\MasterPromoModel::whereIn('id', array_unique($usedPromoIds))
      ->where('flag_all_tiers', false)
      ->whereDoesntHave('tiers', function ($q) use ($tierLevel) {
        $q->where('tier_level', $tierLevel);
      })
      ->first();

    if ($mismatchedPromo) {
      throw new \Exception("This promo is for members only");
    }
  }

  /**
   * Naming note: `$item['menuId']` (frontend cart field) and the `tr_order_detail.menu_id` /
   * `tr_order_detail_package.menu_id` columns it gets saved into are actually `mr_item_conv.id`,
   * not `mr_item.id` — see MenuServices::GetMasterMenuList() for where that id originates.
   */
  public static function SaveOrder($datajson)
  {
    $batch = 0;
    try {

      if ($datajson->orderNumber == '') {

        $date_now = now()->toDateString();

        $datetime_now = now();
        $order_number = self::GenerateOrderNumber($datajson->terminalId);
        $branch = BranchModel::first();

        // dayshift di-resolve DULUAN (sebelum hitung order_queue) -- dipakai dobel: sumber
        // scope order_queue di bawah, dan buat isi dayshift_ulid ke $order.
        $dayshift = DaySiftModel::where("dayout_time", null)->first();
        if (!$dayshift) {
          throw new \Exception("dayshift is not active, cannot create order!");
        }

        // Guard jam notification_end (2026-11-17, GANTI dari guard tanggal start day) -- baca
        // mr_branch_setting.flag_notification_end/notification_end (field lama, sudah ada
        // sejak dulu, TAPI belum pernah ada logic konsumsinya sama sekali di POS sampai
        // sekarang -- cuma CRUD/sync passthrough). CUMA berlaku buat order BARU (orderNumber
        // kosong), BUKAN buat nambah item ke order existing (blok itu beda, lihat else di
        // bawah). flag_notification_end harus true DAN jam sekarang sudah >= notification_end
        // (format "HH:MM:SS") -- gak ada filter tanggal/hari sama sekali di sini, murni jam.
        // branch_id selalu 1 row per install (singleton), sama pola SettingModel/StationModel
        // lain di POS.
        $branchSetting = MasterBranchSettingModel::first();
        if ($branchSetting && $branchSetting->flag_notification_end && !empty($branchSetting->notification_end)) {
          if (now()->format('H:i:s') >= $branchSetting->notification_end) {
            throw new \Exception("cannot create new order, you must end day first!");
          }
        }

        // order_queue di-scope PER DAYSHIFT (dayshift_ulid), BUKAN per order_date (tanggal
        // kalender) lagi -- order_date bisa nyambung >1 dayshift dalam 1 hari (multi-shift) atau
        // 1 dayshift bisa lintas 2 order_date (shift malam lewat tengah malam), dua-duanya bikin
        // nomor antrian gak nyambung/reset di tempat yang salah kalau di-scope per tanggal.
        $last_order =  TrOrderModel::where('dayshift_ulid', $dayshift->ulid)->orderBy('order_queue', 'desc')->first();

        // Custom queue setting (2026-11-19, baru) -- mr_branch_setting.flag_queue/
        // queue_start_from/queue_reset_after_each (field lama, sudah ada sejak 2026-11-03,
        // belum pernah ada logic konsumsinya di POS sampai sekarang, sama pola notification_end
        // di atas). flag_queue=false -> behavior LAMA APA ADANYA (mulai 1, naik terus gak ada
        // batas) -- gak ada perubahan buat branch yang belum pernah nyalain fitur ini.
        // flag_queue=true -> mulai dari queue_start_from (bukan 1), dan queue_reset_after_each
        // adalah AMBANG ANGKA MAKSIMUM -- begitu next_queue bakal MELEBIHI nilai itu, balik lagi
        // ke queue_start_from (bukan reset tiap N order, murni threshold angka). Scope TETAP per
        // dayshift_ulid, gak berubah -- reset alami juga kejadian tiap dayshift baru (dayshift
        // baru = $last_order null = mulai dari queue_start_from lagi).
        if ($branchSetting && $branchSetting->flag_queue) {
          $queueStartFrom = (int) $branchSetting->queue_start_from;
          $queueResetAfterEach = (int) $branchSetting->queue_reset_after_each;

          if ($last_order == null) {
            $order_queue = $queueStartFrom;
          } else {
            $nextQueue = $last_order->order_queue + 1;
            $order_queue = ($queueResetAfterEach > 0 && $nextQueue > $queueResetAfterEach)
              ? $queueStartFrom
              : $nextQueue;
          }
        } else {
          $order_queue = 1;
          if ($last_order != null) {
            $order_queue = $last_order->order_queue + 1;
          }
        }

        //
        $tablesectionnow = TableSectionModel::where('id', $datajson->tableSectionId)->first();
        if (!$tablesectionnow) {
          throw new \Exception("invalid table section!");
        }

        // Guard meja-sudah-ada-order-aktif (2026-10-27) -- CUMA di jalur Create order baru
        // (orderNumber kosong, blok if ini), BUKAN di jalur tambah-pesanan-ke-order-existing
        // (blok else di bawah -- itu memang nambah ke order pending yang sama, bukan bikin
        // order baru di meja yang sama). status IN ('pending', 'hold') -- konsisten sama pola
        // yang sudah ada di SaveMoveTable()/SaveMoveItem(), bukan cuma 'pending' doang, biar
        // order yang lagi di-hold di meja itu juga dihitung "masih aktif".
        if (!empty($datajson->tableId)) {
          $activeOrderOnTable = TrOrderModel::where('table_id', $datajson->tableId)
            ->whereIn('status', ['pending', 'hold'])
            ->first();
          if ($activeOrderOnTable) {
            throw new \Exception('This table already has a pending order!');
          }
        }
        //
        $order = [
          "dayshift_ulid" => $dayshift->ulid,
          "terminal_id" => $datajson->terminalId,
          "order_name" => ($datajson->orderName == '') ? '' : $datajson->orderName,
          "branch_id" => $branch->id,
          "customer_phone_number" =>  $datajson->customerPhoneNumber,
          "order_source" => $datajson->orderSource,
          "table_section_id" => $tablesectionnow->id,

          "order_type" => $tablesectionnow->type,
          "order_date" => $date_now,
          "order_queue" => $order_queue,
          "order_in" => $datetime_now,

          "table_id" => $datajson->tableId,

          "total_batch" => $batch,
          "member_id" => $datajson->memberId,
          "visit_purpose_id" => $datajson->visitPurposeId,
          "pax" => $datajson->orderPax,

          // waiter_name sementara diabaikan (belum ada sumber data waiter sungguhan di flow
          // Create Order) -- diisi sama kayak chasier_name aja (bukan hardcode 'JUSE' lagi),
          // biar gak ada placeholder yang nyasar ke UI manapun yang nampilin kolom ini.
          "waiter_name" => self::getChasierName($datajson),
          "sender_name" => ' ',
          "chasier_name" => self::getChasierName($datajson),
          // created_by (2026-11-20, BARU) -- SEBELUMNYA gak pernah ditulis sama sekali di sini,
          // bikin tr_order.created_by SELALU NULL buat semua order POS/Kiosk. Dampaknya: STAFF
          // SALES SUMMARY/STAFF PAYMENT SUMMARY (DayShiftServices.php, INNER JOIN mr_user ON
          // mr_user.id = tr_order.created_by) selalu kosong, karena INNER JOIN gak match baris
          // created_by NULL. getLoggedInUserId() reuse pola yang sama kayak getChasierName()
          // (resolve dari Bearer token -> SessionModel -> user id) -- null kalau emang gak ada
          // kasir login (wajar buat order Kiosk self-service).
          "created_by" => self::getLoggedInUserId($datajson),
          // "pricelist_id" => $datajson->priceListId,

          // total_item/sub_total/total_discount/total_tax/total_service_charge/total_billing
          // (2026-11-18, UBAH ARSITEKTUR) -- SEMUA kolom turunan dari baris item ini TIDAK
          // LAGI ditulis dari $datajson di sini. Nilai awal 0 (default kolom), nanti DI-
          // OVERRIDE oleh RecalculateOrderTotals($order_number) yang dipanggil SETELAH insert
          // $order_detail/$order_detail_package selesai (lihat bawah) -- server hitung ulang
          // SEMUA angka ini dari baris yang beneran tersimpan, FE gak lagi dipercaya mentah
          // (field itu BOLEH tetap dikirim FE buat display, tapi diabaikan total server).
          //
          // order_fee/delivery_cost TETAP dari FE apa adanya (bukan turunan item, input
          // manual level header) -- ditulis DI SINI (bukan RecalculateOrderTotals()) karena
          // fungsi itu cuma baca ulang 2 kolom ini buat ditambahkan ke total_billing, gak
          // menghitungnya sendiri.
          "order_fee" => $datajson->orderFee ?? 0,
          "delivery_cost" => $datajson->deliveryCost ?? 0,

          // invariant per order (mr_pricelist.is_inclusive via visit purpose, semua baris
          // listOrder pasti sama) -- diambil sekali dari item pertama pas order dibuat,
          // dipakai PrintServices buat nentuin layout struk (inclusive vs breakdown pajak)
          "flag_inclusive_tax" => $datajson->listOrder[0]['flagInclusiveTax'] ?? null,
        ];


        //cek flag table section hold
        if ($tablesectionnow->can_hold) {
          $order['status'] = 'hold';
        } else {
          $order['status'] = 'pending';
        }



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

        // Barrier flag_required_member (2026-09-30) -- promo yang mr_promo.flag_required_member
        // = true WAJIB order-nya punya member_id (bukan guest). Dicek SEKALI di sini (bukan per
        // baris item), kumpulin dulu SEMUA promo_id unik yang kepake (header + package), query
        // sekali ke mr_promo -- niru pola batch yang sama kayak sudomobile (pricing/promo.go),
        // bukan N+1 query per baris.
        if (empty($order['member_id'])) {
          $usedPromoIds = [];
          if ($datajson->listOrder) {
            foreach ($datajson->listOrder as $item) {
              if (!empty($item['promoId'])) {
                $usedPromoIds[] = $item['promoId'];
              }
              foreach (($item['menuPackageList'] ?? []) as $pkg) {
                if (!empty($pkg['promoId'])) {
                  $usedPromoIds[] = $pkg['promoId'];
                }
              }
            }
          }
          if (!empty($usedPromoIds)) {
            $requiredMemberPromo = \App\Models\MasterPromoModel::whereIn('id', array_unique($usedPromoIds))
              ->where('flag_required_member', true)
              ->first();
            if ($requiredMemberPromo) {
              throw new \Exception("This promo is for members only");
            }
          }
        }

        // Barrier tier promo (2026-09-30) -- promo yang mr_promo.flag_all_tiers=false WAJIB
        // member (yang udah dipilih) tier-nya cocok sama salah satu baris mr_promo_tier promo
        // itu. BEDA dari flag_required_member di atas: ini gak nolak "gak ada member" (itu udah
        // dicek terpisah), tapi nolak "ada member TAPI tier-nya gak cocok".
        //
        // (array) cast (2026-10-27) -- $datajson->listOrder bisa null (mis. "Book Table"/"Book &
        // Order" dari list-table page, useBookTable.js, yang booking meja kosong tanpa item sama
        // sekali -- payload-nya emang gak punya field listOrder). ValidatePromoTierBarrier() type
        // -hint-nya "array $listOrder" (strict), null lolos begitu aja bikin TypeError sebelum
        // sempat masuk function body. (array) null jadi [] -- function-nya sendiri udah benar
        // menangani list kosong (foreach 0 iterasi, $usedPromoIds tetap kosong).
        self::ValidatePromoTierBarrier((array) $datajson->listOrder, $order['member_id'] ?? null);

        //pengecekan stok
        $datagrouping_ = [];
        if ($datajson->listOrder) {
          foreach ($datajson->listOrder as $item) {

            $ida = $item['menuPricelistId'];
            $datagrouping_[$ida] = ($datagrouping_[$ida] ?? 0) + $item['qty'];


            // taxRate/serviceChargeRate (2026-11-18, BARU) -- di-resolve SERVER-SIDE dari
            // mr_tax by id (ResolveTaxRate()), BUKAN dipercaya mentah dari FE lagi. FE cukup
            // kirim taxId/serviceChargeId, rate-nya diabaikan walau tetap boleh dikirim.
            $taxRate = self::ResolveTaxRate($item['taxId'] ?? null);
            $serviceChargeRate = self::ResolveTaxRate($item['serviceChargeId'] ?? null);

            // calc (2026-11-18, BARU) -- netPrice/dpp/taxAmount/serviceChargeAmount/total
            // DIHITUNG ULANG SERVER (CalculateLineItem()), BUKAN dipercaya mentah dari FE lagi
            // (keputusan user -- field netPrice/dpp/taxAmount/serviceChargeAmount/total yang
            // dikirim FE di $item DIABAIKAN total walau tetap boleh dikirim, nilai yang dipakai
            // SELALU hasil $calc). Input yang tetap dipercaya dari FE: price/flagInclusiveTax/
            // discountAmount -- taxRate/serviceChargeRate sekarang di-resolve di atas, bukan
            // dari FE. Lihat CalculateLineItem().
            $calc = self::CalculateLineItem(
              (float) $item['price'],
              $taxRate,
              $serviceChargeRate,
              (bool) $item['flagInclusiveTax'],
              (float) $item['discountAmount']
            );

            // Log::info($item);
            $mumu = [
              'ulid' => (string)Str::ulid(),
              'order_number' => $order['order_number'],

              'pricelist_detail_id' => $item['menuPricelistId'],
              'menu_id' => $item['menuId'],
              'qty' => $item['qty'],
              'flag_inclusive_tax' => $item['flagInclusiveTax'],
              'price_pos' => $item['price'],

              'tax_id' => $item['taxId'],
              'tax_type' => $item['taxType'],
              'tax_rate' => $taxRate,
              'tax_amount' => $calc['taxAmount'],

              'service_charge_id' => $item['serviceChargeId'] ?? null,
              'service_charge_rate' => $serviceChargeRate,
              'service_charge_amount' => $calc['serviceChargeAmount'],

              'promo_id' => $item['promoId'],
              'is_free_item_promo' => $item['isFreeItemPromo'] ?? false,
              'discount_percent' => $item['discountPercent'],
              'discount_amount' => $item['discountAmount'],
              // dpp/net_dpp (2026-11-18, DI-RENAME, migration 2026_11_18_000002) -- kolom DB
              // net_price/dpp sekarang. net_price = SEBELUM diskon, dpp = SETELAH diskon
              // (basis pajak sesungguhnya -- makna "DPP" akuntansi yang benar).
              'net_price' => $calc['netPrice'],
              'dpp' => $calc['dpp'],

              'total' => $calc['total'],

              'notes' => $item['notes'],
              'batch' => $order['total_batch'],
            ];

            $order_detail[] = $mumu;

            foreach ($item['menuPackageList'] as $item_package) {
              $pkgTaxRate = self::ResolveTaxRate($item_package['taxId'] ?? null);
              $pkgServiceChargeRate = self::ResolveTaxRate($item_package['serviceChargeId'] ?? null);

              $calcPkg = self::CalculateLineItem(
                (float) $item_package['price'],
                $pkgTaxRate,
                $pkgServiceChargeRate,
                (bool) $item_package['flagInclusiveTax'],
                (float) $item_package['discountAmount']
              );

              $order_detail_package[] = [
                'ulid' => (string)Str::ulid(),
                'tr_order_detail_ulid' => $mumu['ulid'],

                'menu_package_id' => $item_package['menuPackageId'],
                'menu_id' => $item_package['menuId'],
                'qty' => $item_package['qty'],
                'flag_inclusive_tax' => $item_package['flagInclusiveTax'],
                'price_pos' => $item_package['price'],

                'tax_id' => $item_package['taxId'],
                'tax_type' => $item_package['taxType'],
                'tax_rate' => $pkgTaxRate,
                'tax_amount' => $calcPkg['taxAmount'],

                'service_charge_id' => $item_package['serviceChargeId'] ?? null,
                'service_charge_rate' => $pkgServiceChargeRate,
                'service_charge_amount' => $calcPkg['serviceChargeAmount'],

                'promo_id' => $item_package['promoId'],
                'discount_percent' => $item_package['discountPercent'],
                'discount_amount' => $item_package['discountAmount'],
                'net_price' => $calcPkg['netPrice'],
                'dpp' => $calcPkg['dpp'],
                'total' => $calcPkg['total'],

                'notes' => $item_package['notes'],
              ];
            }
          }
        }
        DB::beginTransaction();


        foreach ($datagrouping_ as $inded => $qtye) {
          $itemStockRow = DB::select("SELECT
                      mi.*
                      FROM mr_pricelist_detail mpd
                      join mr_item_conv mic on mic.id = mpd.item_conv_detail_id
                      JOIN mr_item mi on mi.id = mic.item_id

                      WHERE mpd.id = ?", [$inded]);

          if ($itemStockRow[0]->stok_qty > 0 && $itemStockRow[0]->flag_soldout != true) {

            if ($qtye > $itemStockRow[0]->stok_qty) {
              throw new \Exception("Stok yang tersedia untuk " . $itemStockRow[0]->name . " hanya " . $itemStockRow[0]->stok_qty);
            }

            MasterItemModel::where('id', $itemStockRow[0]->id)->update([
              'stok_qty' => ($itemStockRow[0]->stok_qty - $qtye),
              'flag_soldout' => (($itemStockRow[0]->stok_qty - $qtye) === 0)

            ]);
          }
        }

        TrOrderModel::create($order);
        TrOrderDetailModel::insert($order_detail);
        TrOrderDetailPackageModel::insert($order_detail_package);
        // RecalculateOrderTotals() (2026-11-18, BARU) -- hitung ulang sub_total/total_discount/
        // total_tax/total_service_charge/total_billing/total_item dari baris yang BENERAN
        // barusan di-insert, masih di dalam transaksi yang sama (sebelum commit).
        self::RecalculateOrderTotals($order['order_number']);
        DB::commit();


        // $data_order_query = TrOrderModel::where('order_number', $order_number)->first();

        // order dari Kiosk belum tentu jadi dimasak (nunggu bayar dulu) -- print kitchen-nya
        // ditunda sampai payment sukses (lihat PaymentServices::SavePayment()), bukan di sini.
        // print_checker_on (2026-11-04): print checker SEKARANG cuma jalan di save-order kalau
        // table section-nya di-set 'save_order' (default) -- kalau 'save_payment', print checker
        // dipindah ke PaymentServices::SavePayment() (sama pola kayak Kiosk, tapi general per
        // table section, bukan cuma Kiosk doang).
        if ($order['order_source'] !== 'kiosk' && $tablesectionnow->print_checker_on === 'save_order') {
          PrintServices::PrintTableChecker2($tablesectionnow->id, $order_number);
          PrintServices::PrintMainChecker2($tablesectionnow->id, $order_number);
          PrintServices::PrintPriparationStation($tablesectionnow->id, $order_number);

          TrOrderDetailModel::where('order_number', $order_number)->update([
            "done_print" => true,
          ]);
        }




        return $order_number;
      } else { //versi tambah pesanan tapi masih belum bisa cancel menu

        $order_number = $datajson->orderNumber;

        $current_table_order = TrOrderModel::where('order_number', $order_number)->whereIn('status', ['pending', 'hold'])
          ->first();
        $batch = $current_table_order->total_batch;
        // Log::info($datajson);
        // total_item/sub_total/total_discount/total_tax/total_service_charge/total_billing
        // (2026-11-18, UBAH ARSITEKTUR) -- sama alasan mode order baru di atas, SEMUA kolom
        // turunan item TIDAK LAGI ditulis dari $datajson di sini -- di-override
        // RecalculateOrderTotals() setelah insert/update item selesai (baca SEMUA baris order,
        // bukan cuma yang di request kali ini -- penting di mode ini karena order udah punya
        // baris lain sebelumnya). order_fee/delivery_cost TETAP dari FE (input manual header).
        $order_update = [
          "order_name" => $datajson->orderName,
          "pax" => $datajson->orderPax,
          'total_batch' => $batch + 1,
          "order_fee" => $datajson->orderFee ?? 0,
          "delivery_cost" => $datajson->deliveryCost ?? 0,
          "member_id" => $datajson->memberId,
        ];

        $order_detail = [];
        $order_detail_package = [];

        // Barrier flag_required_member (2026-09-30) -- sama pola kayak jalur order BARU di
        // atas, cuma member_id-nya diambil dari $order_update (request kali ini), bukan
        // $current_table_order (member bisa aja baru dipilih pas nambah pesanan ini).
        if (empty($order_update['member_id'])) {
          $usedPromoIds = [];
          foreach ($datajson->listOrder as $item) {
            if (!empty($item['promoId'])) {
              $usedPromoIds[] = $item['promoId'];
            }
            foreach (($item['menuPackageList'] ?? []) as $pkg) {
              if (!empty($pkg['promoId'])) {
                $usedPromoIds[] = $pkg['promoId'];
              }
            }
          }
          if (!empty($usedPromoIds)) {
            $requiredMemberPromo = \App\Models\MasterPromoModel::whereIn('id', array_unique($usedPromoIds))
              ->where('flag_required_member', true)
              ->first();
            if ($requiredMemberPromo) {
              throw new \Exception("This promo is for members only");
            }
          }
        }

        // Barrier tier promo (2026-09-30) -- sama pola kayak jalur order BARU di atas.
        self::ValidatePromoTierBarrier($datajson->listOrder, $order_update['member_id'] ?? null);

        //pengecekan stok
        $datagrouping_ = [];
        // hasNewItem (2026-11-04): true kalau di request ini ADA item BARU (belum punya ulid)
        // ditambahkan -- dipakai buat reset print_ke di bawah (lihat penjelasannya di situ).
        // Item yang CUMA diupdate (udah punya ulid, cabang if) TIDAK bikin ini true.
        $hasNewItem = false;
        foreach ($datajson->listOrder as $item) {


          // Log::info($item);
          if (isset($item['ulid'])) {
            // item sudah tersimpan sebelumnya — update qty juga karena split promo bisa mengubahnya
            //
            // calc (2026-11-18, BARU) -- sama arsitektur mode 1/2a: netPrice/dpp/taxAmount/
            // serviceChargeAmount/total DIHITUNG ULANG SERVER, bukan dipercaya dari FE. Beda
            // dari mode 1/2a: request body di sini TIDAK bawa price/taxRate/serviceChargeRate/
            // flagInclusiveTax (cuma 8 field "ringan" yang dikirim, lihat SAVE ORDER.md) --
            // jadi 4 input itu di-SELECT ULANG dari baris existing (gak berubah dari saat
            // pertama kali dibuat), digabung sama discountAmount BARU dari request ini.
            $existingRow = DB::table('tr_order_detail')
              ->where('ulid', $item['ulid'])
              ->whereNull('cancel_at')
              ->first(['price_pos', 'tax_rate', 'service_charge_rate', 'flag_inclusive_tax']);

            $calc = self::CalculateLineItem(
              (float) $existingRow->price_pos,
              (float) $existingRow->tax_rate,
              (float) ($existingRow->service_charge_rate ?? 0),
              (bool) $existingRow->flag_inclusive_tax,
              (float) $item['discountAmount']
            );

            DB::table('tr_order_detail')
              ->where('ulid', $item['ulid'])
              ->whereNull('cancel_at')
              ->update([
                'qty'                 => $item['qty'],
                'promo_id'            => $item['promoId'],
                'is_free_item_promo'  => $item['isFreeItemPromo'] ?? false,
                'discount_percent'    => $item['discountPercent'],
                'discount_amount'     => $item['discountAmount'],
                'net_price'           => $calc['netPrice'],
                'dpp'                 => $calc['dpp'],
                'tax_amount'          => $calc['taxAmount'],
                // service_charge_amount (2026-11-18, BARU) -- ikut di-update di sini, SAMA
                // alasan taxAmount: basisnya dpp, yang berubah kalau qty/diskon berubah (split
                // promo). serviceChargeId/serviceChargeRate TIDAK ikut di-update -- nilainya
                // per visit purpose (gak berubah karena promo/diskon), cukup tersimpan apa
                // adanya dari saat baris ini pertama kali dibuat.
                'service_charge_amount' => $calc['serviceChargeAmount'],
                // total (2026-11-18, BARU) -- ikut di-update, sama alasan taxAmount/
                // serviceChargeAmount: qty/discount berubah -> total baris ini pasti ikut
                // berubah, gak boleh nyangkut nilai lama.
                'total'               => $calc['total'],
              ]);
          } else {
            // item baru (belum punya ulid)
            $hasNewItem = true;
            $ida = $item['menuPricelistId'];
            $datagrouping_[$ida] = ($datagrouping_[$ida] ?? 0) + $item['qty'];

            // taxRate/serviceChargeRate (2026-11-18, BARU) -- sama pola mode order baru di
            // atas, di-resolve server-side dari mr_tax, bukan dari FE.
            $taxRate = self::ResolveTaxRate($item['taxId'] ?? null);
            $serviceChargeRate = self::ResolveTaxRate($item['serviceChargeId'] ?? null);

            // calc (2026-11-18, BARU) -- sama pola & alasan mode order baru di atas.
            $calc = self::CalculateLineItem(
              (float) $item['price'],
              $taxRate,
              $serviceChargeRate,
              (bool) $item['flagInclusiveTax'],
              (float) $item['discountAmount']
            );

            $mumu = [
              'ulid' => (string)Str::ulid(),
              'order_number' => $order_number,

              'pricelist_detail_id' => $item['menuPricelistId'],
              'menu_id' => $item['menuId'],
              'qty' => $item['qty'],
              'flag_inclusive_tax' => $item['flagInclusiveTax'],
              'price_pos' => $item['price'],

              'tax_id' => $item['taxId'],
              'tax_type' => $item['taxType'],
              'tax_rate' => $taxRate,
              'tax_amount' => $calc['taxAmount'],

              'service_charge_id' => $item['serviceChargeId'] ?? null,
              'service_charge_rate' => $serviceChargeRate,
              'service_charge_amount' => $calc['serviceChargeAmount'],

              'promo_id' => $item['promoId'],
              'is_free_item_promo' => $item['isFreeItemPromo'] ?? false,
              'discount_percent' => $item['discountPercent'],
              'discount_amount' => $item['discountAmount'],
              // dpp/net_dpp (2026-11-18, DI-RENAME, migration 2026_11_18_000002) -- kolom DB
              // net_price/dpp sekarang. net_price = SEBELUM diskon, dpp = SETELAH diskon
              // (basis pajak sesungguhnya -- makna "DPP" akuntansi yang benar).
              'net_price' => $calc['netPrice'],
              'dpp' => $calc['dpp'],

              'total' => $calc['total'],

              'notes' => $item['notes'],
              'batch' => $batch + 1,
            ];
            $order_detail[] = $mumu;

            foreach ($item['menuPackageList'] as $item_package) {
              $pkgTaxRate = self::ResolveTaxRate($item_package['taxId'] ?? null);
              $pkgServiceChargeRate = self::ResolveTaxRate($item_package['serviceChargeId'] ?? null);

              $calcPkg = self::CalculateLineItem(
                (float) $item_package['price'],
                $pkgTaxRate,
                $pkgServiceChargeRate,
                (bool) $item_package['flagInclusiveTax'],
                (float) $item_package['discountAmount']
              );

              $order_detail_package[] = [
                'ulid' => (string)Str::ulid(),
                'tr_order_detail_ulid' => $mumu['ulid'],

                'menu_package_id' => $item_package['menuPackageId'],
                'menu_id' => $item_package['menuId'],
                'qty' => $item_package['qty'],
                'flag_inclusive_tax' => $item_package['flagInclusiveTax'],
                'price_pos' => $item_package['price'],

                'tax_id' => $item_package['taxId'],
                'tax_type' => $item_package['taxType'],
                'tax_rate' => $pkgTaxRate,
                'tax_amount' => $calcPkg['taxAmount'],

                'service_charge_id' => $item_package['serviceChargeId'] ?? null,
                'service_charge_rate' => $pkgServiceChargeRate,
                'service_charge_amount' => $calcPkg['serviceChargeAmount'],

                'promo_id' => $item_package['promoId'],
                'discount_percent' => $item_package['discountPercent'],
                'discount_amount' => $item_package['discountAmount'],
                'net_price' => $calcPkg['netPrice'],
                'dpp' => $calcPkg['dpp'],
                'total' => $calcPkg['total'],

                'notes' => $item_package['notes'],
              ];
            }
          }
        }

        // Log::info("batch sebelumnya :" . $batch);
        DB::beginTransaction();

        foreach ($datagrouping_ as $inded => $qtye) {
          $itemStockRow = DB::select("SELECT
                      mi.*
                      FROM mr_pricelist_detail mpd 
                      join mr_item_conv mic on mic.id = mpd.item_conv_detail_id
                      JOIN mr_item mi on mi.id = mic.item_id

                      WHERE mpd.id = ?", [$inded]);

          if ($itemStockRow[0]->stok_qty > 0 && $itemStockRow[0]->flag_soldout != true) {

            if ($qtye > $itemStockRow[0]->stok_qty) {
              throw new \Exception("Stok yang tersedia untuk " . $itemStockRow[0]->name . " hanya " . $itemStockRow[0]->stok_qty);
            }

            MasterItemModel::where('id', $itemStockRow[0]->id)->update([
              'stok_qty' => ($itemStockRow[0]->stok_qty - $qtye),
              'flag_soldout' => (($itemStockRow[0]->stok_qty - $qtye) === 0)
            ]);
          }
        }

        // print_ke reset (2026-11-04): begitu ada item BARU ditambahkan ke order yang udah
        // pernah di-print bill (print_ke > 0), bill lama itu udah gak merepresentasikan isi
        // order terbaru -- reset ke 0 biar status table (OrderlistServices::resolveStatusTable)
        // balik ke "dining" lagi (bukan nyangkut "billed" selamanya). SENGAJA cuma kalau ada
        // item BARU (bukan tiap kali save order) -- update item yang SUDAH ADA doang (qty/promo/
        // discount) gak bikin bill lama jadi gak valid, jadi print_ke dibiarkan apa adanya.
        // Konsekuensi yang disetujui: label "COPY n" di re-print bill (PrintServices.php, TIDAK
        // diubah) ikut mulai dari 1 lagi setelah ini -- wajar, bukan bug.
        if ($hasNewItem) {
          $order_update['print_ke'] = 0;
        }

        TrOrderModel::where('order_number', $order_number)
          ->update($order_update);
        if (count($order_detail) > 0) {

          TrOrderDetailModel::insert($order_detail);
          TrOrderDetailPackageModel::insert($order_detail_package);

          // sama kayak order baru -- Kiosk nunda print kitchen sampai payment sukses.
          // print_checker_on (2026-11-04): sama kayak jalur order baru di atas -- cuma jalan di
          // save-order kalau table section-nya 'save_order' (default).
          $tablesection_current_order = TableSectionModel::where('id', $current_table_order->table_section_id)->first();
          if ($current_table_order->order_source !== 'kiosk' && $tablesection_current_order && $tablesection_current_order->print_checker_on === 'save_order') {
            PrintServices::PrintTableChecker2($current_table_order->table_section_id, $current_table_order->order_number);
            PrintServices::PrintMainChecker2($current_table_order->table_section_id, $current_table_order->order_number);
            PrintServices::PrintPriparationStation($current_table_order->table_section_id, $current_table_order->order_number);

            TrOrderDetailModel::where('order_number', $current_table_order->order_number)->update([
              "done_print" => true,
            ]);
          }
        }

        //sync update
        TrOrderModel::where("order_number", $order_number)->update(["sync_at" => null]);
        TrOrderDetailModel::where("order_number", $order_number)->update(["sync_at" => null]);
        // TrOrderDetailPackageModel::where("order_number", $order_number)->update(["sync_at" => null]);
        //

        // RecalculateOrderTotals() (2026-11-18, BARU) -- baca SEMUA baris tr_order_detail/
        // tr_order_detail_package milik order ini (bukan cuma $datajson->listOrder yang di
        // mode ini cuma berisi baris yang berubah/baru kali ini), hitung ulang header. Dipanggil
        // PALING TERAKHIR di sini -- baris baru (insert di atas) & baris existing (update mode
        // 2b di awal fungsi, sudah ter-commit duluan karena di luar transaksi ini) dua-duanya
        // sudah tersimpan di titik ini.
        self::RecalculateOrderTotals($order_number);

        DB::commit();

        return $order_number;
      }
    } catch (\Throwable $e) {
      DB::rollBack();
      throw $e;
    }
  }



  /**
   * Naming note: `menuId` in the returned listOrder rows is `tr_order_detail.menu_id`, which is
   * actually `mr_item_conv.id` (see join below) — `menuName`/`menuShortName` are resolved through
   * `mr_item` since `mr_item_conv` itself carries no name.
   */
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
      tro.total_service_charge as totalServiceCharge,
      tro.order_fee as orderFee,
      tro.delivery_cost as deliveryCost,
      tro.total_billing as totalBilling,
      tro.total_discount as totalDiscount,
      tro.table_section_id as tableSectionId,
      mts.name as tableSectionName,
      tro.table_id as tableId,
      mt.name as tableName,
      tro.order_date as orderDate,
      tro.order_in as orderIn,
      tro.status as status,
      tro.member_id as memberId,
      mm.name as memberName,
      mm.phone_number as memberPhoneNumber

      FROM tr_order tro
      JOIN mr_visit_purpose mvp on mvp.id = tro.visit_purpose_id
      LEFT JOIN mr_table_section mts on mts.id = tro.table_section_id
      LEFT JOIN mr_table mt on mt.id = tro.table_id
      LEFT JOIN mr_member mm on mm.id = tro.member_id
      WHERE tro.order_number = ? ", [$order_number]);
      // WHERE tro.order_number = ? and tro.status  IN ('pending', 'hold')", [$order_number]);

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
      trod.price_pos as price,
      trod.tax_id as taxId,
      trod.tax_type as taxType,
      trod.tax_rate as taxRate,
      trod.tax_amount as taxAmount,
      trod.service_charge_id as serviceChargeId,
      trod.service_charge_rate as serviceChargeRate,
      trod.service_charge_amount as serviceChargeAmount,
      trod.promo_id as promoId,
      mp.name as promoName,
      trod.is_free_item_promo as isFreeItemPromo,
      trod.discount_percent as discountPercent,
      trod.discount_amount as discountAmount,
      -- netPrice/dpp (2026-11-18, netDpp DIHAPUS) -- response field sekarang pakai nama kolom
      -- APA ADANYA (net_price->netPrice, dpp->dpp), gak ada lagi alias lama netDpp. Backend/API
      -- sudah diselaraskan penuh ke nama kolom baru -- rename pemakai di posv1-vue (FE) MENYUSUL
      -- TERPISAH (scope sesi ini cuma backend), lihat catatan Status di VIEW ORDER.md.
      trod.net_price as netPrice,
      trod.dpp as dpp,
      trod.total,
      trod.cancel_at as cancelAt,
      trod.cancel_notes as cancelNotes,
      mri.id as itemIdReal,
      mri.category_id as categoryId,
      mri.subcategory_id as subCategoryId

      FROM tr_order_detail trod
      JOIN mr_item_conv mric on mric.id = trod.menu_id
      JOIN mr_item mri on mri.id = mric.item_id
      LEFT JOIN mr_promo mp on mp.id = trod.promo_id

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
        trodp.price_pos as price,
        trodp.tax_id as taxId,
        trodp.tax_type as taxType,
        trodp.tax_rate as taxRate,
        trodp.tax_amount as taxAmount,
        trodp.service_charge_id as serviceChargeId,
        trodp.service_charge_rate as serviceChargeRate,
        trodp.service_charge_amount as serviceChargeAmount,
        trodp.promo_id as promoId,
        trodp.discount_percent as discountPercent,
        trodp.discount_amount as discountAmount,
        -- netPrice/dpp (2026-11-18, netDpp DIHAPUS) -- sama alasan item1 di atas.
        trodp.net_price as netPrice,
        trodp.dpp as dpp,
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
      tro.total_service_charge as totalServiceCharge,
      tro.order_fee as orderFee,
      tro.delivery_cost as deliveryCost,
      tro.total_billing as totalBilling,
      tro.total_discount as totalDiscount

      FROM tr_order tro
      JOIN mr_visit_purpose mvp on mvp.id = tro.visit_purpose_id
      WHERE tro.order_number = ? and tro.status = ?", [$order_number, 'pending']);

      return $data_order[0];
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public static function CancelOrder(string $order_number, $notes = '')
  {
    try {


      //hanya order pending dan hold 
      $order = TrOrderModel::where("order_number", $order_number)->whereIn("status", ['pending', 'hold'])->first();

      if (!$order) {
        throw new \Exception('not a pending / hold order!');
      }


      TrOrderModel::where('order_number', $order_number)->update([
        "status" => "cancel",
        "cancel_at" => now(),
        "cancel_notes" => $notes,
        "order_out" => now(),
        "sync_at" => null
      ]);

      return "cancel order success!";
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public static function listTableBySection(int $tablesection_id)
  {
    try {

      $list_data = DB::select("
                    SELECT
                    mt.*,
                    tro.order_number,
                    tro.order_in,
                    tro.status

                    FROM mr_table mt
                    LEFT JOIN tr_order tro on tro.table_id = mt.id AND tro.status IN ('pending', 'hold')
                    WHERE mt.table_section_id = ? AND tro.order_number is null", [$tablesection_id]);
      return $list_data;
    } catch (\Throwable $err) {
      throw $err;
    }
  }

  public static function listTableBySectionAll(int $tablesection_id)
  {
    try {

      $list_data = DB::select("
                    SELECT
                    mt.*,
                    tro.order_number,
                    tro.order_in,
                    tro.status

                    FROM mr_table mt
                    LEFT JOIN tr_order tro on tro.table_id = mt.id AND tro.status IN ('pending', 'hold')
                    WHERE mt.table_section_id = ? ", [$tablesection_id]);
      return $list_data;
    } catch (\Throwable $err) {
      throw $err;
    }
  }

  public static function SaveMoveTable(string $order_number, $tablesection_id, $table_id = null)
  {
    try {

      $orderdetail = TrOrderModel::where('order_number', $order_number)
        ->whereIn('status', ['pending', 'hold'])->first();
      $tablesection = TableSectionModel::where('id', $tablesection_id)->first();





      if (!$orderdetail) {
        throw new \Exception('order not found! ');
      }

      $perubahan = [
        "table_section_id" => $tablesection->id,
        "sync_at" => null
      ];
      if ($tablesection->type == 'dinein') {
        if ($table_id == null) {
          throw new \Exception('table must be selected! ');
        }


        if ($tablesection->can_hold) {
          $perubahan["status"] = 'hold';
        } else {
          $perubahan["status"] = 'pending';
        }

        if ($table_id != null) {
          $exists = DB::selectOne("
          SELECT
          mt.*,
          tro.order_number,
          tro.order_in,
          tro.status
          
          FROM mr_table mt
          JOIN tr_order tro on tro.table_id = mt.id AND tro.status IN ('pending', 'hold')
          WHERE mt.id = ?", [$table_id]);
          if ($exists) {
            throw new \Exception('Table still has an active order!');
          }
        }



        $perubahan["table_id"] = $table_id;
        $perubahan["order_type"] = $tablesection->type;
        TrOrderModel::where('order_number', $orderdetail->order_number)->update($perubahan);
      } else if ($tablesection->type == 'takeaway') {

        //takeway moveorder yang canhold langsung berubah ke hold dari yang pending
        if ($tablesection->can_hold) {
          $perubahan["status"] = 'hold';
        } else {
          $perubahan["status"] = 'pending';
        }


        $perubahan["table_id"] = null;
        $perubahan["order_type"] = $tablesection->type;
        TrOrderModel::where('order_number', $orderdetail->order_number)->update($perubahan);
      }
      return "Move Table Success!";
    } catch (\Throwable $err) {
      throw $err;
    }
  }

  /**
   * Recompute tr_order.sub_total/total_discount/total_tax/total_service_charge/grand_total/
   * rounding_amount/total_billing/total_item from its current tr_order_detail +
   * tr_order_detail_package rows (mirrors orderPage.vue's hitungTotalFromItemQty(), DIPERLUAS
   * 2026-11-18 ikut service charge -- lihat "Formula Kalkulasi Harga" di SAVE ORDER.md).
   * Dipanggil dari 2 tempat: SaveMoveItem() (karena moving rows antar order gak otomatis sync
   * kolom turunan ini) DAN SaveOrder() (2026-11-18, BARU -- header order sekarang DIHITUNG
   * ULANG SERVER dari SEMUA baris order, bukan dipercaya mentah dari FE lagi, sama arsitektur
   * CalculateLineItem() per baris).
   *
   * orderFee/deliveryCost SENGAJA TIDAK disentuh fungsi ini (bukan turunan dari baris item,
   * murni input FE level header) -- caller (SaveOrder()) yang nulis 2 kolom itu terpisah
   * SEBELUM manggil fungsi ini, order_fee/delivery_cost di sini cuma di-passthrough (dibaca
   * ulang dari tr_order yang udah ke-update caller) biar ikut ke grand_total.
   *
   * grand_total/rounding_amount/total_billing (2026-11-20, BARU) -- rantai nilai akhir order:
   *   grand_total     = akumulasi semua baris item + service_charge + order_fee + delivery_cost,
   *                      SEBELUM dibulatkan (ini yang dulu namanya $total_billing di fungsi ini).
   *   rounding_amount = selisih pembulatan, dihitung dari setting mr_branch_setting milik
   *                      branch order ini (flag_rounding + rounding_nearst_value). Kalau
   *                      flag_rounding false, atau rounding_nearst_value null/<=0, atau
   *                      grand_total sudah pas kelipatan (sisa bagi == 0) -> rounding_amount = 0.
   *                      Rumus (kalau syarat di atas terpenuhi):
   *                        sisa = fmod(grand_total, rounding_nearst_value)
   *                        rounding_amount = rounding_nearst_value - sisa
   *                      fmod() dipakai (bukan operator %) karena rounding_nearst_value/
   *                      grand_total nilai desimal, % PHP membuang ke integer dulu.
   *   total_billing   = grand_total + rounding_amount (nilai final yang ditagih).
   * rounding_mode (kolom udah ada di mr_branch_setting) BELUM dipakai di sini -- scope sesi ini
   * cuma 1 mode pembulatan (ke rounding_nearst_value terdekat ke ATAS), mode lain menyusul kalau
   * dibutuhkan.
   */
  // public (bukan private) -- 2026-11-18, dipakai juga dari KioskController::editExistingOrder()
  // supaya header totals Kiosk konsisten dengan SaveOrder() (1 sumber kebenaran).
  public static function RecalculateOrderTotals(string $order_number)
  {
    $order_detail = DB::select("
      SELECT qty, price_pos, tax_rate, service_charge_rate, flag_inclusive_tax, discount_amount
      FROM tr_order_detail
      WHERE order_number = ? AND cancel_at IS NULL
    ", [$order_number]);

    $order_detail_package = DB::select("
      SELECT trodp.qty, trodp.price_pos, trodp.tax_rate, trodp.service_charge_rate, trodp.flag_inclusive_tax, trodp.discount_amount, trod.qty as parent_qty
      FROM tr_order_detail_package trodp
      JOIN tr_order_detail trod ON trod.ulid = trodp.tr_order_detail_ulid
      WHERE trod.order_number = ? AND trod.cancel_at IS NULL
    ", [$order_number]);

    $total_item = 0;
    $sub_total = 0;
    $total_discount = 0;
    $total_tax = 0;
    $total_service_charge = 0;
    $grand_total = 0;

    foreach ($order_detail as $row) {
      $total_item += $row->qty;
      $calc = self::CalculateLineItem(
        (float) $row->price_pos,
        (float) $row->tax_rate,
        (float) ($row->service_charge_rate ?? 0),
        (bool) $row->flag_inclusive_tax,
        (float) $row->discount_amount
      );

      $sub_total += $row->qty * $calc['netPrice'];
      $total_discount += $row->qty * $row->discount_amount;
      $total_tax += $row->qty * $calc['taxAmount'];
      $total_service_charge += $row->qty * $calc['serviceChargeAmount'];
      $grand_total += $row->qty * $calc['total'];
    }

    foreach ($order_detail_package as $row) {
      $qty = $row->parent_qty * $row->qty;
      $calc = self::CalculateLineItem(
        (float) $row->price_pos,
        (float) $row->tax_rate,
        (float) ($row->service_charge_rate ?? 0),
        (bool) $row->flag_inclusive_tax,
        (float) $row->discount_amount
      );

      $sub_total += $qty * $calc['netPrice'];
      $total_discount += $qty * $row->discount_amount;
      $total_tax += $qty * $calc['taxAmount'];
      $total_service_charge += $qty * $calc['serviceChargeAmount'];
      $grand_total += $qty * $calc['total'];
    }

    // orderFee/deliveryCost (2026-11-18, BARU) -- input FE level header, bukan turunan item,
    // dibaca ulang dari tr_order (caller udah nulis kolom ini SEBELUM manggil fungsi ini) biar
    // ikut masuk grand_total. 0 kalau baris order gak ketemu (defensif, seharusnya selalu ada).
    $orderRow = TrOrderModel::where('order_number', $order_number)->first(['order_fee', 'delivery_cost', 'branch_id']);
    $grand_total += ($orderRow->order_fee ?? 0) + ($orderRow->delivery_cost ?? 0);

    // rounding_amount (2026-11-20, BARU) -- baca setting rounding branch pemilik order ini.
    $rounding_amount = 0;
    $branchSetting = MasterBranchSettingModel::where('branch_id', $orderRow->branch_id ?? null)->first(['flag_rounding', 'rounding_nearst_value']);
    if ($branchSetting && $branchSetting->flag_rounding && (float) $branchSetting->rounding_nearst_value > 0) {
      $nearst = (float) $branchSetting->rounding_nearst_value;
      $sisa = fmod($grand_total, $nearst);
      if ($sisa != 0) {
        $rounding_amount = $nearst - $sisa;
      }
    }

    $total_billing = $grand_total + $rounding_amount;

    // sync_at di-null-kan (2026-09-22) -- sub_total/total_tax/total_billing/dst ini data
    // finansial inti yang dipush ke ERP, kalau baris ini udah sempat ke-push duluan (sync_at
    // udah terisi) sebelum recalculate ini jalan, tanpa di-null-in ulang pushDataOrder() gak
    // bakal pernah nemu baris ini lagi -- ERP nyangkut di angka lama yang udah gak akurat abis
    // cancel item/move item. Pola sama kayak CancelOrder()/SaveMoveTable()/SavePayment()/Void().
    TrOrderModel::where('order_number', $order_number)->update([
      'sub_total' => $sub_total,
      'total_discount' => $total_discount,
      'total_tax' => $total_tax,
      'total_service_charge' => $total_service_charge,
      'grand_total' => $grand_total,
      'rounding_amount' => $rounding_amount,
      'total_billing' => $total_billing,
      'total_item' => $total_item,
      'sync_at' => null,
    ]);
  }

  public static function CancelOrderDetail(string $ulid, string $notes = '')
  {
    try {
      $orderdetail = TrOrderDetailModel::where('ulid', $ulid)->whereNull('cancel_at')->first();

      if (!$orderdetail) {
        throw new \Exception('item not found / already cancelled!');
      }

      TrOrderDetailModel::where('ulid', $ulid)->update([
        "cancel_at" => now(),
        "cancel_notes" => $notes,
        "sync_at" => null,
      ]);

      self::RecalculateOrderTotals($orderdetail->order_number);

      return "cancel item success!";
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public static function SaveMoveItem(string $order_number_before, int $visit_purpose_id, int $to_tablesection_id, int $to_table_id, array $list_item)
  {
    try {


      $tablesectionquery = TableSectionModel::where('id', $to_tablesection_id)->first();
      if (!$tablesectionquery) {
        throw new \Exception("table section not found!");
      }
      if ($tablesectionquery->type == 'dinein') {

        //cek table apakah punya order number
        $table_detail = collect(DB::select("
                    SELECT
                    mt.*,
                    tro.order_number,
                    tro.order_in,
                    tro.status

                    FROM mr_table mt
                    LEFT JOIN tr_order tro on tro.table_id = mt.id AND tro.status IN ('pending', 'hold')
                    WHERE mt.id = ? ", [$to_table_id]))->first();

        //jika belum punya ordernumber
        if ($table_detail->order_number == null) {
          DB::beginTransaction();

          $data = new stdClass;
          $data->orderNumber = '';
          $data->customerPhoneNumber = '';

          $data->orderName = "";
          $data->memberId = null;
          $data->listOrder = [];
          $data->orderPax = 1;
          $data->orderSource = "pos";
          $data->subTotal = 0;
          $data->tableId = $table_detail->id;
          $data->tableSectionId = $table_detail->table_section_id;
          $data->totalBilling = 0;
          $data->totalItem = 0;
          $data->totalTax = 0;
          $data->visitPurposeId = $visit_purpose_id;
          $order_number_new = self::SaveOrder($data);

          if ($order_number_new == '') {
            throw new \Exception('failed to create order number!');
          }

          // $list_item_after_filter = [];
          $list_item_ = json_decode(json_encode($list_item));
          foreach ($list_item_ as $item) {
            $itemquery = TrOrderDetailModel::where('ulid', $item->ulid)->first();

            if ($item->moveQty > 0 && $itemquery->cancel_at == null) {
              // $list_item_after_filter[] = $item;
              $sisa = ($itemquery->qty - $item->moveQty);

              //jika di pindah semua
              if ($sisa == 0) {
                $itemquery->update([
                  'order_number' => $order_number_new,
                  'sync_at' => null,
                ]);

                //jika di pindah sebagian
              } else {
                // update yang lama
                TrOrderDetailModel::where('ulid', $itemquery->ulid)->update([
                  'qty' => $sisa,
                  'sync_at' => null,
                ]);

                // buat baru
                $yangbaru = $itemquery->replicate();
                $yangbaru->ulid = (string) Str::ulid();
                $yangbaru->order_number = $order_number_new;
                $yangbaru->qty = $item->moveQty;
                $yangbaru->save();

                $list_itempackagequery = TrOrderDetailPackageModel::where(
                  'tr_order_detail_ulid',
                  $itemquery->ulid
                )->get();

                if (count($list_itempackagequery) > 0) {
                  foreach ($list_itempackagequery as $itempackage) {
                    $itempackage_baru = $itempackage->replicate();

                    $itempackage_baru->ulid = (string) Str::ulid();
                    $itempackage_baru->tr_order_detail_ulid = $yangbaru->ulid;
                    $itempackage_baru->save();
                  }
                }
              }
            }
          }

          // if (count($list_item_after_filter) == 0) {
          //   throw new \Exception('tidak ada item yang dipilih!');
          // }

          // jika order list telah di pindah ke yang baru yang lama orderanya di tutup
          self::RecalculateOrderTotals($order_number_new);

          $datalistitemorder_lama = TrOrderDetailModel::where('order_number', $order_number_before)->get();
          Log::info($datalistitemorder_lama);

          if (count($datalistitemorder_lama) == 0) {

            TrOrderModel::where('order_number', $order_number_before)->update([
              'status' => 'moved',
              'moved_at' => now(),
              'moved_by' => null,
              'sync_at' => null,
            ]);
            Log::info('success');
          } else {
            self::RecalculateOrderTotals($order_number_before);
          }

          DB::commit();
          return "success move item";
          // sudah punya order number
        } else {
          // $list_item_after_filter = [];
          $list_item_ = json_decode(json_encode($list_item));
          foreach ($list_item_ as $item) {
            $itemquery = TrOrderDetailModel::where('ulid', $item->ulid)->first();

            if ($item->moveQty > 0 && $itemquery->cancel_at == null) {
              // $list_item_after_filter[] = $item;
              $sisa = ($itemquery->qty - $item->moveQty);

              //jika di pindah semua
              if ($sisa == 0) {
                $itemquery->update([
                  'order_number' => $table_detail->order_number,
                  'sync_at' => null,
                ]);

                //jika di pindah sebagian
              } else {
                // update yang lama
                TrOrderDetailModel::where('ulid', $itemquery->ulid)->update([
                  'qty' => $sisa,
                  'sync_at' => null,
                ]);

                // buat baru
                $yangbaru = $itemquery->replicate();
                $yangbaru->ulid = (string) Str::ulid();
                $yangbaru->order_number = $table_detail->order_number;
                $yangbaru->qty = $item->moveQty;
                $yangbaru->save();

                $list_itempackagequery = TrOrderDetailPackageModel::where(
                  'tr_order_detail_ulid',
                  $itemquery->ulid
                )->get();

                if (count($list_itempackagequery) > 0) {
                  foreach ($list_itempackagequery as $itempackage) {
                    $itempackage_baru = $itempackage->replicate();

                    $itempackage_baru->ulid = (string) Str::ulid();
                    $itempackage_baru->tr_order_detail_ulid = $yangbaru->ulid;
                    $itempackage_baru->save();
                  }
                }
              }
            }
          }

          // jika order list telah di pindah ke yang baru yang lama orderanya di tutup
          self::RecalculateOrderTotals($table_detail->order_number);

          $datalistitemorder_lama = TrOrderDetailModel::where('order_number', $order_number_before)->get();
          if (count($datalistitemorder_lama) == 0) {
            TrOrderModel::where('order_number', $order_number_before)->update([
              'status' => 'moved',
              'moved_at' => now(),
              'moved_by' => null,
              'sync_at' => null,
            ]);
          } else {
            self::RecalculateOrderTotals($order_number_before);
          }

          // if (count($list_item_after_filter) == 0) {
          //   throw new \Exception('tidak ada item yang dipilih!');
          // }

          DB::commit();
          return "success move item";
        }
      } elseif ($tablesectionquery->type == 'takeaway') {

        DB::beginTransaction();

        $data = new stdClass;
        $data->orderNumber = '';
        $data->customerPhoneNumber = '';

        $data->orderName = "";
        $data->memberId = null;
        $data->listOrder = [];
        $data->orderPax = 1;
        $data->orderSource = "pos";
        $data->subTotal = 0;
        $data->tableId = null;
        $data->tableSectionId = $to_tablesection_id;
        $data->totalBilling = 0;
        $data->totalItem = 0;
        $data->totalTax = 0;
        $data->visitPurposeId = $visit_purpose_id;
        $order_number_new = self::SaveOrder($data);

        if ($order_number_new == '') {
          throw new \Exception('failed to create order number!');
        }

        // $list_item_after_filter = [];
        $list_item_ = json_decode(json_encode($list_item));
        foreach ($list_item_ as $item) {
          $itemquery = TrOrderDetailModel::where('ulid', $item->ulid)->first();

          if ($item->moveQty > 0 && $itemquery->cancel_at == null) {
            // $list_item_after_filter[] = $item;
            $sisa = ($itemquery->qty - $item->moveQty);

            //jika di pindah semua
            if ($sisa == 0) {
              $itemquery->update([
                'order_number' => $order_number_new,
                'sync_at' => null,
              ]);

              //jika di pindah sebagian
            } else {
              // update yang lama
              TrOrderDetailModel::where('ulid', $itemquery->ulid)->update([
                'qty' => $sisa,
                'sync_at' => null,
              ]);

              // buat baru
              $yangbaru = $itemquery->replicate();
              $yangbaru->ulid = (string) Str::ulid();
              $yangbaru->order_number = $order_number_new;
              $yangbaru->qty = $item->moveQty;
              $yangbaru->save();

              $list_itempackagequery = TrOrderDetailPackageModel::where(
                'tr_order_detail_ulid',
                $itemquery->ulid
              )->get();

              if (count($list_itempackagequery) > 0) {
                foreach ($list_itempackagequery as $itempackage) {
                  $itempackage_baru = $itempackage->replicate();

                  $itempackage_baru->ulid = (string) Str::ulid();
                  $itempackage_baru->tr_order_detail_ulid = $yangbaru->ulid;
                  $itempackage_baru->save();
                }
              }
            }
          }
        }

        // jika order list telah di pindah ke yang baru yang lama orderanya di tutup
        self::RecalculateOrderTotals($order_number_new);

        $datalistitemorder_lama = TrOrderDetailModel::where('order_number', $order_number_before)->get();
        if (count($datalistitemorder_lama) == 0) {
          TrOrderModel::where('order_number', $order_number_before)->update([
            'status' => 'moved',
            'moved_at' => now(),
            'moved_by' => null,
            'sync_at' => null,
          ]);
        } else {
          self::RecalculateOrderTotals($order_number_before);
        }

        // if (count($list_item_after_filter) == 0) {
        //   throw new \Exception('tidak ada item yang dipilih!');
        // }

        DB::commit();
        return "success move item";
      }
    } catch (\Throwable $e) {
      DB::rollBack();
      Log::info($e);
      throw $e;
    }
  }

  private static function getChasierName($request): ?string
  {
    try {
      $token = $request->bearerToken();
      if (!$token) return null;
      $session = SessionModel::where('session_id', $token)->first();
      if (!$session) return null;
      $user = json_decode($session->data);
      return $user->fullname ?? $user->username ?? null;
    } catch (\Throwable $e) {
      return null;
    }
  }

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

  // RecordRemoveItemBeforeSave: audit trail item yang dihapus kasir dari cart lokal SEBELUM
  // order pernah tersimpan ke server sama sekali (removeItemOrder() di orderPage.vue -- tombol
  // Trash2, cuma muncul kalau item belum punya `ulid`). $order_number nullable (2026-09-18,
  // disesuaikan): NULL kalau ini order BARU yang belum pernah tersimpan sama sekali (create baru,
  // dataOrderObject.orderNumber == ''), TERISI kalau kasir lagi EDIT order pending yang UDAH ada
  // nomornya (buka lewat ViewOrder()) terus nambah menu baru ke situ (item baru ini belum punya
  // `ulid` sendiri, tapi order induknya udah py order_number) lalu batal sebelum re-save -- biar
  // baris audit ini tetap bisa dilacak balik ke order mana asalnya, bukan cuma "item apa" doang.
  // dayshift_ulid diresolve dari shift harian yang aktif SEKARANG (DaySiftModel dayout_time masih
  // null) -- null kalau kebetulan gak ada shift aktif pas kejadian ini terjadi (harusnya jarang,
  // tapi jangan sampai malah gagal nyimpen audit-nya).
  // branch_id (2026-09-18, susulan audit) -- sama pola kayak tr_order/tr_dayshift, WAJIB diisi
  // karena APIANDORDER resolve company_id dari kolom ini pas push (PushPOSRemoveItemBeforeSave()).
  // $packages opsional -- sub-item package yang ikut lenyap bareng item HEAD ini.
  public static function RecordRemoveItemBeforeSave(Request $request, int $item_conv_id, $qty, ?string $order_number = null, array $packages = []): void
  {
    // guard: item_conv_id 0/kosong (request malformed/frontend ngirim nilai falsy) gak usah
    // dicatat -- baris audit yang gak nunjuk ke item manapun gak ada gunanya.
    if ($item_conv_id <= 0) {
      return;
    }

    try {
      $branch = BranchModel::first();
      $dayshift = DaySiftModel::where('dayout_time', null)->orderBy('ulid', 'desc')->first();
      $created_by = self::getLoggedInUserId($request);

      $header = TrRemoveItemBeforeSaveModel::create([
        'branch_id' => $branch->id ?? null,
        'order_number' => $order_number,
        'dayshift_ulid' => $dayshift->ulid ?? null,
        'item_conv_id' => $item_conv_id,
        'qty' => $qty,
        'created_at' => now(),
        'created_by' => $created_by,
      ]);

      foreach ($packages as $package) {
        TrRemoveItemBeforeSavePackageModel::create([
          'tr_remove_item_before_save_ulid' => $header->ulid,
          'item_conv_id' => $package['item_conv_id'] ?? null,
          'qty' => $package['qty'] ?? 0,
        ]);
      }
    } catch (\Throwable $e) {
      // sengaja gak dilempar -- ini audit trail, bukan jalur kritikal transaksi. Kalau gagal
      // nyimpen (mis. request malformed), jangan sampai bikin kasir keliatan error, cukup dicatat.
      Log::info($e);
    }
  }
}
