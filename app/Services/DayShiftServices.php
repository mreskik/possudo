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
use Carbon\Carbon;
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

  // recapAmount (2026-11-24) -- baca 1 elemen sales_recapitulation berdasarkan teks "key"-nya,
  // BUKAN index posisi. Sebelumnya print/V3 baca pakai index literal ($recap[13], [7], [8], [9])
  // dan index-nya bergeser 1 dari urutan array yang sebenarnya (PB1 kebaca VAT, VAT kebaca Gross
  // Sales, Gross Sales kebaca Pax Total, dst). Dengan lookup by key, urutan array boleh berubah
  // tanpa merusak pembacanya. Key tidak ketemu -> 0 (bukan error).
  public static function recapAmount($recap, string $key): float
  {
    foreach ($recap ?? [] as $row) {
      if (($row['key'] ?? null) === $key) {
        return (float) ($row['amount'] ?? 0);
      }
    }
    return 0.0;
  }

  // GetReportOrderList (2026-11-24) -- daftar order untuk 1 window laporan (dayshift/shift),
  // dibedakan lewat STATUS order + kolom waktu yang SESUAI statusnya: paid -> order_out,
  // cancel -> cancel_at, void -> void_at. order_in/dayshift_ulid TIDAK dipakai (order hold boleh
  // lintas dayshift, dan order yang dibuka di shift 1 tapi dibayar di shift 2 harus masuk shift
  // 2). $start inklusif (>=), $end eksklusif (<) dan boleh null (= sampai sekarang).
  // pending/hold SENGAJA tidak lewat sini -- diambil global (outstanding) oleh pemanggil.
  // other_cost (2026-11-24): order yang dibayar pakai payment type OTHER (FOC/fasilitas, mis. karyawan) --
  // datanya nyata (barang keluar, ada nilai), tapi BUKAN pendapatan (jurnal ERP tidak membuat Revenue buat
  // order ini). Dipilih di sini supaya bisa ditampilkan sebagai baris terpisah "Other Cost", dan SENGAJA
  // tidak dimasukkan ke hitungan penjualan (Total Bills/Net/Gross/kategori/payment).
  //
  // paid pakai order_out, BUKAN payment_at (2026-11-24, keputusan user): order_out = kapan POS
  // MEMPROSES order (order POS: sama dengan payment_at, sama-sama now() saat bayar; order mobile/QR:
  // saat POS menarik order -- MobileOrderPullServices, selalu di dalam dayshift aktif). payment_at
  // order mobile = waktu customer bayar (dari sudomobile) yang bisa jatuh SEBELUM dayin_time dayshift
  // tempat order ditarik (POS tutup) -> order hilang dari semua window; atau jatuh di dayshift
  // sebelumnya padahal jurnal End Day-nya sudah terkirim. order_out aman di sini karena yang dibaca
  // cuma status 'paid' -- Void() yang menimpa order_out hanya terjadi saat status berubah jadi
  // 'void' (dihitung lewat void_at), jadi order berstatus paid tidak pernah tertimpa.
  private static function GetReportOrderList(string $start, ?string $end): array
  {
    $cols = ['paid' => 'order_out', 'other_cost' => 'order_out', 'cancel' => 'cancel_at', 'void' => 'void_at'];
    $conds = [];
    $params = [];
    foreach ($cols as $status => $col) {
      $cond = "(tro.status = '{$status}' AND tro.{$col} >= ?";
      $params[] = $start;
      if ($end !== null) {
        $cond .= " AND tro.{$col} < ?";
        $params[] = $end;
      }
      $conds[] = $cond . ")";
    }

    return DB::select("SELECT tro.* FROM tr_order tro WHERE " . implode(" OR ", $conds), $params);
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
      throw new \Exception('branch ops setting has not been pulled yet, cannot check operating hours');
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

  public static function StartDay($start_cash, $request = null)
  {
    try {
      // lockForUpdate di row branch -- dijadiin "kunci" biar 2 request StartDay yang nembak
      // barengan buat branch yang sama antre (bukan dua-duanya lolos cek GetDayShift() sebelum
      // salah satu sempat insert). Tanpa ini ada celah race condition kecil yang bisa bikin
      // 2 dayshift aktif sekaligus -- MySQL gak dukung partial unique index (WHERE dayout_time
      // IS NULL) kayak Postgres, jadi diamanin lewat transaction + row lock di sini.
      return DB::transaction(function () use ($start_cash, $request) {
        $datetimenow = now();
        $branch = BranchModel::lockForUpdate()->first();
        $current_dayshift = self::GetDayShift();
        if ($current_dayshift) {
          if ($current_dayshift->dayout_time == null) {
            throw new \Exception('cannot start day because end day has not been done yet!');
          }
        }

        // Guard waktu komputer mundur (2026-09-21) -- bandingkan waktu Start Cash SEKARANG
        // dengan dayin_time dayshift TERAKHIR (apa pun statusnya, udah di-end-day atau belum,
        // beda dari GetDayShift() di atas yang cuma nangkep yang masih aktif). Kalau waktu
        // sekarang lebih AWAL/mundur dari itu, berarti jam komputer kasir salah (mundur, misal
        // baterai CMOS habis/gak ke-sync NTP) -- ditolak dari awal, DULUAN sebelum ulid dayshift
        // baru ke-generate (ulid komposisi pakai timestamp, kalau kebablasan bikin ulid mundur
        // itu bisa nabrak urutan yang udah ada / rusak asumsi "makin baru makin besar" di query
        // ORDER BY ulid yang dipakai di GetDayShift()).
        $lastDayshift = DaySiftModel::orderBy('dayin_time', 'desc')->first();
        // dayin_time gak di-cast ke Carbon oleh model ini ($timestamps=false, gak ada $casts) --
        // balik sebagai string mentah dari DB, jadi di-parse manual di sini biar aman
        // dibandingkan, gak bergantung ke cast model.
        if ($lastDayshift && $datetimenow->lt(Carbon::parse($lastDayshift->dayin_time))) {
          throw new \Exception('computer time is incorrect (earlier than the last dayshift), cannot start cash with a time in the past -- please check and correct this computer\'s clock first');
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
          "dayin_user_id" => self::getLoggedInUserId($request) ?? 1,
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
          throw new \Exception('cannot end shift because start day has not been done yet!');
        }
      } else {
        throw new \Exception("please install the application first!");
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

  // EndDay -- urutan disusun ulang 2026-09-24 (sebelumnya: save+push+jurnal 1 transaksi besar,
  // push gagal = SEMUANYA rollback termasuk save; jurnal tetap dicoba walau push gagal). Sekarang:
  // 1) validasi, 2) SAVE (transaksi sendiri, sempit, commit duluan -- push gagal TIDAK BOLEH lagi
  // nge-rollback ini), 3) PUSH semua (try/catch LOKAL, gak nge-throw ke luar), 4) JURNAL cuma
  // di-hit KALAU push di atas semuanya sukses -- kalau push ada yang gagal, jurnal DISKIP total
  // (endpoint jurnal ERP emang nolak kalau pos_dayshift/pos_order belum ada di sana, jadi nyoba
  // jurnal pas push gagal cuma bakal gagal lagi, percuma). Response ke frontend SELALU sukses
  // ("end day success!") walau push/jurnal gagal di background -- push yang gagal otomatis
  // di-retry sama job sync:push (jalan tiap 120 detik, filter sync_at IS NULL, lihat SyncPush.php),
  // jurnal yang gak sempat ke-trigger bisa di-retry manual dari modul Dayshift Jurnal di ERP.
  public static function EndDay(Request $request)
  {
    $dayshift_ulid = $request->input("dayshift_ulid");
    $aktual_ending_cash = $request->input("aktual_ending_cash");
    $notes = $request->input("notes");

    // 1. VALIDASI
    $current_dayshift = DaySiftModel::where("ulid", $dayshift_ulid)->first();
    if (!$current_dayshift) {
      throw new \Exception("start day has never been done!");
    }
    if ($current_dayshift->dayout_time != null) {
      throw new \Exception("end day has already been done!");
    }

    // 2. SAVE -- transaksi sendiri, cuma nyakup UPDATE tr_dayshift, commit SEBELUM nyentuh
    // push/jurnal sama sekali. sync_at di-null-kan bareng (2026-09-22) -- kolom ini udah kepush
    // duluan pas Start Day (sync:push jalan tiap 120 detik sepanjang shift berlangsung), jadi
    // sync_at-nya udah TERISI di titik ini. Tanpa di-null-kan, pushDataDayShift() (filter WHERE
    // sync_at IS NULL) gak bakal pernah nemu baris ini lagi buat di-push ulang -- dayout_time/
    // dayout_total/dayout_notes yang baru aja diisi gak akan pernah sampai ke pos_dayshift ERP.
    DB::beginTransaction();
    try {
      DaySiftModel::where("ulid", $dayshift_ulid)->update([
        "dayout_time" => now(),
        "dayout_total" => $aktual_ending_cash,
        "dayout_notes" => $notes,
        "dayout_user_id" => self::getLoggedInUserId($request) ?? 1,
        "sync_at" => null,
      ]);
      DB::commit();
    } catch (\Throwable $e) {
      DB::rollBack();
      throw $e;
    }

    // Non-blocking (2026-11-23) -- station_id di-resolve DI SINI (masih punya $request HTTP asli)
    // SEBELUM spawn command, lihat catatan lengkap di OrderServices::SaveOrder().
    //
    // REROUTE ke 'report-dayshift' (BUKAN 'end-day' lagi, 2026-11-23, REFACTOR) -- PrintEndDay()
    // (1-baris-kasir) gak dipakai lagi di jalur manapun, digantikan PrintReportDaysift()
    // (3-baris-kasir: Start/End/Print) di SINI (End Day otomatis) DAN di
    // DayShiftController::printEndayReport() (manual, "On Running", guard khusus dayshift aktif --
    // TIDAK relevan di titik INI karena dayshift di sini SUDAH ditutup duluan di atas).
    $station_id = PrintServices::RetrieveReceiptStationIdFromSession($request);
    $cashier_print_fullname = PrintServices::getLoggedInUserFullname($request);
    PrintServices::dispatchPrintJob($request, 'report-dayshift', ['dayshift_ulid' => $dayshift_ulid, 'station_id' => $station_id ?? '', 'cashier_print_fullname' => $cashier_print_fullname ?? '']);

    // 3. PUSH -- try/catch LOKAL, BUKAN nge-throw ke luar (beda dari sebelumnya). Push gagal gak
    // boleh bikin EndDay keliatan gagal ke frontend -- SAVE di atas udah aman kesimpen.
    $pushService = new PushDataServices;
    $pushFailed = false;
    try {
      // dayshift dulu, wajib duluan sebelum order (pos_order.dayshift_ulid ngerujuk ke situ).
      $pushService->pushDataDayShift();
      $pushService->pushDataDayShiftDetail();
      $pushService->pushDataOrder();
      $pushService->pushDataOrderDetail();
      $pushService->pushDataOrderDetailPackage();
      $pushService->pushDataOrderPayment();
    } catch (\Throwable $e) {
      $pushFailed = true;
      Log::error('EndDay: push ke ERP gagal, jurnal DISKIP -- nunggu sync:push job retry', [
        'dayshift_ulid' => $dayshift_ulid,
        'error' => $e->getMessage(),
      ]);
    }

    // 4. JURNAL -- CUMA di-hit kalau semua push di atas sukses. Token branch yang sama dipakai
    // buat auth /pos/sync/*, sekarang wajib juga buat /pos/endday/* (lihat
    // middleware.BranchTokenAuth di APIANDORDER dan midleware.BranchTokenAuth di sudocore2,
    // keduanya validasi token yang sama).
    if (!$pushFailed) {
      $branch = BranchModel::first();
      $resc = Http::withToken($branch->token)
        ->withHeaders(BranchAuthHeaderService::build())
        ->withOptions(['verify' => config('services.http_verify_ssl')])
        ->get(env('SERVER_ENDPOINT') . "/pos/endday/jurnal/" . $branch->id . "/" . $dayshift_ulid);
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
    }

    return "end day success!";
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
          $netsales_sc_total += $orderitem->total_service_charge;
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
        $netsales_sc_total + $netsales_pb1_total + $netsales_vat_total;

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
      // Join mr_user 2x (alias beda) buat dapetin NAMA yang mulai/nutup shift, bukan cuma
      // dayin_user_id/dayout_user_id mentah -- dipakai kolom "Started By"/"Ended By" di
      // ShiftLogPage.vue (posv1-vue). dayout_user_id null selama shift masih kebuka, LEFT JOIN
      // biar baris shift yang belum ditutup tetap muncul (dayout_user_name null).
      $dayshift_list = DB::select("
      SELECT
      td.*,
      uin.fullname as dayin_user_name,
      uout.fullname as dayout_user_name
      FROM tr_dayshift td
      LEFT JOIN mr_user uin on uin.id = td.dayin_user_id
      LEFT JOIN mr_user uout on uout.id = td.dayout_user_id
      ORDER BY td.ulid DESC
      ");
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
          $netsales_sc_total += $orderitem->total_service_charge;
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
        $netsales_sc_total + $netsales_pb1_total + $netsales_vat_total;

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
      // JOIN mr_user biar frontend (DayStartEndPage.vue, kolom "User" tabel Shift Detail)
      // nampilin NAMA yang end shift, bukan shift_user_id mentah -- sama pola dayin_user_name.
      $daftar_dayshift_detail = DB::table('tr_dayshift_detail')
        ->leftJoin('mr_user', 'mr_user.id', '=', 'tr_dayshift_detail.shift_user_id')
        ->where('tr_dayshift_detail.dayshift_ulid', $dayshift_ulid)
        ->select('tr_dayshift_detail.*', 'mr_user.fullname as shift_user_name')
        ->get();
      $data_dayshift = DaySiftModel::where('ulid', $dayshift_ulid)->first();
      // urut shift_time (bukan ulid) -- sama dengan GetReportPerShift(), tidak ambigu.
      $data_dayshift_detail = DayShiftDetailModel::where('dayshift_ulid', $dayshift_ulid)
        ->orderBy('shift_time', 'desc')->first();
      if ($data_dayshift_detail) {
        $pakai_header = false;
      }

      // awal window = End Shift terakhir (shift_time), atau dayin_time kalau dayshift belum
      // pernah End Shift (= 1 shift utuh).
      if ($pakai_header) {
        $starttime = $data_dayshift->dayin_time;
      } else {
        $starttime = $data_dayshift_detail->shift_time;
      }
      // akhir window = dayout_time kalau dayshift sudah di-End Day (supaya order dayshift
      // SESUDAHNYA tidak ikut terhitung), NULL kalau masih berjalan (sampai sekarang).
      $endtime = $data_dayshift->dayout_time ?? null;

      // ambil data -- scope pakai STATUS + order_out(paid)/cancel_at/void_at (lihat GetReportOrderList)
      $data_order_list = self::GetReportOrderList($starttime, $endtime);

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
      $subtotal_total = 0;      // SUM(sub_total) order paid -- "Sales (Subtotal)", SEBELUM diskon
      $rounding_total = 0;      // SUM(rounding_amount) order paid
      $total_billing_total = 0; // SUM(total_billing) order paid -- "Total Bills" & "Gross Sales"
      $netsales_dc_total = 0;
      $netsales_of_total = 0;
      $netsales_sc_total = 0;

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
      $other_cost_total = 0; // SUM(total_billing) order other_cost (FOC) -- BUKAN pendapatan
      $other_cost_count = 0;


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

        // 2026-11-24: Net Sales = total_billing - total_tax (pajak = titipan ke pemerintah; service
        // charge/order fee/delivery cost/rounding = pendapatan kita, jadi ikut Net Sales).
        // Gross Sales/Total Bills = total_billing. Semua dari kolom header tr_order, tidak dihitung ulang.
        if ($orderitem->status == "paid") {
          $net_order = ($orderitem->total_billing ?? 0) - ($orderitem->total_tax ?? 0);
          $number_of_bill += 1;
          $discount_total += $orderitem->total_discount;
          $netsales += $net_order;
          $subtotal_total += ($orderitem->sub_total ?? 0);
          $rounding_total += ($orderitem->rounding_amount ?? 0);
          $total_billing_total += ($orderitem->total_billing ?? 0);
          $netsales_dc_total += $orderitem->delivery_cost;
          $netsales_of_total += $orderitem->order_fee;
          $netsales_sc_total += $orderitem->total_service_charge;
          $pax_total += $orderitem->pax;

          $avg_netsales_per_pax += $net_order;
          $avg_grosssales_per_pax += ($orderitem->total_billing);
          $avg_netsales_per_bill += $net_order;
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
        // other_cost: cuma ditampung ke baris "Other Cost" -- TIDAK masuk $list_ordernumber->paid, jadi tidak
        // ikut penjualan/pajak/kategori/payment.
        if ($orderitem->status == "other_cost") {
          $other_cost_total += ($orderitem->total_billing ?? 0);
          $other_cost_count += 1;
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

      // dpp_total: dipakai baris "DPP" section SALES SUMMARY, SUM(dpp*qty) dari kedua tabel
      // detail (kolom dpp udah ada langsung, gak perlu formula ulang) -- sama pola kayak
      // GetReportDayshiftorEndDay()/GetReportCurrentShift()/GetReportPerShift().
      $dpp_total = 0;
      foreach ($order_paid_detail as $opd) {
        if ($opd->tax_type == 'pb1') {
          $netsales_pb1_total += ($opd->tax_amount * $opd->qty);
        } else if ($opd->tax_type == 'vat') {
          $netsales_vat_total += ($opd->tax_amount * $opd->qty);
        }
        $dpp_total += ($opd->dpp * $opd->qty);
      }

      // qty efektif package = qty parent x qty package (sama dengan RecalculateOrderTotals()).
      $parent_qty_by_ulid = [];
      foreach ($order_paid_detail as $parent) {
        $parent_qty_by_ulid[$parent->ulid] = $parent->qty;
      }
      foreach ($order_paid_detail_package as $opd) {
        $pkg_qty = ($parent_qty_by_ulid[$opd->tr_order_detail_ulid] ?? 1) * $opd->qty;
        if ($opd->tax_type == 'pb1') {
          $netsales_pb1_total += $opd->tax_amount * $pkg_qty;
        } else if ($opd->tax_type == 'vat') {
          $netsales_vat_total += $opd->tax_amount * $pkg_qty;
        }
        $dpp_total += ($opd->dpp * $pkg_qty);
      }

      $total_tax_total = $netsales_pb1_total + $netsales_vat_total;


      // Gross Sales = SUM(total_billing) -- sudah termasuk pajak, SC, order fee, delivery cost, rounding.
      $gross_sales = $total_billing_total;

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

        // sales_by_category (CATEGORY SUMMARY) -- diperbaiki 2026-11-24 (permintaan owner):
        //   * qty    = qty HEAD saja (baris tr_order_detail). Isi package (body, tr_order_detail_package)
        //              TIDAK menambah qty (qty body = 0).
        //   * amount = HEAD + BODY. Body dihitung dengan qty efektif = qty head x qty package, dan
        //              dimasukkan ke kategori HEAD-nya (package dijual sebagai 1 kesatuan di bawah head),
        //              BUKAN ke kategori menu anak.
        //   * semua nilai moneter dikali qty -- kolom tod.total disimpan PER SATU UNIT (lihat
        //     OrderServices::CalculateLineItem), dulu grand_total tidak dikali qty sehingga item
        //     dengan qty > 1 dan isi package kekecilan.
        $sales_by_category = DB::select("SELECT
          mcc.name AS category_name,
          SUM( gabungan.qty ) AS qty,
          SUM( gabungan.sub_total ) AS sub_total,
          SUM( gabungan.discount_amount ) AS discount_amount,
          SUM( gabungan.vat_amount ) AS vat_amount,
          SUM( gabungan.pb1_amount ) AS pb1_amount,
          SUM( gabungan.grand_total ) AS grand_total
        FROM
          (
          SELECT
            mi.category_id AS category_id,
            tod.qty AS qty,
            ( (CASE WHEN tod.flag_inclusive_tax = 1 THEN tod.price_pos / (1 + tod.tax_rate/100) ELSE tod.price_pos END) * tod.qty ) AS sub_total,
            ( tod.discount_amount * tod.qty ) AS discount_amount,
            IF( tod.tax_type = 'vat', tod.tax_amount * tod.qty, 0 ) AS vat_amount,
            IF( tod.tax_type = 'pb1', tod.tax_amount * tod.qty, 0 ) AS pb1_amount,
            ( tod.total * tod.qty ) AS grand_total
          FROM
            tr_order_detail tod
            JOIN mr_item_conv mic ON mic.id = tod.menu_id
            JOIN mr_item mi ON mi.id = mic.item_id
          WHERE
            tod.order_number IN (" . $order_number_concat . ")
            AND tod.cancel_at IS NULL
          UNION ALL
          SELECT
            mi.category_id AS category_id,
            0 AS qty,
            ( (CASE WHEN pkg.flag_inclusive_tax = 1 THEN pkg.price_pos / (1 + pkg.tax_rate/100) ELSE pkg.price_pos END) * (head.qty * pkg.qty) ) AS sub_total,
            ( pkg.discount_amount * (head.qty * pkg.qty) ) AS discount_amount,
            IF( pkg.tax_type = 'vat', pkg.tax_amount * (head.qty * pkg.qty), 0 ) AS vat_amount,
            IF( pkg.tax_type = 'pb1', pkg.tax_amount * (head.qty * pkg.qty), 0 ) AS pb1_amount,
            ( pkg.total * (head.qty * pkg.qty) ) AS grand_total
          FROM
            tr_order_detail_package pkg
            JOIN tr_order_detail head ON head.ulid = pkg.tr_order_detail_ulid
            JOIN mr_item_conv mic ON mic.id = head.menu_id
            JOIN mr_item mi ON mi.id = mic.item_id
          WHERE
            pkg.tr_order_detail_ulid IN (" . $ulid_orderdetail_concat . ")
            AND head.cancel_at IS NULL
          ) AS gabungan
          JOIN mr_category mcc ON mcc.id = gabungan.category_id
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


      // dayin_user_name: dipakai frontend (Shift Detail, DayStartEndPage.vue "Started By") biar
      // nampilin NAMA kasir yang start day, bukan id mentah (dayshift.dayin_user_id) -- lookup
      // ke mr_user, sama pola kayak dayin_user_fullname/dayout_user_fullname di PrintServices.php.
      $dayin_user_name = DB::table('mr_user')
        ->where('id', $data_dayshift->dayin_user_id ?? null)
        ->value('fullname') ?? '';

      // sales_by_visit_purpose/sales_by_order_source/sales_by_staff/cash_in_total: dipakai section
      // SALES TYPE SUMMARY, ORDER SOURCE SUMMARY, STAFF SALES SUMMARY, CASH FLOW SUMMARY di halaman
      // web (DayStartEndPage.vue) -- disamakan dengan yang sudah ada di GetReportDayshiftorEndDay()/
      // GetReportCurrentShift()/GetReportPerShift() biar web & print konsisten datanya.
      $sales_by_visit_purpose = DB::table("tr_order")
        ->join("mr_visit_purpose", "mr_visit_purpose.id", "=", "tr_order.visit_purpose_id")
        ->whereIn("tr_order.order_number", $list_ordernumber->paid)
        ->select(
          "mr_visit_purpose.name as visit_purpose_name",
          DB::raw("COUNT(tr_order.order_number) as qty"),
          DB::raw("SUM(tr_order.total_billing) as total_amount")
        )
        ->groupBy("visit_purpose_name")->get();

      $sales_by_order_source = DB::table("tr_order")
        ->whereIn("tr_order.order_number", $list_ordernumber->paid)
        ->select(
          "tr_order.order_source",
          DB::raw("COUNT(tr_order.order_number) as qty"),
          DB::raw("SUM(tr_order.total_billing) as total_amount")
        )
        ->groupBy("order_source")->get();

      $sales_by_staff = DB::table("tr_order")
        ->join("mr_user", "mr_user.id", "=", "tr_order.created_by")
        ->whereIn("tr_order.order_number", $list_ordernumber->paid)
        ->select(
          "mr_user.fullname as staff_name",
          DB::raw("COUNT(tr_order.order_number) as qty"),
          DB::raw("SUM(tr_order.total_billing) as total_amount")
        )
        ->groupBy("staff_name")->get();

      // STAFF PAYMENT SUMMARY -- breakdown 2 dimensi staff x payment method (siapa yang bikin
      // order, dibayar pakai metode apa). JOIN tr_order_payment -> tr_order (via payment_number,
      // BUKAN FK beneran, cuma kolom yang sama nilainya -- sama pola $list_payment_number diisi
      // dari $orderitem->payment_number di atas) -> mr_user (via created_by, sama pola
      // $sales_by_staff) + JOIN mr_payment_method (sama pola $payment_detail_list).
      // staff_name/payment_method_name DIPISAH (2026-10-22, sebelumnya CONCAT jadi 1 kolom
      // "label") -- dikonsumsi printStaffPaymentSection() yang ngelompokin per staf, butuh 2
      // field terpisah buat bisa group-by staf dulu baru breakdown metode di dalamnya.
      $sales_by_staff_payment = DB::table("tr_order_payment")
        ->join("tr_order", "tr_order.payment_number", "=", "tr_order_payment.payment_number")
        ->join("mr_user", "mr_user.id", "=", "tr_order.created_by")
        ->join("mr_payment_method", "mr_payment_method.id", "=", "tr_order_payment.payment_method_id")
        ->whereIn("tr_order_payment.payment_number", $list_payment_number)
        ->select(
          "mr_user.fullname as staff_name",
          "mr_payment_method.name as payment_method_name",
          DB::raw("COUNT(tr_order_payment.ulid) as qty"),
          DB::raw("SUM(tr_order_payment.payment_amount) as amount")
        )
        ->groupBy("mr_user.fullname", "mr_payment_method.name")->get();

      // cash_in_total: filter payment_method_type_id = 1 (ID row "CASH" di mr_payment_method_type),
      // BUKAN mr_payment_method.name = 'CASH' -- konsisten sama keputusan di GetReportDayshiftorEndDay().
      $cash_in_total = DB::table("tr_order_payment")
        ->join("mr_payment_method", "mr_payment_method.id", "=", "tr_order_payment.payment_method_id")
        ->whereIn("tr_order_payment.payment_number", $list_payment_number)
        ->where("mr_payment_method.payment_method_type_id", 1)
        ->sum("tr_order_payment.payment_amount");

      return [

        "dayshift" => $data_dayshift,
        "dayin_user_name" => $dayin_user_name,
        "dayshift_detail" => $daftar_dayshift_detail,
        "sales_recapitulation" => [
          ["pl" => 1, "key" => "Hold Sales", "amount" => $holdsales],
          ["pl" => 1, "key" => "Pending Sales", "amount" => $pendingsales],
          ["pl" => 1, "key" => "Net Sales", "amount" => $netsales],
          ["pl" => 40, "key" => "Netsales Delivery Cost", "amount" => $netsales_dc_total],
          ["pl" => 40, "key" => "Netsales Order Fee", "amount" => $netsales_of_total],
          ["pl" => 40, "key" => "Netsales Service Charge", "amount" => $netsales_sc_total],
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
          // elemen baru (2026-11-24) -- SELALU ditambah di akhir; pembaca pakai DayShiftServices::recapAmount() by key
          ["pl" => 1, "key" => "Sales Subtotal", "amount" => $subtotal_total],
          ["pl" => 1, "key" => "Rounding", "amount" => $rounding_total],
          ["pl" => 1, "key" => "Total Bills", "amount" => $total_billing_total],
          ["pl" => 1, "key" => "Other Cost", "amount" => $other_cost_total],
          ["pl" => 1, "key" => "Other Cost Count", "amount" => $other_cost_count],
        ],
        "dpp_total" => $dpp_total,
        "total_tax_total" => $total_tax_total,
        "payment_recapitulation" => $payment_detail_list,
        "sales_by_menu" => $sales_by_menu,
        "sales_by_category" => $sales_by_category,
        "sales_by_table" => $sales_by_table_section,
        "sales_by_visit_purpose" => $sales_by_visit_purpose,
        "sales_by_order_source" => $sales_by_order_source,
        "sales_by_staff" => $sales_by_staff,
        "sales_by_staff_payment" => $sales_by_staff_payment,
        "cash_in_total" => $cash_in_total,
      ];
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  // breakdownWithPercent: helper array kecil, samain sama printBreakdownSection() di
  // PrintServices.php -- persentase dihitung dari total section itu sendiri, BUKAN grand total.
  private static function breakdownWithPercent($rows, string $labelKey, string $amountKey, ?string $qtyKey = 'qty'): array
  {
    $total = 0;
    foreach ($rows as $r) {
      $total += (float) $r->$amountKey;
    }
    $result = [];
    foreach ($rows as $r) {
      $amount = (float) $r->$amountKey;
      $result[] = [
        "label" => $r->$labelKey,
        "qty" => $qtyKey && isset($r->$qtyKey) ? (int) $r->$qtyKey : null,
        "amount" => $amount,
        "percent" => $total > 0 ? round(($amount / $total) * 100) : 0,
      ];
    }
    return $result;
  }

  // GetReportSummaryV2 -- struktur response key-value, 1:1 SAMA PERSIS kayak section yang
  // dicetak PrintServices::PrintEndDay() (SALES SUMMARY/SALES TYPE SUMMARY/ORDER SOURCE
  // SUMMARY/ITEM CATEGORY SUMMARY/STAFF SALES SUMMARY/PAYMENT METHOD SUMMARY/CASH FLOW SUMMARY).
  // Reuse GetReportCurrentShiftforTampilan() buat raw data (endpoint lama /report/:id TETAP ADA,
  // gak dihapus, cuma gak dipakai lagi sama DayStartEndPage.vue setelah pindah ke v2 ini) --
  // dipakai endpoint baru GET /dayshift/report-v2/:id.
  // DEPRECATED (2026-11-24) -- usang & kurang lengkap, PAKAI GetReportSummaryV3() SAJA. Pembacaan
  // sales_recapitulation di sini masih pakai index posisi yang bergeser 1, dan tidak punya field baru
  // (bill_count, order_fee, delivery_cost, rounding, other_cost). Sengaja TIDAK diperbaiki.
  static function GetReportSummaryV2($dayshift_ulid)
  {
    $raw = self::GetReportCurrentShiftforTampilan($dayshift_ulid);
    $recap = $raw['sales_recapitulation'];
    $amountAt = function (int $i) use ($recap) {
      return (float) ($recap[$i]['amount'] ?? 0);
    };

    // ORDER SOURCE SUMMARY: 4 kategori FIXED (pos/qr/mobile/kiosk), selalu ditampilkan semua
    // walau salah satu belum ada transaksi (0) -- sama pola PrintServices::PrintEndDay().
    $orderSourceLabels = ["pos" => "POS", "qr" => "QR Order", "mobile" => "Mobile App", "kiosk" => "Kiosk"];
    $orderSourceByKey = [];
    foreach ($raw['sales_by_order_source'] as $item) {
      $orderSourceByKey[$item->order_source] = $item;
    }
    $orderSourceTotal = 0;
    foreach ($orderSourceByKey as $item) {
      $orderSourceTotal += (float) $item->total_amount;
    }
    $orderSourceSummary = [];
    foreach ($orderSourceLabels as $key => $label) {
      $found = $orderSourceByKey[$key] ?? null;
      $amount = (float) ($found->total_amount ?? 0);
      $orderSourceSummary[] = [
        "label" => $label,
        "qty" => (int) ($found->qty ?? 0),
        "amount" => $amount,
        "percent" => $orderSourceTotal > 0 ? round(($amount / $orderSourceTotal) * 100) : 0,
      ];
    }

    $startingCash = (float) ($raw['dayshift']->dayin_total ?? 0);
    $cashIn = (float) ($raw['cash_in_total'] ?? 0);
    $cashOut = 0;
    $expectedCash = $startingCash + $cashIn - $cashOut;
    $actualCash = (float) ($raw['dayshift']->dayout_total ?? 0);

    // STAFF PAYMENT SUMMARY -- label digabung "Nama - Metode" DI SINI (query sales_by_staff_payment,
    // GetReportCurrentShiftforTampilan, sejak 2026-10-22 ngasih staff_name/payment_method_name
    // TERPISAH buat kebutuhan printStaffPaymentSection() yang ngelompokin per staf -- breakdownWithPercent()
    // di bawah butuh 1 kolom label flat, bukan 2 kolom terpisah, makanya digabung manual lagi di sini.
    // BUG LAMA yang diperbaiki (2026-11-20): kode sebelumnya manggil breakdownWithPercent() pakai
    // labelKey "label" padahal kolom itu udah gak ada di hasil query sejak 2026-10-22 -- hasilnya
    // "label" selalu null di tiap baris staff_payment_summary.
    $staffPaymentRows = [];
    foreach ($raw['sales_by_staff_payment'] as $item) {
      $staffPaymentRows[] = (object) [
        "label" => $item->staff_name . " - " . $item->payment_method_name,
        "qty" => $item->qty,
        "amount" => $item->amount,
      ];
    }

    return [
      // passthrough -- field yang gak ada padanannya di section print end day, tapi masih dipakai
      // DayStartEndPage.vue (header Branch/Started By/Starting Shift, Shift Detail, Sales By
      // Menu, Sales By Table, dialog End Day) biar halaman itu bisa full pindah ke v2 tanpa
      // manggil GetReportCurrentShiftforTampilan() lagi secara terpisah dari frontend.
      "dayshift" => $raw['dayshift'],
      "dayin_user_name" => $raw['dayin_user_name'],
      "dayshift_detail" => $raw['dayshift_detail'],
      "sales_by_menu" => $raw['sales_by_menu'],
      "sales_by_table" => $raw['sales_by_table'],

      "sales_summary" => [
        "total_bills" => $amountAt(13),
        "average_per_bill" => $amountAt(14),
        "on_hold" => $amountAt(0),
        "pending" => $amountAt(1),
        "sales_subtotal" => $amountAt(2),
        // index 17 ("Discount Total"), BUKAN 18 -- array sales_recapitulation cuma punya 18
        // elemen (index 0-17), [18] selalu undefined (bug diperbaiki 2026-11-20, sama off-by-one
        // yang ditemukan+diperbaiki di PrintServices.php::PrintEndDay()).
        "discount" => $amountAt(17),
        "dpp" => (float) ($raw['dpp_total'] ?? 0),
        "service_charge" => $amountAt(5),
        "pb1" => $amountAt(7),
        "vat" => $amountAt(8),
        "total_tax" => (float) ($raw['total_tax_total'] ?? 0),
        "net_sales" => $amountAt(2),
        "gross_sales" => $amountAt(9),
      ],
      "sales_type_summary" => self::breakdownWithPercent($raw['sales_by_visit_purpose'], "visit_purpose_name", "total_amount"),
      "order_source_summary" => $orderSourceSummary,
      "item_category_summary" => self::breakdownWithPercent($raw['sales_by_category'], "category_name", "grand_total"),
      "staff_sales_summary" => self::breakdownWithPercent($raw['sales_by_staff'], "staff_name", "total_amount"),
      "payment_method_summary" => self::breakdownWithPercent($raw['payment_recapitulation'], "payment_method_name", "payment_amount"),
      "staff_payment_summary" => self::breakdownWithPercent($staffPaymentRows, "label", "amount"),
      "cash_flow_summary" => [
        "starting_cash" => $startingCash,
        "cash_in" => $cashIn,
        "cash_out" => $cashOut,
        "expected_cash" => $expectedCash,
        "actual_cash" => $actualCash,
        "variance" => $actualCash - $expectedCash,
        "closing_note" => $raw['dayshift']->dayout_notes ?? '',
      ],
    ];
  }

  // GetReportSummaryV3 (2026-11-13) -- SAMA PERSIS V2, cuma minus "sales_by_menu" & "sales_by_table"
  // dari response (2 field itu dipakai DayStartEndPage.vue render tabel Sales By Menu/Sales By
  // Table di frontend, tapi gak kepake di consumer baru yang cuma butuh section summary-nya doang).
  // $scope (2026-11-24, permintaan owner) -- periode data laporan:
  //   'dayshift' (DEFAULT) = SELURUH dayshift: dari dayin_time sampai dayout_time (atau sampai sekarang kalau
  //                          belum End Day). Angka TIDAK berubah saat ganti shift, sama dengan struk End Day.
  //   'shift'              = hanya SHIFT BERJALAN: dari End Shift terakhir (atau dayin_time kalau belum pernah
  //                          End Shift) sampai dayout_time/sekarang -- perilaku V3 sebelumnya.
  //   'shift' + $shiftNumber = SHIFT KE-N (2026-11-24): N = tr_dayshift_detail.shift_number untuk shift yang sudah
  //                          di-End Shift (pakai GetReportPerShift()), N = jumlah End Shift + 1 = shift berjalan.
  //                          Response SAMA dengan scope lain (lengkap) + field shift_number.
  // starting_cash selalu modal awal DAYSHIFT; pada scope 'shift' cash_in cuma shift tsb, jadi expected_cash
  // yang benar untuk dialog End Day hanya didapat dari scope 'dayshift'.
  // Kesalahan input (scope/shift_number tidak valid, shift tidak ada) -> \InvalidArgumentException supaya controller
  // bisa membalas code 100.
  static function GetReportSummaryV3($dayshift_ulid, string $scope = 'dayshift', ?int $shiftNumber = null)
  {
    $scope = strtolower($scope);
    if (!in_array($scope, ['dayshift', 'shift'], true)) {
      throw new \InvalidArgumentException("invalid scope, use 'dayshift' or 'shift'");
    }
    if ($shiftNumber !== null && $scope !== 'shift') {
      throw new \InvalidArgumentException("shift_number requires scope=shift");
    }
    if ($shiftNumber !== null && $shiftNumber < 1) {
      throw new \InvalidArgumentException("invalid shift_number");
    }

    if ($shiftNumber !== null) {
      // shift_number <= jumlah End Shift -> shift yang sudah selesai; jumlah End Shift + 1 -> shift berjalan.
      $totalEndShift = DB::table('tr_dayshift_detail')->where('dayshift_ulid', $dayshift_ulid)->count();
      if ($shiftNumber > $totalEndShift + 1) {
        throw new \InvalidArgumentException("shift not found");
      }
      if ($shiftNumber <= $totalEndShift) {
        $detail = DayShiftDetailModel::where('dayshift_ulid', $dayshift_ulid)->where('shift_number', $shiftNumber)->first();
        if (!$detail) {
          throw new \InvalidArgumentException("shift not found");
        }
        $raw = self::GetReportPerShift($detail->ulid);
      } else {
        $raw = self::GetReportCurrentShiftforTampilan($dayshift_ulid);
      }
    } elseif ($scope === 'shift') {
      $raw = self::GetReportCurrentShiftforTampilan($dayshift_ulid);
    } else {
      $raw = self::GetReportDayshiftorEndDay($dayshift_ulid);
    }

    if (!isset($raw['dayin_user_name'])) {
      // GetReportDayshiftorEndDay()/GetReportPerShift() tidak membawa 2 field ini (dipakai print, yang mencari
      // sendiri) -- dilengkapi di sini supaya bentuk response V3 SAMA untuk semua scope. Query sama dengan
      // GetReportCurrentShiftforTampilan(): dayshift_detail di-JOIN mr_user (shift_user_name).
      $raw['dayshift_detail'] = DB::table('tr_dayshift_detail')
        ->leftJoin('mr_user', 'mr_user.id', '=', 'tr_dayshift_detail.shift_user_id')
        ->where('tr_dayshift_detail.dayshift_ulid', $dayshift_ulid)
        ->select('tr_dayshift_detail.*', 'mr_user.fullname as shift_user_name')
        ->get();
      $raw['dayin_user_name'] = DB::table('mr_user')
        ->where('id', $raw['dayshift']->dayin_user_id ?? null)
        ->value('fullname') ?? '';
    }
    $recap = $raw['sales_recapitulation'];
    // 2026-11-24: baca recap BY KEY (bukan index posisi) -- index lama bergeser 1 dari urutan array
    // yang sebenarnya (pb1 kebaca VAT, vat kebaca Gross Sales, gross_sales kebaca Pax Total,
    // total_bills kebaca Avg Netsales Per Bill). Lihat DayShiftServices::recapAmount().
    $amountAt = function (string $key) use ($recap) {
      return self::recapAmount($recap, $key);
    };

    // ORDER SOURCE SUMMARY: 4 kategori FIXED (pos/qr/mobile/kiosk), selalu ditampilkan semua
    // walau salah satu belum ada transaksi (0) -- sama pola PrintServices::PrintEndDay().
    $orderSourceLabels = ["pos" => "POS", "qr" => "QR Order", "mobile" => "Mobile App", "kiosk" => "Kiosk"];
    $orderSourceByKey = [];
    foreach ($raw['sales_by_order_source'] as $item) {
      $orderSourceByKey[$item->order_source] = $item;
    }
    $orderSourceTotal = 0;
    foreach ($orderSourceByKey as $item) {
      $orderSourceTotal += (float) $item->total_amount;
    }
    $orderSourceSummary = [];
    foreach ($orderSourceLabels as $key => $label) {
      $found = $orderSourceByKey[$key] ?? null;
      $amount = (float) ($found->total_amount ?? 0);
      $orderSourceSummary[] = [
        "label" => $label,
        "qty" => (int) ($found->qty ?? 0),
        "amount" => $amount,
        "percent" => $orderSourceTotal > 0 ? round(($amount / $orderSourceTotal) * 100) : 0,
      ];
    }

    $startingCash = (float) ($raw['dayshift']->dayin_total ?? 0);
    $cashIn = (float) ($raw['cash_in_total'] ?? 0);
    $cashOut = 0;
    $expectedCash = $startingCash + $cashIn; // 2026-11-24 (owner): expected = cash in + starting cash
    $actualCash = (float) ($raw['dayshift']->dayout_total ?? 0);

    // STAFF PAYMENT SUMMARY -- label digabung "Nama - Metode" DI SINI, sama alasan+bug yang
    // diperbaiki di GetReportSummaryV2() (lihat komentar di sana).
    $staffPaymentRows = [];
    foreach ($raw['sales_by_staff_payment'] as $item) {
      $staffPaymentRows[] = (object) [
        "label" => $item->staff_name . " - " . $item->payment_method_name,
        "qty" => $item->qty,
        "amount" => $item->amount,
      ];
    }

    $result = [
      "dayshift" => $raw['dayshift'],
      "dayin_user_name" => $raw['dayin_user_name'],
      "dayshift_detail" => $raw['dayshift_detail'],
      // scope data yang dipakai: 'dayshift' (seluruh dayshift, default) atau 'shift' (shift berjalan).
      "scope" => $scope,
      // nomor shift yang diminta (?shift_number=N), null kalau bukan laporan per shift.
      "shift_number" => $shiftNumber,

      // blok "report_detail" (2026-11-13, baru di V3) -- membungkus 8 section (7 sales +
      // cash_flow_summary), sebelumnya semua ini flat di top-level sama kayak V2.
      "report_detail" => [
        "sales_summary" => [
          // total_bills = SUM(total_billing) order paid (AMOUNT, bukan jumlah -- berubah 2026-11-24).
          // Jumlah order paid ada di bill_count; average_per_bill = total_bills / bill_count.
          "total_bills" => $amountAt("Total Bills"),
          "bill_count" => (int) $amountAt("Number Of Bills"),
          "average_per_bill" => $amountAt("Avg Gross Sales Per Bill"),
          "on_hold" => $amountAt("Hold Sales"),
          "pending" => $amountAt("Pending Sales"),
          // other_cost = SUM(total_billing) order bayar tipe OTHER (FOC/fasilitas) -- nilai nyata tapi BUKAN pendapatan,
          // TIDAK termasuk total_bills/net_sales/gross_sales. other_cost_count = jumlah ordernya.
          "other_cost" => $amountAt("Other Cost"),
          "other_cost_count" => (int) $amountAt("Other Cost Count"),
          // sales_subtotal = SUM(tr_order.sub_total), SEBELUM diskon (bukan lagi sama dengan net_sales).
          "sales_subtotal" => $amountAt("Sales Subtotal"),
          "discount" => $amountAt("Discount Total"),
          "dpp" => (float) ($raw['dpp_total'] ?? 0),
          "service_charge" => $amountAt("Netsales Service Charge"),
          "order_fee" => $amountAt("Netsales Order Fee"),
          "delivery_cost" => $amountAt("Netsales Delivery Cost"),
          "rounding" => $amountAt("Rounding"),
          "pb1" => $amountAt("Netsales PB1 Total"),
          "vat" => $amountAt("Netsales VAT Total"),
          "total_tax" => (float) ($raw['total_tax_total'] ?? 0),
          // net_sales = SUM(total_billing - total_tax); gross_sales = SUM(total_billing).
          "net_sales" => $amountAt("Net Sales"),
          "gross_sales" => $amountAt("Gross Sales"),
        ],
        "sales_type_summary" => self::breakdownWithPercent($raw['sales_by_visit_purpose'], "visit_purpose_name", "total_amount"),
        "order_source_summary" => $orderSourceSummary,
        "item_category_summary" => self::breakdownWithPercent($raw['sales_by_category'], "category_name", "grand_total"),
        "staff_sales_summary" => self::breakdownWithPercent($raw['sales_by_staff'], "staff_name", "total_amount"),
        "payment_method_summary" => self::breakdownWithPercent($raw['payment_recapitulation'], "payment_method_name", "payment_amount"),
        "staff_payment_summary" => self::breakdownWithPercent($staffPaymentRows, "label", "amount"),
        "cash_flow_summary" => [
          "starting_cash" => $startingCash,
          "cash_in" => $cashIn,
          "cash_out" => $cashOut,
          "expected_cash" => $expectedCash,
          "actual_cash" => $actualCash,
          "variance" => $actualCash - $expectedCash,
          "closing_note" => $raw['dayshift']->dayout_notes ?? '',
        ],
      ],
    ];

    return $result;
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
      // endtime NULL kalau dayshift masih AKTIF (belum di-End Day) -- BUKAN lagi error (2026-11-23,
      // guard "end day has not been done yet!" DIHAPUS). Dayshift aktif = scope SAMPAI SEKARANG
      // (tanpa batas atas), sama pola GetReport() lama (lihat percabangan query di bawah) -- fungsi
      // ini sekarang bisa dipanggil buat dayshift aktif MAUPUN sudah ditutup.
      $endtime = $data_dayshift->dayout_time;
      // } else {
      //   $starttime = $data_dayshift_detail->shift_time;
      // }

      // ambil data
      //////bawahnya ini sama dengan report biasa

      // kueri ambil data start time sampai sekarang

      // scope pakai STATUS + order_out(paid)/cancel_at/void_at (lihat GetReportOrderList) -- $endtime
      // NULL (dayshift masih berjalan) otomatis = tanpa batas atas.
      $data_order_list = self::GetReportOrderList($starttime, $endtime);

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
      $subtotal_total = 0;      // SUM(sub_total) order paid -- "Sales (Subtotal)", SEBELUM diskon
      $rounding_total = 0;      // SUM(rounding_amount) order paid
      $total_billing_total = 0; // SUM(total_billing) order paid -- "Total Bills" & "Gross Sales"
      $netsales_dc_total = 0;
      $netsales_of_total = 0;
      $netsales_sc_total = 0;

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
      $other_cost_total = 0; // SUM(total_billing) order other_cost (FOC) -- BUKAN pendapatan
      $other_cost_count = 0;


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

        // 2026-11-24: Net Sales = total_billing - total_tax (pajak = titipan ke pemerintah; service
        // charge/order fee/delivery cost/rounding = pendapatan kita, jadi ikut Net Sales).
        // Gross Sales/Total Bills = total_billing. Semua dari kolom header tr_order, tidak dihitung ulang.
        if ($orderitem->status == "paid") {
          $net_order = ($orderitem->total_billing ?? 0) - ($orderitem->total_tax ?? 0);
          $number_of_bill += 1;
          $discount_total += $orderitem->total_discount;
          $netsales += $net_order;
          $subtotal_total += ($orderitem->sub_total ?? 0);
          $rounding_total += ($orderitem->rounding_amount ?? 0);
          $total_billing_total += ($orderitem->total_billing ?? 0);
          $netsales_dc_total += $orderitem->delivery_cost;
          $netsales_of_total += $orderitem->order_fee;
          $netsales_sc_total += $orderitem->total_service_charge;
          $pax_total += $orderitem->pax;

          $avg_netsales_per_pax += $net_order;
          $avg_grosssales_per_pax += ($orderitem->total_billing);
          $avg_netsales_per_bill += $net_order;
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
        // other_cost: cuma ditampung ke baris "Other Cost" -- TIDAK masuk $list_ordernumber->paid, jadi tidak
        // ikut penjualan/pajak/kategori/payment.
        if ($orderitem->status == "other_cost") {
          $other_cost_total += ($orderitem->total_billing ?? 0);
          $other_cost_count += 1;
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


      // dpp_total: SUM(dpp*qty) gabungan detail+package, dipakai di section SALES SUMMARY
      // (baris "DPP") -- kolom dpp udah ada langsung di tr_order_detail/tr_order_detail_package
      // (nilai net-of-tax, SETELAH diskon -- makna "DPP" yang benar secara akuntansi, sejak
      // swap kolom dpp/net_dpp 2026-11-18; kalau butuh nilai SEBELUM diskon pakai net_price,
      // bukan kolom ini), sama sumber persis yang dipakai tax breakdown di bawah, jadi
      // digabung ke loop yang sama biar gak double-loop.
      $dpp_total = 0;

      foreach ($order_paid_detail as $opd) {
        if ($opd->tax_type == 'pb1') {
          $netsales_pb1_total += ($opd->tax_amount * $opd->qty);
        } else if ($opd->tax_type == 'vat') {
          $netsales_vat_total += ($opd->tax_amount * $opd->qty);
        }
        $dpp_total += ($opd->dpp * $opd->qty);
      }

      // qty efektif package = qty parent x qty package (sama dengan RecalculateOrderTotals()).
      $parent_qty_by_ulid = [];
      foreach ($order_paid_detail as $parent) {
        $parent_qty_by_ulid[$parent->ulid] = $parent->qty;
      }
      foreach ($order_paid_detail_package as $opd) {
        $pkg_qty = ($parent_qty_by_ulid[$opd->tr_order_detail_ulid] ?? 1) * $opd->qty;
        if ($opd->tax_type == 'pb1') {
          $netsales_pb1_total += $opd->tax_amount * $pkg_qty;
        } else if ($opd->tax_type == 'vat') {
          $netsales_vat_total += $opd->tax_amount * $pkg_qty;
        }
        $dpp_total += ($opd->dpp * $pkg_qty);
      }

      $total_tax_total = $netsales_pb1_total + $netsales_vat_total;


      // Gross Sales = SUM(total_billing) -- sudah termasuk pajak, SC, order fee, delivery cost, rounding.
      $gross_sales = $total_billing_total;

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
      $sales_by_category_item = [];
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

        // sales_by_category (CATEGORY SUMMARY) -- diperbaiki 2026-11-24 (permintaan owner):
        //   * qty    = qty HEAD saja (baris tr_order_detail). Isi package (body, tr_order_detail_package)
        //              TIDAK menambah qty (qty body = 0).
        //   * amount = HEAD + BODY. Body dihitung dengan qty efektif = qty head x qty package, dan
        //              dimasukkan ke kategori HEAD-nya (package dijual sebagai 1 kesatuan di bawah head),
        //              BUKAN ke kategori menu anak.
        //   * semua nilai moneter dikali qty -- kolom tod.total disimpan PER SATU UNIT (lihat
        //     OrderServices::CalculateLineItem), dulu grand_total tidak dikali qty sehingga item
        //     dengan qty > 1 dan isi package kekecilan.
        $sales_by_category = DB::select("SELECT
          mcc.name AS category_name,
          SUM( gabungan.qty ) AS qty,
          SUM( gabungan.sub_total ) AS sub_total,
          SUM( gabungan.discount_amount ) AS discount_amount,
          SUM( gabungan.vat_amount ) AS vat_amount,
          SUM( gabungan.pb1_amount ) AS pb1_amount,
          SUM( gabungan.grand_total ) AS grand_total
        FROM
          (
          SELECT
            mi.category_id AS category_id,
            tod.qty AS qty,
            ( (CASE WHEN tod.flag_inclusive_tax = 1 THEN tod.price_pos / (1 + tod.tax_rate/100) ELSE tod.price_pos END) * tod.qty ) AS sub_total,
            ( tod.discount_amount * tod.qty ) AS discount_amount,
            IF( tod.tax_type = 'vat', tod.tax_amount * tod.qty, 0 ) AS vat_amount,
            IF( tod.tax_type = 'pb1', tod.tax_amount * tod.qty, 0 ) AS pb1_amount,
            ( tod.total * tod.qty ) AS grand_total
          FROM
            tr_order_detail tod
            JOIN mr_item_conv mic ON mic.id = tod.menu_id
            JOIN mr_item mi ON mi.id = mic.item_id
          WHERE
            tod.order_number IN (" . $order_number_concat . ")
            AND tod.cancel_at IS NULL
          UNION ALL
          SELECT
            mi.category_id AS category_id,
            0 AS qty,
            ( (CASE WHEN pkg.flag_inclusive_tax = 1 THEN pkg.price_pos / (1 + pkg.tax_rate/100) ELSE pkg.price_pos END) * (head.qty * pkg.qty) ) AS sub_total,
            ( pkg.discount_amount * (head.qty * pkg.qty) ) AS discount_amount,
            IF( pkg.tax_type = 'vat', pkg.tax_amount * (head.qty * pkg.qty), 0 ) AS vat_amount,
            IF( pkg.tax_type = 'pb1', pkg.tax_amount * (head.qty * pkg.qty), 0 ) AS pb1_amount,
            ( pkg.total * (head.qty * pkg.qty) ) AS grand_total
          FROM
            tr_order_detail_package pkg
            JOIN tr_order_detail head ON head.ulid = pkg.tr_order_detail_ulid
            JOIN mr_item_conv mic ON mic.id = head.menu_id
            JOIN mr_item mi ON mi.id = mic.item_id
          WHERE
            pkg.tr_order_detail_ulid IN (" . $ulid_orderdetail_concat . ")
            AND head.cancel_at IS NULL
          ) AS gabungan
          JOIN mr_category mcc ON mcc.id = gabungan.category_id
        GROUP BY
          category_name");

        // sales_by_category_item (2026-11-23, BARU) -- breakdown NESTED kategori->item (BEDA
        // dari sales_by_category di atas yang FLAT per kategori doang, item di dalamnya
        // di-collapse habis oleh GROUP BY category_name). Query SAMA PERSIS, cuma GROUP BY
        // ditambah menu_name + SELECT menu_name di level luar -- gabungan.menu_name udah ada
        // dari dulu di subquery, cuma belum pernah di-expose. Assembly nested (kategori jadi
        // header, item jadi sub-baris) dilakukan di PrintServices::printCategoryItemBreakdownSection(),
        // bukan di sini -- hasil query ini tetap FLAT, sama filosofi sales_by_staff_payment.
        // 2026-11-24: diperbaiki -- head (is_body=0) diurutkan DI ATAS body/isi package (is_body=1) dalam
        // tiap kategori; nilai dikali qty; body ikut kategori head (lihat komentar di SQL).
        $sales_by_category_item = DB::select("SELECT
          mcc.name AS category_name,
          gabungan.menu_name,
          gabungan.is_body,
          SUM( gabungan.qty ) AS qty,
          SUM( gabungan.sub_total ) AS sub_total,
          SUM( gabungan.discount_amount ) AS discount_amount,
          SUM( gabungan.vat_amount ) AS vat_amount,
          SUM( gabungan.pb1_amount ) AS pb1_amount,
          SUM( gabungan.grand_total ) AS grand_total
        FROM
          (
          -- HEAD (is_body = 0): baris tr_order_detail, qty = qty head
          SELECT
            mi.category_id AS category_id,
            mi.NAME AS menu_name,
            0 AS is_body,
            tod.qty AS qty,
            ( (CASE WHEN tod.flag_inclusive_tax = 1 THEN tod.price_pos / (1 + tod.tax_rate/100) ELSE tod.price_pos END) * tod.qty ) AS sub_total,
            ( tod.discount_amount * tod.qty ) AS discount_amount,
            IF( tod.tax_type = 'vat', tod.tax_amount * tod.qty, 0 ) AS vat_amount,
            IF( tod.tax_type = 'pb1', tod.tax_amount * tod.qty, 0 ) AS pb1_amount,
            ( tod.total * tod.qty ) AS grand_total
          FROM
            tr_order_detail tod
            JOIN mr_item_conv mic ON mic.id = tod.menu_id
            JOIN mr_item mi ON mi.id = mic.item_id
          WHERE
            tod.order_number IN (" . $order_number_concat . ")
            AND tod.cancel_at IS NULL
          UNION ALL
          -- BODY (is_body = 1): isi package. Nama = menu anak, KATEGORI = kategori HEAD (sama
          -- dengan CATEGORY SUMMARY, supaya total kedua section sama), qty efektif = qty head x qty package.
          SELECT
            hmi.category_id AS category_id,
            cmi.NAME AS menu_name,
            1 AS is_body,
            ( head.qty * pkg.qty ) AS qty,
            ( (CASE WHEN pkg.flag_inclusive_tax = 1 THEN pkg.price_pos / (1 + pkg.tax_rate/100) ELSE pkg.price_pos END) * (head.qty * pkg.qty) ) AS sub_total,
            ( pkg.discount_amount * (head.qty * pkg.qty) ) AS discount_amount,
            IF( pkg.tax_type = 'vat', pkg.tax_amount * (head.qty * pkg.qty), 0 ) AS vat_amount,
            IF( pkg.tax_type = 'pb1', pkg.tax_amount * (head.qty * pkg.qty), 0 ) AS pb1_amount,
            ( pkg.total * (head.qty * pkg.qty) ) AS grand_total
          FROM
            tr_order_detail_package pkg
            JOIN tr_order_detail head ON head.ulid = pkg.tr_order_detail_ulid
            JOIN mr_item_conv hmic ON hmic.id = head.menu_id
            JOIN mr_item hmi ON hmi.id = hmic.item_id
            JOIN mr_item_conv cmic ON cmic.id = pkg.menu_id
            JOIN mr_item cmi ON cmi.id = cmic.item_id
          WHERE
            pkg.tr_order_detail_ulid IN (" . $ulid_orderdetail_concat . ")
            AND head.cancel_at IS NULL
          ) AS gabungan
          JOIN mr_category mcc ON mcc.id = gabungan.category_id
        GROUP BY
          category_name, menu_name, is_body
        ORDER BY
          category_name, is_body, menu_name");
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

      // sales_by_visit_purpose: dipakai section SALES TYPE SUMMARY (baru) -- group order paid
      // by visit_purpose_id (Dine In/Takeaway/dst), qty+total per tipe.
      $sales_by_visit_purpose = DB::table("tr_order")
        ->join("mr_visit_purpose", "mr_visit_purpose.id", "=", "tr_order.visit_purpose_id")
        ->whereIn("tr_order.order_number", $list_ordernumber->paid)
        ->select(
          "mr_visit_purpose.name as visit_purpose_name",
          DB::raw("COUNT(tr_order.order_number) as qty"),
          DB::raw("SUM(tr_order.total_billing) as total_amount")
        )
        ->groupBy("visit_purpose_name")->get();

      // sales_by_order_source: dipakai section ORDER SOURCE SUMMARY (baru) -- group order paid
      // by order_source (pos/qr/mobile/kiosk). SENGAJA gak di-groupBy doang -- 4 kategori FIXED
      // selalu ditampilkan di section-nya walau salah satu belum ada transaksi sama sekali
      // (dilengkapi ke 0 di sisi PrintServices, bukan di sini -- query ini cuma balikin yang
      // beneran ada datanya).
      $sales_by_order_source = DB::table("tr_order")
        ->whereIn("tr_order.order_number", $list_ordernumber->paid)
        ->select(
          "tr_order.order_source",
          DB::raw("COUNT(tr_order.order_number) as qty"),
          DB::raw("SUM(tr_order.total_billing) as total_amount")
        )
        ->groupBy("order_source")->get();

      // sales_by_staff: dipakai section STAFF SALES SUMMARY (baru) -- group order paid by
      // created_by (user_id yang login pas order dibuat), JOIN mr_user buat nama tampilan.
      // Order tanpa created_by (kalau ada data lama/anomali) gak ikut ke-grouping (INNER JOIN).
      $sales_by_staff = DB::table("tr_order")
        ->join("mr_user", "mr_user.id", "=", "tr_order.created_by")
        ->whereIn("tr_order.order_number", $list_ordernumber->paid)
        ->select(
          "mr_user.fullname as staff_name",
          DB::raw("COUNT(tr_order.order_number) as qty"),
          DB::raw("SUM(tr_order.total_billing) as total_amount")
        )
        ->groupBy("staff_name")->get();

      // sales_by_staff_payment: dipakai section STAFF PAYMENT SUMMARY -- breakdown 2 dimensi
      // staff x payment method. staff_name/payment_method_name DIPISAH (2026-10-22, sebelumnya
      // CONCAT jadi 1 kolom "label") -- lihat komentar lengkap di GetReportDayshiftorEndDay().
      $sales_by_staff_payment = DB::table("tr_order_payment")
        ->join("tr_order", "tr_order.payment_number", "=", "tr_order_payment.payment_number")
        ->join("mr_user", "mr_user.id", "=", "tr_order.created_by")
        ->join("mr_payment_method", "mr_payment_method.id", "=", "tr_order_payment.payment_method_id")
        ->whereIn("tr_order_payment.payment_number", $list_payment_number)
        ->select(
          "mr_user.fullname as staff_name",
          "mr_payment_method.name as payment_method_name",
          DB::raw("COUNT(tr_order_payment.ulid) as qty"),
          DB::raw("SUM(tr_order_payment.payment_amount) as amount")
        )
        ->groupBy("mr_user.fullname", "mr_payment_method.name")->get();

      // cash_in_total: dipakai section CASH FLOW SUMMARY (baru, baris "Cash In") -- total
      // pembayaran METODE BERTIPE CASH selama periode dayshift ini. BUKAN dari kolom
      // tr_dayshift.system_cash_received (kolom itu gak pernah diisi/dipakai di kode manapun),
      // dihitung on-the-fly dari tr_order_payment biar akurat.
      //
      // Filter pakai payment_method_type_id = 1 (ID row "CASH" di mr_payment_method_type,
      // dicek langsung ke data -- 2026-09-23), BUKAN mr_payment_method.name = 'CASH' (nama
      // metode bisa ganti-ganti/nambah metode baru bertipe cash, tapi ID row tipe-nya tetap).
      $cash_in_total = DB::table("tr_order_payment")
        ->join("mr_payment_method", "mr_payment_method.id", "=", "tr_order_payment.payment_method_id")
        ->whereIn("tr_order_payment.payment_number", $list_payment_number)
        ->where("mr_payment_method.payment_method_type_id", 1)
        ->sum("tr_order_payment.payment_amount");

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
          // elemen baru (2026-11-24) -- SELALU ditambah di akhir; pembaca pakai DayShiftServices::recapAmount() by key
          ["pl" => 1, "key" => "Sales Subtotal", "amount" => $subtotal_total],
          ["pl" => 1, "key" => "Rounding", "amount" => $rounding_total],
          ["pl" => 1, "key" => "Total Bills", "amount" => $total_billing_total],
          ["pl" => 1, "key" => "Other Cost", "amount" => $other_cost_total],
          ["pl" => 1, "key" => "Other Cost Count", "amount" => $other_cost_count],
        ],
        // dpp_total/total_tax_total (BARU) -- dipakai baris "DPP"/"Total Tax" section SALES
        // SUMMARY, dihitung di loop tax breakdown di atas (bukan array sales_recapitulation
        // biar gak ganggu index numerik yang udah dipakai PrintServices existing).
        "dpp_total" => $dpp_total,
        "total_tax_total" => $total_tax_total,
        "payment_recapitulation" => $payment_detail_list,
        "sales_by_menu" => $sales_by_menu,
        "sales_by_category" => $sales_by_category,
        "sales_by_category_item" => $sales_by_category_item,
        "sales_by_table" => $sales_by_table_section,
        "sales_by_visit_purpose" => $sales_by_visit_purpose,
        "sales_by_order_source" => $sales_by_order_source,
        "sales_by_staff" => $sales_by_staff,
        "sales_by_staff_payment" => $sales_by_staff_payment,
        "cash_in_total" => $cash_in_total,
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
      // urut shift_time (bukan ulid) -- sama dengan GetReportPerShift(), tidak ambigu.
      $data_dayshift_detail = DayShiftDetailModel::where('dayshift_ulid', $dayshift_ulid)
        ->orderBy('shift_time', 'desc')->first();
      if ($data_dayshift_detail) {
        $pakai_header = false;
      }

      // awal window = End Shift terakhir (shift_time), atau dayin_time kalau dayshift belum
      // pernah End Shift (= 1 shift utuh).
      if ($pakai_header) {
        $starttime = $data_dayshift->dayin_time;
      } else {
        $starttime = $data_dayshift_detail->shift_time;
      }
      // akhir window = dayout_time kalau dayshift sudah di-End Day (supaya order dayshift
      // SESUDAHNYA tidak ikut terhitung), NULL kalau masih berjalan (sampai sekarang).
      $endtime = $data_dayshift->dayout_time ?? null;

      // ambil data -- scope pakai STATUS + order_out(paid)/cancel_at/void_at (lihat GetReportOrderList)
      $data_order_list = self::GetReportOrderList($starttime, $endtime);

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
      $subtotal_total = 0;      // SUM(sub_total) order paid -- "Sales (Subtotal)", SEBELUM diskon
      $rounding_total = 0;      // SUM(rounding_amount) order paid
      $total_billing_total = 0; // SUM(total_billing) order paid -- "Total Bills" & "Gross Sales"
      $netsales_dc_total = 0;
      $netsales_of_total = 0;
      $netsales_sc_total = 0;

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
      $other_cost_total = 0; // SUM(total_billing) order other_cost (FOC) -- BUKAN pendapatan
      $other_cost_count = 0;


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

        // 2026-11-24: Net Sales = total_billing - total_tax (pajak = titipan ke pemerintah; service
        // charge/order fee/delivery cost/rounding = pendapatan kita, jadi ikut Net Sales).
        // Gross Sales/Total Bills = total_billing. Semua dari kolom header tr_order, tidak dihitung ulang.
        if ($orderitem->status == "paid") {
          $net_order = ($orderitem->total_billing ?? 0) - ($orderitem->total_tax ?? 0);
          $number_of_bill += 1;
          $discount_total += $orderitem->total_discount;
          $netsales += $net_order;
          $subtotal_total += ($orderitem->sub_total ?? 0);
          $rounding_total += ($orderitem->rounding_amount ?? 0);
          $total_billing_total += ($orderitem->total_billing ?? 0);
          $netsales_dc_total += $orderitem->delivery_cost;
          $netsales_of_total += $orderitem->order_fee;
          $netsales_sc_total += $orderitem->total_service_charge;
          $pax_total += $orderitem->pax;

          $avg_netsales_per_pax += $net_order;
          $avg_grosssales_per_pax += ($orderitem->total_billing);
          $avg_netsales_per_bill += $net_order;
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
        // other_cost: cuma ditampung ke baris "Other Cost" -- TIDAK masuk $list_ordernumber->paid, jadi tidak
        // ikut penjualan/pajak/kategori/payment.
        if ($orderitem->status == "other_cost") {
          $other_cost_total += ($orderitem->total_billing ?? 0);
          $other_cost_count += 1;
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

      // dpp_total: SAMA PERSIS pola GetReportDayshiftorEndDay() -- lihat komentar di sana.
      $dpp_total = 0;

      foreach ($order_paid_detail as $opd) {
        if ($opd->tax_type == 'pb1') {
          $netsales_pb1_total += ($opd->tax_amount * $opd->qty);
        } else if ($opd->tax_type == 'vat') {
          $netsales_vat_total += ($opd->tax_amount * $opd->qty);
        }
        $dpp_total += ($opd->dpp * $opd->qty);
      }

      // qty efektif package = qty parent x qty package (sama dengan RecalculateOrderTotals()).
      $parent_qty_by_ulid = [];
      foreach ($order_paid_detail as $parent) {
        $parent_qty_by_ulid[$parent->ulid] = $parent->qty;
      }
      foreach ($order_paid_detail_package as $opd) {
        $pkg_qty = ($parent_qty_by_ulid[$opd->tr_order_detail_ulid] ?? 1) * $opd->qty;
        if ($opd->tax_type == 'pb1') {
          $netsales_pb1_total += $opd->tax_amount * $pkg_qty;
        } else if ($opd->tax_type == 'vat') {
          $netsales_vat_total += $opd->tax_amount * $pkg_qty;
        }
        $dpp_total += ($opd->dpp * $pkg_qty);
      }

      $total_tax_total = $netsales_pb1_total + $netsales_vat_total;


      // Gross Sales = SUM(total_billing) -- sudah termasuk pajak, SC, order fee, delivery cost, rounding.
      $gross_sales = $total_billing_total;

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
      $sales_by_category_item = [];
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

        // sales_by_category (CATEGORY SUMMARY) -- diperbaiki 2026-11-24 (permintaan owner):
        //   * qty    = qty HEAD saja (baris tr_order_detail). Isi package (body, tr_order_detail_package)
        //              TIDAK menambah qty (qty body = 0).
        //   * amount = HEAD + BODY. Body dihitung dengan qty efektif = qty head x qty package, dan
        //              dimasukkan ke kategori HEAD-nya (package dijual sebagai 1 kesatuan di bawah head),
        //              BUKAN ke kategori menu anak.
        //   * semua nilai moneter dikali qty -- kolom tod.total disimpan PER SATU UNIT (lihat
        //     OrderServices::CalculateLineItem), dulu grand_total tidak dikali qty sehingga item
        //     dengan qty > 1 dan isi package kekecilan.
        $sales_by_category = DB::select("SELECT
          mcc.name AS category_name,
          SUM( gabungan.qty ) AS qty,
          SUM( gabungan.sub_total ) AS sub_total,
          SUM( gabungan.discount_amount ) AS discount_amount,
          SUM( gabungan.vat_amount ) AS vat_amount,
          SUM( gabungan.pb1_amount ) AS pb1_amount,
          SUM( gabungan.grand_total ) AS grand_total
        FROM
          (
          SELECT
            mi.category_id AS category_id,
            tod.qty AS qty,
            ( (CASE WHEN tod.flag_inclusive_tax = 1 THEN tod.price_pos / (1 + tod.tax_rate/100) ELSE tod.price_pos END) * tod.qty ) AS sub_total,
            ( tod.discount_amount * tod.qty ) AS discount_amount,
            IF( tod.tax_type = 'vat', tod.tax_amount * tod.qty, 0 ) AS vat_amount,
            IF( tod.tax_type = 'pb1', tod.tax_amount * tod.qty, 0 ) AS pb1_amount,
            ( tod.total * tod.qty ) AS grand_total
          FROM
            tr_order_detail tod
            JOIN mr_item_conv mic ON mic.id = tod.menu_id
            JOIN mr_item mi ON mi.id = mic.item_id
          WHERE
            tod.order_number IN (" . $order_number_concat . ")
            AND tod.cancel_at IS NULL
          UNION ALL
          SELECT
            mi.category_id AS category_id,
            0 AS qty,
            ( (CASE WHEN pkg.flag_inclusive_tax = 1 THEN pkg.price_pos / (1 + pkg.tax_rate/100) ELSE pkg.price_pos END) * (head.qty * pkg.qty) ) AS sub_total,
            ( pkg.discount_amount * (head.qty * pkg.qty) ) AS discount_amount,
            IF( pkg.tax_type = 'vat', pkg.tax_amount * (head.qty * pkg.qty), 0 ) AS vat_amount,
            IF( pkg.tax_type = 'pb1', pkg.tax_amount * (head.qty * pkg.qty), 0 ) AS pb1_amount,
            ( pkg.total * (head.qty * pkg.qty) ) AS grand_total
          FROM
            tr_order_detail_package pkg
            JOIN tr_order_detail head ON head.ulid = pkg.tr_order_detail_ulid
            JOIN mr_item_conv mic ON mic.id = head.menu_id
            JOIN mr_item mi ON mi.id = mic.item_id
          WHERE
            pkg.tr_order_detail_ulid IN (" . $ulid_orderdetail_concat . ")
            AND head.cancel_at IS NULL
          ) AS gabungan
          JOIN mr_category mcc ON mcc.id = gabungan.category_id
        GROUP BY
          category_name");

        // sales_by_category_item (2026-11-23, BARU) -- breakdown NESTED kategori->item (BEDA
        // dari sales_by_category di atas yang FLAT per kategori doang, item di dalamnya
        // di-collapse habis oleh GROUP BY category_name). Query SAMA PERSIS, cuma GROUP BY
        // ditambah menu_name + SELECT menu_name di level luar -- gabungan.menu_name udah ada
        // dari dulu di subquery, cuma belum pernah di-expose. Assembly nested (kategori jadi
        // header, item jadi sub-baris) dilakukan di PrintServices::printCategoryItemBreakdownSection(),
        // bukan di sini -- hasil query ini tetap FLAT, sama filosofi sales_by_staff_payment.
        // 2026-11-24: diperbaiki -- head (is_body=0) diurutkan DI ATAS body/isi package (is_body=1) dalam
        // tiap kategori; nilai dikali qty; body ikut kategori head (lihat komentar di SQL).
        $sales_by_category_item = DB::select("SELECT
          mcc.name AS category_name,
          gabungan.menu_name,
          gabungan.is_body,
          SUM( gabungan.qty ) AS qty,
          SUM( gabungan.sub_total ) AS sub_total,
          SUM( gabungan.discount_amount ) AS discount_amount,
          SUM( gabungan.vat_amount ) AS vat_amount,
          SUM( gabungan.pb1_amount ) AS pb1_amount,
          SUM( gabungan.grand_total ) AS grand_total
        FROM
          (
          -- HEAD (is_body = 0): baris tr_order_detail, qty = qty head
          SELECT
            mi.category_id AS category_id,
            mi.NAME AS menu_name,
            0 AS is_body,
            tod.qty AS qty,
            ( (CASE WHEN tod.flag_inclusive_tax = 1 THEN tod.price_pos / (1 + tod.tax_rate/100) ELSE tod.price_pos END) * tod.qty ) AS sub_total,
            ( tod.discount_amount * tod.qty ) AS discount_amount,
            IF( tod.tax_type = 'vat', tod.tax_amount * tod.qty, 0 ) AS vat_amount,
            IF( tod.tax_type = 'pb1', tod.tax_amount * tod.qty, 0 ) AS pb1_amount,
            ( tod.total * tod.qty ) AS grand_total
          FROM
            tr_order_detail tod
            JOIN mr_item_conv mic ON mic.id = tod.menu_id
            JOIN mr_item mi ON mi.id = mic.item_id
          WHERE
            tod.order_number IN (" . $order_number_concat . ")
            AND tod.cancel_at IS NULL
          UNION ALL
          -- BODY (is_body = 1): isi package. Nama = menu anak, KATEGORI = kategori HEAD (sama
          -- dengan CATEGORY SUMMARY, supaya total kedua section sama), qty efektif = qty head x qty package.
          SELECT
            hmi.category_id AS category_id,
            cmi.NAME AS menu_name,
            1 AS is_body,
            ( head.qty * pkg.qty ) AS qty,
            ( (CASE WHEN pkg.flag_inclusive_tax = 1 THEN pkg.price_pos / (1 + pkg.tax_rate/100) ELSE pkg.price_pos END) * (head.qty * pkg.qty) ) AS sub_total,
            ( pkg.discount_amount * (head.qty * pkg.qty) ) AS discount_amount,
            IF( pkg.tax_type = 'vat', pkg.tax_amount * (head.qty * pkg.qty), 0 ) AS vat_amount,
            IF( pkg.tax_type = 'pb1', pkg.tax_amount * (head.qty * pkg.qty), 0 ) AS pb1_amount,
            ( pkg.total * (head.qty * pkg.qty) ) AS grand_total
          FROM
            tr_order_detail_package pkg
            JOIN tr_order_detail head ON head.ulid = pkg.tr_order_detail_ulid
            JOIN mr_item_conv hmic ON hmic.id = head.menu_id
            JOIN mr_item hmi ON hmi.id = hmic.item_id
            JOIN mr_item_conv cmic ON cmic.id = pkg.menu_id
            JOIN mr_item cmi ON cmi.id = cmic.item_id
          WHERE
            pkg.tr_order_detail_ulid IN (" . $ulid_orderdetail_concat . ")
            AND head.cancel_at IS NULL
          ) AS gabungan
          JOIN mr_category mcc ON mcc.id = gabungan.category_id
        GROUP BY
          category_name, menu_name, is_body
        ORDER BY
          category_name, is_body, menu_name");
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

      // sales_by_visit_purpose/sales_by_order_source/sales_by_staff/cash_in_total: SAMA
      // PERSIS pola GetReportDayshiftorEndDay() -- lihat komentar di sana.
      $sales_by_visit_purpose = DB::table("tr_order")
        ->join("mr_visit_purpose", "mr_visit_purpose.id", "=", "tr_order.visit_purpose_id")
        ->whereIn("tr_order.order_number", $list_ordernumber->paid)
        ->select(
          "mr_visit_purpose.name as visit_purpose_name",
          DB::raw("COUNT(tr_order.order_number) as qty"),
          DB::raw("SUM(tr_order.total_billing) as total_amount")
        )
        ->groupBy("visit_purpose_name")->get();

      $sales_by_order_source = DB::table("tr_order")
        ->whereIn("tr_order.order_number", $list_ordernumber->paid)
        ->select(
          "tr_order.order_source",
          DB::raw("COUNT(tr_order.order_number) as qty"),
          DB::raw("SUM(tr_order.total_billing) as total_amount")
        )
        ->groupBy("order_source")->get();

      $sales_by_staff = DB::table("tr_order")
        ->join("mr_user", "mr_user.id", "=", "tr_order.created_by")
        ->whereIn("tr_order.order_number", $list_ordernumber->paid)
        ->select(
          "mr_user.fullname as staff_name",
          DB::raw("COUNT(tr_order.order_number) as qty"),
          DB::raw("SUM(tr_order.total_billing) as total_amount")
        )
        ->groupBy("staff_name")->get();

      // staff_name/payment_method_name DIPISAH (2026-10-22, sebelumnya CONCAT jadi 1 kolom
      // "label") -- lihat komentar lengkap di GetReportDayshiftorEndDay().
      $sales_by_staff_payment = DB::table("tr_order_payment")
        ->join("tr_order", "tr_order.payment_number", "=", "tr_order_payment.payment_number")
        ->join("mr_user", "mr_user.id", "=", "tr_order.created_by")
        ->join("mr_payment_method", "mr_payment_method.id", "=", "tr_order_payment.payment_method_id")
        ->whereIn("tr_order_payment.payment_number", $list_payment_number)
        ->select(
          "mr_user.fullname as staff_name",
          "mr_payment_method.name as payment_method_name",
          DB::raw("COUNT(tr_order_payment.ulid) as qty"),
          DB::raw("SUM(tr_order_payment.payment_amount) as amount")
        )
        ->groupBy("mr_user.fullname", "mr_payment_method.name")->get();

      $cash_in_total = DB::table("tr_order_payment")
        ->join("mr_payment_method", "mr_payment_method.id", "=", "tr_order_payment.payment_method_id")
        ->whereIn("tr_order_payment.payment_number", $list_payment_number)
        ->where("mr_payment_method.payment_method_type_id", 1)
        ->sum("tr_order_payment.payment_amount");

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
          // elemen baru (2026-11-24) -- SELALU ditambah di akhir; pembaca pakai DayShiftServices::recapAmount() by key
          ["pl" => 1, "key" => "Sales Subtotal", "amount" => $subtotal_total],
          ["pl" => 1, "key" => "Rounding", "amount" => $rounding_total],
          ["pl" => 1, "key" => "Total Bills", "amount" => $total_billing_total],
          ["pl" => 1, "key" => "Other Cost", "amount" => $other_cost_total],
          ["pl" => 1, "key" => "Other Cost Count", "amount" => $other_cost_count],
        ],
        "dpp_total" => $dpp_total,
        "total_tax_total" => $total_tax_total,
        "payment_recapitulation" => $payment_detail_list,
        "sales_by_menu" => $sales_by_menu,
        "sales_by_category" => $sales_by_category,
        "sales_by_category_item" => $sales_by_category_item,
        "sales_by_table" => $sales_by_table_section,
        "sales_by_visit_purpose" => $sales_by_visit_purpose,
        "sales_by_order_source" => $sales_by_order_source,
        "sales_by_staff" => $sales_by_staff,
        "sales_by_staff_payment" => $sales_by_staff_payment,
        "cash_in_total" => $cash_in_total,
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
        throw new \Exception('dayshift detail data not found!');
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

      // scope pakai STATUS + order_out(paid)/cancel_at/void_at (lihat GetReportOrderList) -- window
      // = [akhir shift sebelumnya (atau dayin_time), shift_time shift ini).
      $data_order_list = self::GetReportOrderList($starttime, $endtime);

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
      $subtotal_total = 0;      // SUM(sub_total) order paid -- "Sales (Subtotal)", SEBELUM diskon
      $rounding_total = 0;      // SUM(rounding_amount) order paid
      $total_billing_total = 0; // SUM(total_billing) order paid -- "Total Bills" & "Gross Sales"
      $netsales_dc_total = 0;
      $netsales_of_total = 0;
      $netsales_sc_total = 0;

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
      $other_cost_total = 0; // SUM(total_billing) order other_cost (FOC) -- BUKAN pendapatan
      $other_cost_count = 0;


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

        // 2026-11-24: Net Sales = total_billing - total_tax (pajak = titipan ke pemerintah; service
        // charge/order fee/delivery cost/rounding = pendapatan kita, jadi ikut Net Sales).
        // Gross Sales/Total Bills = total_billing. Semua dari kolom header tr_order, tidak dihitung ulang.
        if ($orderitem->status == "paid") {
          $net_order = ($orderitem->total_billing ?? 0) - ($orderitem->total_tax ?? 0);
          $number_of_bill += 1;
          $discount_total += $orderitem->total_discount;
          $netsales += $net_order;
          $subtotal_total += ($orderitem->sub_total ?? 0);
          $rounding_total += ($orderitem->rounding_amount ?? 0);
          $total_billing_total += ($orderitem->total_billing ?? 0);
          $netsales_dc_total += $orderitem->delivery_cost;
          $netsales_of_total += $orderitem->order_fee;
          $netsales_sc_total += $orderitem->total_service_charge;
          $pax_total += $orderitem->pax;

          $avg_netsales_per_pax += $net_order;
          $avg_grosssales_per_pax += ($orderitem->total_billing);
          $avg_netsales_per_bill += $net_order;
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
        // other_cost: cuma ditampung ke baris "Other Cost" -- TIDAK masuk $list_ordernumber->paid, jadi tidak
        // ikut penjualan/pajak/kategori/payment.
        if ($orderitem->status == "other_cost") {
          $other_cost_total += ($orderitem->total_billing ?? 0);
          $other_cost_count += 1;
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

      // dpp_total: SAMA PERSIS pola GetReportDayshiftorEndDay() -- lihat komentar di sana.
      $dpp_total = 0;

      foreach ($order_paid_detail as $opd) {
        if ($opd->tax_type == 'pb1') {
          $netsales_pb1_total += ($opd->tax_amount * $opd->qty);
        } else if ($opd->tax_type == 'vat') {
          $netsales_vat_total += ($opd->tax_amount * $opd->qty);
        }
        $dpp_total += ($opd->dpp * $opd->qty);
      }

      // qty efektif package = qty parent x qty package (sama dengan RecalculateOrderTotals()).
      $parent_qty_by_ulid = [];
      foreach ($order_paid_detail as $parent) {
        $parent_qty_by_ulid[$parent->ulid] = $parent->qty;
      }
      foreach ($order_paid_detail_package as $opd) {
        $pkg_qty = ($parent_qty_by_ulid[$opd->tr_order_detail_ulid] ?? 1) * $opd->qty;
        if ($opd->tax_type == 'pb1') {
          $netsales_pb1_total += $opd->tax_amount * $pkg_qty;
        } else if ($opd->tax_type == 'vat') {
          $netsales_vat_total += $opd->tax_amount * $pkg_qty;
        }
        $dpp_total += ($opd->dpp * $pkg_qty);
      }

      $total_tax_total = $netsales_pb1_total + $netsales_vat_total;

      // Gross Sales = SUM(total_billing) -- sudah termasuk pajak, SC, order fee, delivery cost, rounding.
      $gross_sales = $total_billing_total;

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
      $sales_by_category_item = [];
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

        // sales_by_category (CATEGORY SUMMARY) -- diperbaiki 2026-11-24 (permintaan owner):
        //   * qty    = qty HEAD saja (baris tr_order_detail). Isi package (body, tr_order_detail_package)
        //              TIDAK menambah qty (qty body = 0).
        //   * amount = HEAD + BODY. Body dihitung dengan qty efektif = qty head x qty package, dan
        //              dimasukkan ke kategori HEAD-nya (package dijual sebagai 1 kesatuan di bawah head),
        //              BUKAN ke kategori menu anak.
        //   * semua nilai moneter dikali qty -- kolom tod.total disimpan PER SATU UNIT (lihat
        //     OrderServices::CalculateLineItem), dulu grand_total tidak dikali qty sehingga item
        //     dengan qty > 1 dan isi package kekecilan.
        $sales_by_category = DB::select("SELECT
          mcc.name AS category_name,
          SUM( gabungan.qty ) AS qty,
          SUM( gabungan.sub_total ) AS sub_total,
          SUM( gabungan.discount_amount ) AS discount_amount,
          SUM( gabungan.vat_amount ) AS vat_amount,
          SUM( gabungan.pb1_amount ) AS pb1_amount,
          SUM( gabungan.grand_total ) AS grand_total
        FROM
          (
          SELECT
            mi.category_id AS category_id,
            tod.qty AS qty,
            ( (CASE WHEN tod.flag_inclusive_tax = 1 THEN tod.price_pos / (1 + tod.tax_rate/100) ELSE tod.price_pos END) * tod.qty ) AS sub_total,
            ( tod.discount_amount * tod.qty ) AS discount_amount,
            IF( tod.tax_type = 'vat', tod.tax_amount * tod.qty, 0 ) AS vat_amount,
            IF( tod.tax_type = 'pb1', tod.tax_amount * tod.qty, 0 ) AS pb1_amount,
            ( tod.total * tod.qty ) AS grand_total
          FROM
            tr_order_detail tod
            JOIN mr_item_conv mic ON mic.id = tod.menu_id
            JOIN mr_item mi ON mi.id = mic.item_id
          WHERE
            tod.order_number IN (" . $order_number_concat . ")
            AND tod.cancel_at IS NULL
          UNION ALL
          SELECT
            mi.category_id AS category_id,
            0 AS qty,
            ( (CASE WHEN pkg.flag_inclusive_tax = 1 THEN pkg.price_pos / (1 + pkg.tax_rate/100) ELSE pkg.price_pos END) * (head.qty * pkg.qty) ) AS sub_total,
            ( pkg.discount_amount * (head.qty * pkg.qty) ) AS discount_amount,
            IF( pkg.tax_type = 'vat', pkg.tax_amount * (head.qty * pkg.qty), 0 ) AS vat_amount,
            IF( pkg.tax_type = 'pb1', pkg.tax_amount * (head.qty * pkg.qty), 0 ) AS pb1_amount,
            ( pkg.total * (head.qty * pkg.qty) ) AS grand_total
          FROM
            tr_order_detail_package pkg
            JOIN tr_order_detail head ON head.ulid = pkg.tr_order_detail_ulid
            JOIN mr_item_conv mic ON mic.id = head.menu_id
            JOIN mr_item mi ON mi.id = mic.item_id
          WHERE
            pkg.tr_order_detail_ulid IN (" . $ulid_orderdetail_concat . ")
            AND head.cancel_at IS NULL
          ) AS gabungan
          JOIN mr_category mcc ON mcc.id = gabungan.category_id
        GROUP BY
          category_name");

        // sales_by_category_item (2026-11-23, BARU) -- breakdown NESTED kategori->item (BEDA
        // dari sales_by_category di atas yang FLAT per kategori doang, item di dalamnya
        // di-collapse habis oleh GROUP BY category_name). Query SAMA PERSIS, cuma GROUP BY
        // ditambah menu_name + SELECT menu_name di level luar -- gabungan.menu_name udah ada
        // dari dulu di subquery, cuma belum pernah di-expose. Assembly nested (kategori jadi
        // header, item jadi sub-baris) dilakukan di PrintServices::printCategoryItemBreakdownSection(),
        // bukan di sini -- hasil query ini tetap FLAT, sama filosofi sales_by_staff_payment.
        // 2026-11-24: diperbaiki -- head (is_body=0) diurutkan DI ATAS body/isi package (is_body=1) dalam
        // tiap kategori; nilai dikali qty; body ikut kategori head (lihat komentar di SQL).
        $sales_by_category_item = DB::select("SELECT
          mcc.name AS category_name,
          gabungan.menu_name,
          gabungan.is_body,
          SUM( gabungan.qty ) AS qty,
          SUM( gabungan.sub_total ) AS sub_total,
          SUM( gabungan.discount_amount ) AS discount_amount,
          SUM( gabungan.vat_amount ) AS vat_amount,
          SUM( gabungan.pb1_amount ) AS pb1_amount,
          SUM( gabungan.grand_total ) AS grand_total
        FROM
          (
          -- HEAD (is_body = 0): baris tr_order_detail, qty = qty head
          SELECT
            mi.category_id AS category_id,
            mi.NAME AS menu_name,
            0 AS is_body,
            tod.qty AS qty,
            ( (CASE WHEN tod.flag_inclusive_tax = 1 THEN tod.price_pos / (1 + tod.tax_rate/100) ELSE tod.price_pos END) * tod.qty ) AS sub_total,
            ( tod.discount_amount * tod.qty ) AS discount_amount,
            IF( tod.tax_type = 'vat', tod.tax_amount * tod.qty, 0 ) AS vat_amount,
            IF( tod.tax_type = 'pb1', tod.tax_amount * tod.qty, 0 ) AS pb1_amount,
            ( tod.total * tod.qty ) AS grand_total
          FROM
            tr_order_detail tod
            JOIN mr_item_conv mic ON mic.id = tod.menu_id
            JOIN mr_item mi ON mi.id = mic.item_id
          WHERE
            tod.order_number IN (" . $order_number_concat . ")
            AND tod.cancel_at IS NULL
          UNION ALL
          -- BODY (is_body = 1): isi package. Nama = menu anak, KATEGORI = kategori HEAD (sama
          -- dengan CATEGORY SUMMARY, supaya total kedua section sama), qty efektif = qty head x qty package.
          SELECT
            hmi.category_id AS category_id,
            cmi.NAME AS menu_name,
            1 AS is_body,
            ( head.qty * pkg.qty ) AS qty,
            ( (CASE WHEN pkg.flag_inclusive_tax = 1 THEN pkg.price_pos / (1 + pkg.tax_rate/100) ELSE pkg.price_pos END) * (head.qty * pkg.qty) ) AS sub_total,
            ( pkg.discount_amount * (head.qty * pkg.qty) ) AS discount_amount,
            IF( pkg.tax_type = 'vat', pkg.tax_amount * (head.qty * pkg.qty), 0 ) AS vat_amount,
            IF( pkg.tax_type = 'pb1', pkg.tax_amount * (head.qty * pkg.qty), 0 ) AS pb1_amount,
            ( pkg.total * (head.qty * pkg.qty) ) AS grand_total
          FROM
            tr_order_detail_package pkg
            JOIN tr_order_detail head ON head.ulid = pkg.tr_order_detail_ulid
            JOIN mr_item_conv hmic ON hmic.id = head.menu_id
            JOIN mr_item hmi ON hmi.id = hmic.item_id
            JOIN mr_item_conv cmic ON cmic.id = pkg.menu_id
            JOIN mr_item cmi ON cmi.id = cmic.item_id
          WHERE
            pkg.tr_order_detail_ulid IN (" . $ulid_orderdetail_concat . ")
            AND head.cancel_at IS NULL
          ) AS gabungan
          JOIN mr_category mcc ON mcc.id = gabungan.category_id
        GROUP BY
          category_name, menu_name, is_body
        ORDER BY
          category_name, is_body, menu_name");
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

      // sales_by_visit_purpose/sales_by_order_source/sales_by_staff/cash_in_total: SAMA
      // PERSIS pola GetReportDayshiftorEndDay() -- lihat komentar di sana.
      $sales_by_visit_purpose = DB::table("tr_order")
        ->join("mr_visit_purpose", "mr_visit_purpose.id", "=", "tr_order.visit_purpose_id")
        ->whereIn("tr_order.order_number", $list_ordernumber->paid)
        ->select(
          "mr_visit_purpose.name as visit_purpose_name",
          DB::raw("COUNT(tr_order.order_number) as qty"),
          DB::raw("SUM(tr_order.total_billing) as total_amount")
        )
        ->groupBy("visit_purpose_name")->get();

      $sales_by_order_source = DB::table("tr_order")
        ->whereIn("tr_order.order_number", $list_ordernumber->paid)
        ->select(
          "tr_order.order_source",
          DB::raw("COUNT(tr_order.order_number) as qty"),
          DB::raw("SUM(tr_order.total_billing) as total_amount")
        )
        ->groupBy("order_source")->get();

      $sales_by_staff = DB::table("tr_order")
        ->join("mr_user", "mr_user.id", "=", "tr_order.created_by")
        ->whereIn("tr_order.order_number", $list_ordernumber->paid)
        ->select(
          "mr_user.fullname as staff_name",
          DB::raw("COUNT(tr_order.order_number) as qty"),
          DB::raw("SUM(tr_order.total_billing) as total_amount")
        )
        ->groupBy("staff_name")->get();

      // staff_name/payment_method_name DIPISAH (2026-10-22, sebelumnya CONCAT jadi 1 kolom
      // "label") -- lihat komentar lengkap di GetReportDayshiftorEndDay().
      $sales_by_staff_payment = DB::table("tr_order_payment")
        ->join("tr_order", "tr_order.payment_number", "=", "tr_order_payment.payment_number")
        ->join("mr_user", "mr_user.id", "=", "tr_order.created_by")
        ->join("mr_payment_method", "mr_payment_method.id", "=", "tr_order_payment.payment_method_id")
        ->whereIn("tr_order_payment.payment_number", $list_payment_number)
        ->select(
          "mr_user.fullname as staff_name",
          "mr_payment_method.name as payment_method_name",
          DB::raw("COUNT(tr_order_payment.ulid) as qty"),
          DB::raw("SUM(tr_order_payment.payment_amount) as amount")
        )
        ->groupBy("mr_user.fullname", "mr_payment_method.name")->get();

      $cash_in_total = DB::table("tr_order_payment")
        ->join("mr_payment_method", "mr_payment_method.id", "=", "tr_order_payment.payment_method_id")
        ->whereIn("tr_order_payment.payment_number", $list_payment_number)
        ->where("mr_payment_method.payment_method_type_id", 1)
        ->sum("tr_order_payment.payment_amount");

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
          // elemen baru (2026-11-24) -- SELALU ditambah di akhir; pembaca pakai DayShiftServices::recapAmount() by key
          ["pl" => 1, "key" => "Sales Subtotal", "amount" => $subtotal_total],
          ["pl" => 1, "key" => "Rounding", "amount" => $rounding_total],
          ["pl" => 1, "key" => "Total Bills", "amount" => $total_billing_total],
          ["pl" => 1, "key" => "Other Cost", "amount" => $other_cost_total],
          ["pl" => 1, "key" => "Other Cost Count", "amount" => $other_cost_count],
        ],
        "dpp_total" => $dpp_total,
        "total_tax_total" => $total_tax_total,
        "payment_recapitulation" => $payment_detail_list,
        "sales_by_menu" => $sales_by_menu,
        "sales_by_category" => $sales_by_category,
        "sales_by_category_item" => $sales_by_category_item,
        "sales_by_table" => $sales_by_table_section,
        "sales_by_visit_purpose" => $sales_by_visit_purpose,
        "sales_by_order_source" => $sales_by_order_source,
        "sales_by_staff" => $sales_by_staff,
        "sales_by_staff_payment" => $sales_by_staff_payment,
        "cash_in_total" => $cash_in_total,
      ];
    } catch (\Throwable $e) {
      throw $e;
    }
  }
}
