<?php

namespace App\Services;

use App\Models\BranchModel;
use App\Models\DayShiftDetailModel;
use App\Models\MasterTableSectionPrintCategorySettingModel;
use App\Models\MasterVisitPurposeModel;
use App\Models\SessionModel;
use App\Models\SettingModel;
use App\Models\StationModel;
use App\Models\TableModel;
use App\Models\DaySiftModel;
use App\Models\TableSectionModel;
use App\Models\TrOrderDetailPackageModel;
use App\Models\TrOrderModel;
use App\Models\TrPaymentModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use \Mike42\Escpos\PrintConnectors\WindowsPrintConnector;
use \Mike42\Escpos\Printer;
use \Mike42\Escpos\EscposImage;

class PrintServices
{
  // ResolveFlagInclusiveTax: flag_inclusive_tax di tr_order (invariant per order) baru ada
  // sejak migration 2026_08_09_000003 -- order yang dibuat SEBELUM itu kolomnya NULL. Buat
  // reprint order lama, fallback baca dari baris tr_order_detail pertama order itu (bukan
  // diasumsikan true/false) supaya struk lama tetap akurat.
  public static function ResolveFlagInclusiveTax($data_order): ?bool
  {
    if ($data_order->flag_inclusive_tax !== null) {
      return (bool) $data_order->flag_inclusive_tax;
    }
    $fallback = DB::table('tr_order_detail')
      ->where('order_number', $data_order->order_number)
      ->whereNull('cancel_at')
      ->value('flag_inclusive_tax');
    return $fallback === null ? null : (bool) $fallback;
  }

  // GetTaxBreakdownByType: SUM(qty*tax_amount) per tax_type (PB1/VAT/dst) buat 1 order_number,
  // gabungan tr_order_detail + tr_order_detail_package. Beda dari flag_inclusive_tax,
  // tax_type itu SUMBERNYA per-item (mr_item.tax_type) jadi 1 order bisa campur beberapa
  // tax_type -- dipakai buat breakdown pajak di struk order exclusive-tax.
  public static function GetTaxBreakdownByType(string $order_number): array
  {
    $rows = DB::select("
      SELECT tax_type, SUM(tax_amount) AS tax_amount FROM (
        SELECT trod.tax_type, trod.qty * trod.tax_amount AS tax_amount
        FROM tr_order_detail trod
        WHERE trod.order_number = ? AND trod.cancel_at IS NULL
        UNION ALL
        SELECT trodp.tax_type, (trod.qty * trodp.qty) * trodp.tax_amount AS tax_amount
        FROM tr_order_detail_package trodp
        JOIN tr_order_detail trod ON trod.ulid = trodp.tr_order_detail_ulid
        WHERE trod.order_number = ? AND trod.cancel_at IS NULL
      ) combined
      WHERE tax_type IS NOT NULL
      GROUP BY tax_type
    ", [$order_number, $order_number]);

    $breakdown = [];
    foreach ($rows as $row) {
      if ($row->tax_amount > 0) {
        $breakdown[strtoupper($row->tax_type)] = $row->tax_amount;
      }
    }
    return $breakdown;
  }

  public static function resizeGambar($source = "", $newWidth = 150, $outputPath = "logo_resize.png")
  {
    if ($source == "" || !file_exists($source)) {
      return "Gambar tidak ditemukan";
    }

    list($width, $height, $type) = getimagesize($source);

    switch ($type) {
      case IMAGETYPE_PNG:
        $img = imagecreatefrompng($source);
        break;
      case IMAGETYPE_JPEG:
        $img = imagecreatefromjpeg($source);
        break;
      default:
        return "Format tidak didukung";
    }

    $newHeight = ($height / $width) * $newWidth;

    $resized = imagecreatetruecolor($newWidth, $newHeight);
    $white = imagecolorallocate($resized, 255, 255, 255);
    imagefill($resized, 0, 0, $white);

    imagecopyresampled(
      $resized,
      $img,
      0,
      0,
      0,
      0,
      $newWidth,
      $newHeight,
      $width,
      $height
    );

    imagepng($resized, $outputPath);

    imagedestroy($img);
    imagedestroy($resized);
  }

  public static function line(string $left, string $right, int $width = 0)
  {
    $leftWidth = $width - strlen($right);
    return $left . str_pad($right, $leftWidth, " ", STR_PAD_LEFT) . "\n";
  }

  public static function threeline($qty, string $item, string $harga, int $width = 0)
  {
    // bagi lebar jadi 3 bagian
    $col1Width = 6;
    $col3Width = 10;
    $col2Width = $width - ($col1Width + $col3Width);

    return
      str_pad($qty, $col1Width) .
      str_pad($item, $col2Width, ' ', STR_PAD_RIGHT) .
      str_pad($harga, $col3Width, ' ', STR_PAD_LEFT) . "\n";
  }
  static function kirikakan(string $kiri, string $kanan, int $width)
  {
    $col1Width = 6;
    $col3Width = 10;
    $col2Width = $width - ($col1Width + $col3Width);
    return
      str_pad($kiri, $col2Width + $col1Width, ' ', STR_PAD_RIGHT) .
      str_pad($kanan, $col3Width, ' ', STR_PAD_LEFT) . "\n";
  }

  public static function threeline2($qty, string $item, string $harga, int $width = 0)
  {
    // bagi lebar jadi 3 bagian
    $col1Width = 0;
    $col3Width = 12;
    $col2Width = $width - ($col1Width + $col3Width);

    return
      str_pad($qty, $col1Width) .
      str_pad($item, $col2Width, ' ', STR_PAD_LEFT) .
      str_pad($harga, $col3Width, ' ', STR_PAD_LEFT) . "\n";
  }

  public static function separator($char = '-', $width = 0)
  {
    return str_repeat($char, $width) . "\n";
  }

  // getLoggedInUserFullname ambil nama user yang sedang login dari bearer token request,
  // pola sama seperti OrderServices::getChasierName() -- app ini gak pakai Auth::user() Laravel,
  // login state-nya di tabel mr_session (session_id = token).
  public static function getLoggedInUserFullname($request): ?string
  {
    try {
      $token = $request?->bearerToken();
      if (!$token) return null;
      $session = SessionModel::where('session_id', $token)->first();
      if (!$session) return null;
      $user = json_decode($session->data);
      return $user->fullname ?? $user->username ?? null;
    } catch (\Throwable $e) {
      return null;
    }
  }


  public static function PrintTableChecker2(int $table_section_id, string $order_number, $test = false)
  {
    try {


      $table_section =  TableSectionModel::where('id', $table_section_id)->first();
      if (!$table_section) {
        Log::info("table section tidak ditemukan!");
        return;
      }


      $data_station = StationModel::where('id', $table_section->tablechecker_station_id)->first();
      if (!$data_station) {
        return;
      }

      $data_order = TrOrderModel::leftJoin('mr_member', 'tr_order.member_id', '=', 'mr_member.id')
        ->select('tr_order.*', 'mr_member.name as member_name')
        ->where('tr_order.order_number', $order_number)->first();
      if (!$data_order) {
        Log::info('data order ' . $order_number . ' tidak ditemukan!');
        return;
      }


      // $done_print = 'and trod.done_print = false';
      // if ($test) {
      //   $done_print = '';
      // }
      $mbe = "
      SELECT
      trod.*,
      mi.name as menu_name
      FROM tr_order_detail trod
      JOIN mr_item_conv mic on mic.id = trod.menu_id
      JOIN mr_item mi on mi.id = mic.item_id
      WHERE trod.order_number = ? 
      ";

      $data_order_detail = DB::select($mbe, [$order_number]);
      if (count($data_order_detail) == 0) {
        Log::info('data order detail kosong jadi gak di print untuk order ' . $order_number);
        return;
      }

      // if (count($data_order_detail) > 0) {
      //   return;
      // }


      $data_visitpurpose = MasterVisitPurposeModel::where('id', $data_order->visit_purpose_id)->first();

      $konektor = new WindowsPrintConnector($data_station->printer_name);
      $print = new Printer($konektor);
      $order_number = $data_order->order_number;
      $charPerLine = $data_station->line_character;
      $table_section_name = $table_section->name;
      $visitpurpose_name = $data_visitpurpose->name;
      $last_batch = $data_order->total_batch;
      $pax = $data_order->pax;
      $order_in  = $data_order->order_in;
      $order_queue = $data_order->order_queue;
      $info  = $data_order->order_name;
      if (!empty($data_order->member_name)) {
        $info = $data_order->member_name . " / " . $info;
      }
      $cashier  = $data_order->chasier_name;

      $print->setEmphasis(true);
      $print->setTextSize(2, 2);
      $print->text("TABLE CHECKER\n");
      $print->setEmphasis(FALSE);
      $print->setTextSize(1, 1);
      $print->text(self::separator("-", $charPerLine));

      $meja = TableModel::where('id', $data_order->table_id)->first();



      // $print->text("Date        : " . $data_order->order_date . "\n");
      $print->text("Date        : " . $order_in . "\n");
      $print->text("Customer Name: " . $info . "\n");
      $print->text("Table       : " . $table_section->name . ($meja ? " / " . $meja->name : "") . "\n");
      $print->text("Purpose     : " . $visitpurpose_name . "\n");
      $print->text("Queue       : " . $order_queue . "\n");
      $print->text("Pax         : " . $pax . "\n");
      $print->text("Batch       : " . $last_batch . "\n");
      $print->text("Cashier     : " . $cashier . "\n");


      // $print->feed(1);
      // $print->setJustification(Printer::JUSTIFY_CENTER);
      // $print->setEmphasis(true);
      // $print->setTextSize(1, 2);
      // $print->text("Table Checker\n");
      // $print->setTextSize(1, 1);
      // $print->setEmphasis(false);
      // $print->text(self::separator("=", $charPerLine));
      // $print->setEmphasis(true);
      // $print->setTextSize(1, 2);
      // $print->text("Queue : " . $order_queue . "\n");
      // $print->setTextSize(1, 1);
      // $print->setEmphasis(false);
      // $print->text(self::separator("=", $charPerLine));
      // $print->setEmphasis(true);
      // $print->setTextSize(1, 2);
      // $print->text("Table : " . $table_section_name . "\n");
      // $print->setTextSize(1, 1);
      // $print->setEmphasis(false);
      // $print->text(self::separator("=", $charPerLine));

      // $print->setJustification(Printer::JUSTIFY_LEFT);
      // $print->text("Order       : " . $order_number . "\n");
      // $print->text("Date        : " . $order_in . "\n");
      // $print->text("Purpose     : " . $visitpurpose_name . "\n");
      // $print->text("Waiter      : " . $cashier . "\n");
      // $print->text("Sender      : " . $cashier . "\n");
      // // $print->text("Info        : " . $info . "\n");
      // $print->text("Batch       : " . $last_batch . "\n");
      // $print->text("Customer    : " . $info . "\n");

      $print->text(self::separator("-", $charPerLine));
      $print->setTextSize(1, 2);

      foreach ($data_order_detail as $itemmenu) {

        //jika selesai print lewati
        if ($itemmenu->done_print && !$test) {
          continue;
        }

        $print->text("  " . $itemmenu->qty . " " . $itemmenu->menu_name . "\n");

        $listpackagedetail = DB::select("
        SELECT
          trod.*,
          mi.name as menu_name
        FROM tr_order_detail_package trod
        JOIN mr_item_conv mic on mic.id = trod.menu_id
        JOIN mr_item mi on mi.id = mic.item_id

        WHERE tr_order_detail_ulid = ?", [$itemmenu->ulid]);

        foreach ($listpackagedetail as $itempackage) {
          if (count($listpackagedetail) > 0) {
            $print->text("    " .  $itempackage->qty . " " . $itempackage->menu_name . "\n");
            if ($itempackage->notes && $itempackage->notes != '') {
              $print->setEmphasis(true);
              $print->setTextSize(1, 1);
              $print->text("    * " . $itempackage->notes . "\n");
              $print->setTextSize(1, 2);
              $print->setEmphasis(false);
            }
          }
        }
        if ($itemmenu->notes && $itemmenu->notes != '') {
          $print->setEmphasis(true);
          $print->setTextSize(1, 1);
          $print->text("  notes : " . $itemmenu->notes . "\n");
          $print->setTextSize(1, 2);
          $print->setEmphasis(false);
        }
      }

      $print->setTextSize(1, 1);
      // $print->text(self::separator("-"),$charPerLine);
      $print->text(self::separator("-", $charPerLine));
      $print->text("\n");

      $print->cut();
      $print->close();
    } catch (\Throwable $e) {
      Log::info($e);
    }
  }

  //ini masih fixed
  public static function PrintMainChecker2(int $table_section_id, string $order_number, $test = false)
  {
    try {

      $table_section =  TableSectionModel::where('id', $table_section_id)->first();

      if (!$table_section) {
        Log::info("table section tidak ditemukan!");
        return;
      }


      $data_station = StationModel::where('id', $table_section->mainchecker_station_id)->first();

      if (!$data_station) {
        return;
      }


      $data_order = TrOrderModel::leftJoin('mr_member', 'tr_order.member_id', '=', 'mr_member.id')
        ->select('tr_order.*', 'mr_member.name as member_name')
        ->where('tr_order.order_number', $order_number)->first();

      $data_order_detail = DB::select("
      SELECT
      trod.*,
      mi.name as menu_name
      FROM tr_order_detail trod
      JOIN mr_item_conv mic on mic.id = trod.menu_id
      JOIN mr_item mi on mi.id = mic.item_id
      WHERE trod.order_number = ? and trod.done_print = false
      ", [$order_number]);

      if (count($data_order_detail) == 0) {
        Log::info('data order detail kosong jadi gak di print untuk order ' . $order_number);
        return;
      }

      $data_visitpurpose = MasterVisitPurposeModel::where('id', $data_order->visit_purpose_id)->first();

      $konektor = new WindowsPrintConnector($data_station->printer_name);
      $print = new Printer($konektor);

      $order_number = $data_order->order_number;
      // $charPerLine = 48;
      $charPerLine = $data_station->line_character;
      $table_section_name = $table_section->name;
      $visitpurpose_name = $data_visitpurpose->name;
      $last_batch = $data_order->total_batch;
      $pax = $data_order->pax;
      $order_in  = $data_order->order_in;
      $order_queue = $data_order->order_queue;
      $info  = $data_order->order_name;
      if (!empty($data_order->member_name)) {
        $info = $data_order->member_name . " / " . $info;
      }
      $cashier  = $data_order->chasier_name;

      //////////////////// end inisialisasi


      $print->setEmphasis(true);
      $print->setTextSize(2, 2);
      $print->text("MAIN CHECKER\n");
      $print->setEmphasis(FALSE);
      $print->setTextSize(1, 1);
      $print->text(self::separator("-", $charPerLine));


      $meja = TableModel::where('id', $data_order->table_id)->first();


      // $print->text("Date        : " . $data_order->order_date . "\n");
      $print->text("Date        : " . $order_in . "\n");
      $print->text("Customer Name: " . $info . "\n");
      // $print->text("Table       : " . $table_section->name . "\n");
      $print->text("Table       : " . $table_section->name . ($meja ? " / " . $meja->name : "") . "\n");
      $print->text("Purpose     : " . $visitpurpose_name . "\n");
      $print->text("Queue       : " . $order_queue . "\n");
      $print->text("Pax         : " . $pax . "\n");
      $print->text("Batch       : " . $last_batch . "\n");
      $print->text("Cashier     : " . $cashier . "\n");


      // $print->feed(1);
      // $print->setJustification(Printer::JUSTIFY_LEFT);
      // $print->setEmphasis(true);
      // $print->setTextSize(2, 2);
      // $print->text($order_number . "\n");
      // $print->text($order_in . "\n");
      // $print->text("QUEUE : " . $order_queue . "\n");
      // $print->text($table_section_name . "\n");
      // $print->feed(1);
      // $print->text($visitpurpose_name . "\n");
      // $print->feed(1);
      // $print->setEmphasis(false);
      // $print->setTextSize(1, 1);

      // $print->setJustification(Printer::JUSTIFY_LEFT);
      // $print->text("Info        : " . $info . "\n");
      // $print->text("Waiter      : " . $cashier . "\n");
      // $print->text("Sender      : " . $cashier . "\n");
      // $print->text("Batch       : " . $last_batch . "\n");
      // $print->text("Pax         : " . $pax . "\n");

      // $print->text(self::separator("=", $charPerLine));
      // $print->setJustification(Printer::JUSTIFY_CENTER);
      // $print->setEmphasis(true);
      // $print->setTextSize(1, 2);
      // $print->text("MAIN CHECKER" . "\n");
      // $print->setTextSize(1, 1);
      // $print->setEmphasis(false);
      $print->text(self::separator("-", $charPerLine));
      $print->setJustification(Printer::JUSTIFY_LEFT);
      $print->setTextSize(1, 2);
      foreach ($data_order_detail as $itemmenu) {

        if ($itemmenu->done_print && !$test) {
          continue;
        }

        $print->text(" " . $itemmenu->qty . " " . $itemmenu->menu_name . "\n");

        $listpackagedetail = DB::select("
        SELECT
          trod.*,
          mi.name as menu_name
        FROM tr_order_detail_package trod
        JOIN mr_item_conv mic on mic.id = trod.menu_id
        JOIN mr_item mi on mi.id = mic.item_id

        WHERE tr_order_detail_ulid = ?", [$itemmenu->ulid]);

        foreach ($listpackagedetail as $itempackage) {
          if (count($listpackagedetail) > 0) {
            $print->text("  " .  $itempackage->qty . " " . $itempackage->menu_name . "\n");
            if ($itempackage->notes && $itempackage->notes != '') {
              $print->setEmphasis(true);
              $print->setTextSize(1, 1);
              $print->text("  * " . $itempackage->notes . "\n");
              $print->setTextSize(2, 2);
              $print->setEmphasis(false);
            }
          }
        }
        if ($itemmenu->notes && $itemmenu->notes != '') {
          $print->setEmphasis(true);
          $print->setTextSize(1, 1);
          $print->text(" notes : " . $itemmenu->notes . "\n");
          $print->setTextSize(1, 2);
          $print->setEmphasis(false);
        }
      }
      $print->setTextSize(1, 1);
      $print->text(self::separator("-"));
      $print->text(self::separator("-"));
      $print->feed(1);

      $print->cut();
      $print->close();
    } catch (\Throwable $e) {
      Log::info($e);
    }
  }
  ////////////////////

  public static function PrintPriparationStation(int $table_section, string $order_number, $test = false)
  {
    try {
      // Log::info("$table_section $order_number");
      $datasubcategorystation = MasterTableSectionPrintCategorySettingModel::where('table_section_id', $table_section)
        ->get();

      if (count($datasubcategorystation) == 0) {
        Log::info("data sub category di table section kosong!");
        return;
      }


      // $data_station = StationModel::where('id', $table_section->tablechecker_station_id)->first();


      $data_order = TrOrderModel::leftJoin('mr_member', 'tr_order.member_id', '=', 'mr_member.id')
        ->select('tr_order.*', 'mr_member.name as member_name')
        ->where('tr_order.order_number', $order_number)->first();
      $data_visitpurpose = MasterVisitPurposeModel::where('id', $data_order->visit_purpose_id)->first();

      $doneprint = ' and trod.done_print = false ';

      if ($test) {
        $doneprint = '';
      }

      $data_order_detail = DB::select("
      SELECT

      trod.ulid,
      trod.menu_id,
      trod.qty,
      trod.notes,
      mi.* 
      FROM tr_order_detail trod
      JOIN mr_item_conv mic on mic.id = trod.menu_id
      JOIN mr_item mi on mi.id = mic.item_id

      WHERE trod.order_number = ? " . $doneprint, [$order_number]);


      // foreach ($data_order_detail as $order_item) {
      //   // $package = [];
      //   $listpackagedetail = DB::select("
      //           SELECT
      //           trod.ulid,
      //           trod.menu_id,
      //           trod.qty,
      //           trod.notes,
      //           mi.* 
      //           FROM tr_order_detail_package trod
      //           JOIN mr_item_conv mic on mic.id = trod.menu_id
      //           JOIN mr_item mi on mi.id = mic.item_id

      //           WHERE trod.tr_order_detail_ulid = ?", [$order_item->ulid]);
      //   $order_item->package_detail = $listpackagedetail;
      // }


      // $data_order_detail_package = TrOrderDetailPackageModel:: 

      $total_item = 0;
      $daftar_menu_mau_print = [];

      foreach ($data_order_detail as $item) {
        $total_item += $item->qty;

        $master_item = DB::select("
               SELECT
                mic.id as itemconv_id,
                mi.id as item_id,
                mi.short_name as item_name,
                mi.subcategory_id
                FROM mr_item_conv mic
                JOIN mr_item mi on mi.id = mic.item_id
                WHERE mic.id = ? ", [$item->menu_id]);

        // DISINI NGECEK ITEM SPARATE PRINT ATAU ENGGA SAAT INI BELUM IMPLEMENTASI (DARI MASTER ITEM ATAS INI) NARIK DATA MASTER ITEM NANTI ADA KOLOM SPARATE
        // SEKARANG BELUM IMPLEMENTASI JADI SEMENTARA TAK SPARATE DULU UNTUK SEKARANG IMPLEMENTASI NO DULU

        $print_tablesection_subcategory_setting = MasterTableSectionPrintCategorySettingModel::where('table_section_id', $table_section)
          ->where("sub_category_id", $master_item[0]->subcategory_id)
          ->first();

        $ddata_package = [];
        $listpackagedetail = DB::select("
                SELECT
                trod.ulid,
                trod.menu_id,
                trod.qty,
                trod.notes,
                mi.* 
                FROM tr_order_detail_package trod
                JOIN mr_item_conv mic on mic.id = trod.menu_id
                JOIN mr_item mi on mi.id = mic.item_id

                WHERE trod.tr_order_detail_ulid = ?", [$item->ulid]);

        foreach ($listpackagedetail as $package) {
          $master_item2 = DB::select("
               SELECT
                mic.id as itemconv_id,
                mi.id as item_id,
                mi.short_name as item_name,
                mi.subcategory_id
                FROM mr_item_conv mic
                JOIN mr_item mi on mi.id = mic.item_id
                WHERE mic.id = ? ", [$package->menu_id]);

          $ddata_package[] = [
            "qty" => $package->qty,
            "item_name" => $master_item2[0]->item_name,
            "item_notes" => $package->notes,
          ];
        }



        for ($i = 0; $i < $item->qty; $i++) {
          $daftar_menu_mau_print[] = [
            "item_name" => $master_item[0]->item_name,
            "station_id" => $print_tablesection_subcategory_setting->station_id,
            "item_notes" => $item->notes,
            "data_package" => $ddata_package,

          ];
        }
      }

      // lopingan daftar menu yang mau di print 
      $itungan_item = 1;

      foreach ($daftar_menu_mau_print as $itemmauprint) {
        $data_station = StationModel::where('id', $itemmauprint['station_id'])->first();
        $ngeprintasek = new GeneralLabel;
        $ngeprintasek->setMargin(0, 2);
        $ngeprintasek->setNamePrinter($data_station->printer_name);
        $ngeprintasek->setText($data_order->order_in);
        $ngeprintasek->setText($data_order->order_queue . " | " . $data_order->order_name . " | $itungan_item/$total_item");
        $ngeprintasek->setText($data_visitpurpose->name);
        $ngeprintasek->setText($itemmauprint['item_name']);

        if (count($itemmauprint['data_package']) > 0) {
          foreach ($itemmauprint['data_package'] as $itempackage) {
            $ngeprintasek->setText(" " . $itempackage['qty'] . "x " . $itempackage['item_name']);
            if ($itempackage['item_notes'] && $itempackage['item_notes'] != '') {
              $ngeprintasek->setText('   * ' . $itempackage['item_notes']);
            }
          }
        }
        if ($item->notes && $item->notes != '') {
          $ngeprintasek->setText(' notes : ' . $item->notes);
        }
        $ngeprintasek->sikat();
        $itungan_item = $itungan_item + 1;
      }


      // foreach ($data_order_detail as $item) {

      //   if (count($item->package_detail) == 0) {
      //     //ITEM BIASA
      //     $total_qty = $item->qty;
      //     foreach ($datasubcategorystation as $stationnary) {
      //       if ($item->subcategory_id == $stationnary->sub_category_id) {
      //         $data_station = StationModel::where('id', $stationnary->station_id)->first();
      //         if ($data_station) {
      //           for ($i = 1; $i <= $total_qty; $i++) {
      //             $ngeprint = new GeneralLabel;

      //             $ngeprint->setNamePrinter($data_station->printer_name);
      //             $ngeprint->setText($data_order->order_in);
      //             $ngeprint->setText($data_order->order_queue . " | " . $data_order->order_name . " | $itungan_item/$total_item");
      //             $ngeprint->setText($data_visitpurpose->name);
      //             $ngeprint->setText($item->name);
      //             if ($item->notes && $item->notes != '') {
      //               $ngeprint->setText(' notes : ' . $item->notes);
      //             }
      //             $ngeprint->sikat();

      //             $itungan_item++;
      //           }
      //         }
      //       }
      //     }
      //   } else {


      //     //VERSI PACKAGE
      //     $total_qty = $item->qty;
      //     foreach ($datasubcategorystation as $stationnary) {
      //       if ($item->subcategory_id == $stationnary->sub_category_id) {
      //         $data_station = StationModel::where('id', $stationnary->station_id)->first();

      //         if ($data_station) {
      //           for ($i = 1; $i <= $total_qty; $i++) {
      //             $ngeprint = new GeneralLabel;
      //             $ngeprint->setMargin(1, 5);

      //             $ngeprint->setNamePrinter($data_station->name);
      //             $ngeprint->setText($data_order->order_in);
      //             $ngeprint->setText($data_order->order_queue . " | " . $data_order->order_name . " | $i/$total_qty");
      //             $ngeprint->setText($data_visitpurpose->name);
      //             $ngeprint->setText($item->name);

      //             //itempackage
      //             $listpackagedetail = DB::select("
      //               SELECT
      //               trod.ulid,
      //               trod.menu_id,
      //               trod.qty,
      //               trod.notes,
      //               mi.* 
      //               FROM tr_order_detail_package trod
      //               JOIN mr_item_conv mic on mic.id = trod.menu_id
      //               JOIN mr_item mi on mi.id = mic.item_id

      //               WHERE trod.tr_order_detail_ulid = ?", [$item->ulid]);

      //             foreach ($listpackagedetail as $itempackage) {

      //               foreach ($datasubcategorystation as $stationnaryB) {

      //                 if ($itempackage->subcategory_id == $stationnaryB->sub_category_id) {

      //                   $data_station2 = StationModel::where('id', $stationnaryB->station_id)->first();
      //                   if ($data_station2) {
      //                     $ngeprint->setText(" " . $itempackage->qty . "x " . $itempackage->name);


      //                     if ($itempackage->notes && $itempackage->notes != '') {
      //                       $ngeprint->setText('   * ' . $itempackage->notes);
      //                     }
      //                   }
      //                 }
      //               }
      //             }
      //             if ($item->notes && $item->notes != '') {
      //               $ngeprint->setText(' notes : ' . $item->notes);
      //             }
      //             $ngeprint->sikat();
      //           }
      //         }
      //       }
      //     }
      //   }
      // }
    } catch (\Throwable $e) {
      Log::info($e);
    }
  }


  public static function PrintPayment(string $order_number)
  {
    try {

      $data_order = TrOrderModel::leftJoin('mr_member', 'tr_order.member_id', '=', 'mr_member.id')
        ->select('tr_order.*', 'mr_member.name as member_name')
        ->where('tr_order.order_number', $order_number)->first();

      $table_section =  TableSectionModel::where('id', $data_order->table_section_id)->first();
      if (!$table_section) {
        Log::info("table section tidak ditemukan!");
        return;
      }
      $settingan = SettingModel::first();
      $data_station = StationModel::where('id', $settingan->default_station)->first();

      if (!$data_station) {
        return;
      }



      $payment_number = $data_order->payment_number;
      $detail_payment = DB::select("SELECT
      tpd.*,
      mpm.name as payment_method_name
      FROM tr_order_payment tpd
      JOIN mr_payment_method mpm on mpm.id = tpd.payment_method_id
      WHERE tpd.payment_number = ?", [$payment_number]);
      $data_order_detail = DB::select("
      SELECT
      trod.*,
      mi.name as menu_name,
      mp.name as promo_name
      FROM tr_order_detail trod
      JOIN mr_item_conv mic on mic.id = trod.menu_id
      JOIN mr_item mi on mi.id = mic.item_id
      LEFT JOIN mr_promo mp on mp.id = trod.promo_id
      WHERE trod.order_number = ? 
      ", [$order_number]);


      $data_visitpurpose = MasterVisitPurposeModel::where('id', $data_order->visit_purpose_id)->first();
      $konektor = new WindowsPrintConnector($data_station->printer_name);
      $print = new Printer($konektor);
      $branch = BranchModel::first();

      $textHeader =  $branch->printing_header;
      $textFooter =  $branch->printing_footer;
      $order_number = $data_order->order_number;
      $charPerLine = $data_station->line_character;
      $table_section_name = $table_section->name;
      $visitpurpose_name = $data_visitpurpose->name;
      $last_batch = $data_order->total_batch;
      $pax = $data_order->pax;
      $order_in  = $data_order->order_in;
      $order_queue = $data_order->order_queue;
      $info  = $data_order->order_name;
      if (!empty($data_order->member_name)) {
        $info = $data_order->member_name . " / " . $info;
      }
      $cashier  = $data_order->chasier_name ?? '';

      $print->setJustification(Printer::JUSTIFY_CENTER);

      // logo header: pakai logo_header_src jika ada, fallback ke logo_resize.png
      $logoSrc = !empty($branch->logo_header_src)
        ? public_path(ltrim($branch->logo_header_src, '/'))
        : public_path('logo_resize.png');
      if (file_exists($logoSrc)) {
        self::resizeGambar($logoSrc, 180, public_path('logo_resize.png'));
        $imageLogo = EscposImage::load(public_path("logo_resize.png"), false);
        $print->bitImage($imageLogo);
        $print->text("\n");
      }

      $print->setEmphasis(true);
      $print->text("$textHeader\n");


      //SETINGAN ITERABLE PAID AND VOID

      //update print ke ketika ngeprint
      if ($data_order->status == "paid") {
        TrOrderModel::where('order_number', $order_number)->update(['print_ke' => $data_order->print_ke + 1]);
        //DIGINIIN DIATAS SOALNYA UPDATENYA SETELAH NGAMBILDATA 
        $ke = $data_order->print_ke + 1;
        if ($ke > 1) {
          $print->setTextSize(1, 2); // gedene
          $print->text("COPY " . $ke . "\n");
          $print->setTextSize(1, 1); // gedene
        }
      } elseif ($data_order->status == "void") {
        $print->setTextSize(1, 2); // gedene
        $print->text("VOID");
        $print->setTextSize(1, 1); // gedene

        TrOrderModel::where('order_number', $order_number)->update(['void_print_ke' => $data_order->void_print_ke + 1]);
        //DIGINIIN DIATAS SOALNYA UPDATENYA SETELAH NGAMBILDATA 
        $ke = $data_order->void_print_ke + 1;
        if ($ke > 1) {
          $print->setTextSize(1, 2); // gedene
          $print->text(" COPY " . $ke . "\n");
          $print->setTextSize(1, 1); // gedene
        }
      }

      /////////////////////////////////////////////////



      $print->setEmphasis(false);

      $print->setJustification(Printer::JUSTIFY_LEFT);
      $print->text(self::separator("-", $charPerLine));

      $print->text("No          : " . $data_order->payment_number . "\n");
      $print->text("Sales No    : " . $data_order->order_number . "\n");
      // $print->text("Date        : " . $data_order->order_date . "\n");
      $print->text("Date        : " . $data_order->order_in . "\n");
      $print->text("Customer Name: " . $info . "\n");
      // $print->text("Table       : " . $table_section_name . "\n");
      // $print->text("Purpose     : " . $visitpurpose_name . "\n");
      $print->text("Pax         : " . $pax . "\n");
      $print->text("Cashier     : " . $cashier . "\n");
      $print->text("Status      : ");
      $print->setEmphasis(true);
      $print->text(strtoupper($data_order->status) . "\n");
      $print->setEmphasis(false);
      $print->text(self::separator("-", $charPerLine));
      $displaySubtotal = 0;
      foreach ($data_order_detail as $itemmenu) {
        // Subtotal di struk itu SEBELUM diskon -- pakai dpp (net-of-tax, sebelum diskon) x
        // (1 + tax_rate) buat balikin harga aslinya, BUKAN $itemmenu->total (yang sekarang udah
        // net-of-discount sejak restrukturisasi kolom), biar "Subtotal - Discount = Grand Total".
        $displaySubtotal += $itemmenu->qty * $itemmenu->dpp * (1 + $itemmenu->tax_rate / 100);
        $print->text(self::threeline($itemmenu->qty, $itemmenu->menu_name, number_format($itemmenu->total, 0, ',', '.'), $charPerLine));
        $totalItemDiscount = $itemmenu->discount_amount;

        $listpackagedetail = DB::select("
        SELECT
          trod.*,
          mi.name as menu_name,
          mp.name as promo_name
        FROM tr_order_detail_package trod
        JOIN mr_item_conv mic on mic.id = trod.menu_id
        JOIN mr_item mi on mi.id = mic.item_id
        LEFT JOIN mr_promo mp on mp.id = trod.promo_id
        WHERE tr_order_detail_ulid = ?", [$itemmenu->ulid]);

        foreach ($listpackagedetail as $itempackage) {
          if (count($listpackagedetail) > 0) {
            $displaySubtotal += $itemmenu->qty * $itempackage->qty * $itempackage->dpp * (1 + $itempackage->tax_rate / 100);
            $print->text(self::threeline("", " " . $itempackage->qty . " " . $itempackage->menu_name, number_format($itempackage->total, 0, ',', '.'), $charPerLine));
            $totalItemDiscount += $itempackage->discount_amount;

            if ($itempackage->notes != null || $itempackage->notes != '') {
              $print->text(self::threeline("", " *" . $itempackage->notes, '', $charPerLine));
            }
          }
        }

        if ($totalItemDiscount > 0) {
          $promoNameLabel = $itemmenu->promo_name ? " % " . $itemmenu->promo_name : " % Promo";
          $print->text(self::threeline("", $promoNameLabel, "-" . number_format($totalItemDiscount, 0, ',', '.'), $charPerLine));
        }
        if ($itemmenu->notes != null || $itemmenu->notes != '') {
          $print->text(self::line('notes :' . $itemmenu->notes, "",  0));
        }
      }
      $print->text(self::separator("-", $charPerLine));
      $print->text($data_order->total_item . " Items" . "\n");
      $print->setJustification(Printer::JUSTIFY_RIGHT);

      // $print->text(self::threeline2("", "Delivery Cost :", "0", $charPerLine)); // disiini
      // $print->text(self::threeline2("", "Order Fee :", "0", $charPerLine));


      $isInclusiveTax = self::ResolveFlagInclusiveTax($data_order);
      $taxBreakdown = self::GetTaxBreakdownByType($data_order->order_number);

      $print->text("\n");

      if ($data_order->total_discount > 0) {
        $print->text(self::threeline2("", "Subtotal :", number_format($displaySubtotal, 0, ',', '.'), $charPerLine));
        $print->text(self::threeline2("", "Discount :", "-" . number_format($data_order->total_discount, 0, ',', '.'), $charPerLine));
        $print->text(self::separator("-", $charPerLine));
      }

      // exclusive: breakdown pajak per tax_type di ATAS Grand Total (bisa lebih dari 1 baris
      // kalau order-nya campur PB1 & VAT). inclusive (atau gak ketauan/$isInclusiveTax null)
      // -- gak ada breakdown di sini, cuma catatan "Price Inclusive of ..." di bawah (perilaku lama).
      if ($isInclusiveTax === false) {
        foreach ($taxBreakdown as $taxTypeLabel => $taxAmount) {
          $print->text(self::threeline2("", $taxTypeLabel . " :", number_format($taxAmount, 0, ',', '.'), $charPerLine));
        }
      }

      $print->setEmphasis(true);
      $print->setTextSize(1, 2); // gedene
      $print->text(self::threeline2("", "Grand Total :", number_format($data_order->total_billing, 0, ',', '.'), $charPerLine));
      $print->setTextSize(1, 1); //normal e
      $print->setEmphasis(false);

      $print->text("\n");
      foreach ($detail_payment as $itempayment) {
        $print->text(self::threeline2("", $itempayment->payment_method_name, number_format($itempayment->payment_amount, 0, ',', '.'), $charPerLine));
      }

      // $print->text("Order Fee : 5.000"."\n");
      // $print->text("Grand Total : 57.000"."\n");
      // $print->text("Qris BCA : 57.000"."\n");

      // $print->setJustification(Printer::JUSTIFY_LEFT);
      $print->text(self::separator("-", $charPerLine));
      $print->text(self::threeline2("", "Change :", "0", $charPerLine));
      if ($isInclusiveTax !== false && !empty($taxBreakdown)) {
        $taxLabel = implode(' & ', array_keys($taxBreakdown));
        $print->text(self::threeline2("", "Price Inclusive of " . $taxLabel . " :", number_format($data_order->total_tax, 0, ',', '.'), $charPerLine));
      }

      $print->text(self::separator("-", $charPerLine));
      $print->setJustification(Printer::JUSTIFY_CENTER);
      $print->text($textFooter);

      // footer image jika ada
      if (!empty($branch->image_footer_src)) {
        $footerSrc = public_path(ltrim($branch->image_footer_src, '/'));
        if (file_exists($footerSrc)) {
          self::resizeGambar($footerSrc, 150, public_path('footer_resize.png'));
          $imageFooter = EscposImage::load(public_path('footer_resize.png'), false);
          $print->text("\n");
          $print->bitImage($imageFooter);
        }
      }

      $print->feed(2);
      $print->cut();
      $print->close();
    } catch (\Throwable $e) {
      Log::info($e);
    }
  }

  public static function PrintBill(string $order_number)
  {
    try {
      $data_order = TrOrderModel::leftJoin('mr_member', 'tr_order.member_id', '=', 'mr_member.id')
        ->select('tr_order.*', 'mr_member.name as member_name')
        ->where('tr_order.order_number', $order_number)->first();

      $table_section =  TableSectionModel::where('id', $data_order->table_section_id)->first();
      if (!$table_section) {
        Log::info("table section tidak ditemukan!");
        return;
      }

      $settingan = SettingModel::first();
      $data_station = StationModel::where('id', $settingan->default_station)->first();

      if (!$data_station) {
        return;
      }

      $data_order_detail = DB::select("
      SELECT
      trod.*,
      mi.name as menu_name,
      mp.name as promo_name
      FROM tr_order_detail trod
      JOIN mr_item_conv mic on mic.id = trod.menu_id
      JOIN mr_item mi on mi.id = mic.item_id
      LEFT JOIN mr_promo mp on mp.id = trod.promo_id
      WHERE trod.order_number = ? 
      ", [$order_number]);
      $data_visitpurpose = MasterVisitPurposeModel::where('id', $data_order->visit_purpose_id)->first();

      $konektor = new WindowsPrintConnector($data_station->printer_name);
      $print = new Printer($konektor);
      /////////////////////
      // self::resizeGambar(public_path("img/logo1.png"), 165);
      // $imageLogo = EscposImage::load(public_path("logo_resize.png"), false);

      $order_number = $data_order->order_number;
      $charPerLine = $data_station->line_character;
      $table_section_name = $table_section->name;
      $visitpurpose_name = $data_visitpurpose->name;
      $last_batch = $data_order->total_batch;
      $pax = $data_order->pax;

      $order_in  = $data_order->order_in;
      $order_queue = $data_order->order_queue;
      $info  = $data_order->order_name;
      if (!empty($data_order->member_name)) {
        $info = $data_order->member_name . " / " . $info;
      }
      $cashier  = $data_order->chasier_name;

      if ($data_order->status == 'cancel') {
        $print->setEmphasis(true);
        $print->setTextSize(2, 2);
        $print->text("CANCEL ORDER\n");

        if ($data_order->cancel_print_ke != 0) {
          $print->setTextSize(1, 2);
          $print->text("COPY " . ($data_order->cancel_print_ke + 1) . "\n");
        }
        $print->setTextSize(1, 1);
        TrOrderModel::where('order_number', $order_number)->update(['cancel_print_ke' => $data_order->cancel_print_ke + 1]);
        $print->setEmphasis(false);
      }

      $print->setJustification(Printer::JUSTIFY_LEFT);
      $print->text(self::separator("-", $charPerLine));


      // $print->text("No          : ".$orderNumber."\n");
      // $print->text("Sales No    : ".$salesNo."\n");
      // $print->text("Date        : " . $data_order->order_date . "\n");
      $print->text("Time In     : " . $order_in . "\n");
      $print->text("Customer Name: " . $info . "\n");
      $print->text("Table       : " . $table_section->name . "\n");
      $print->text("Purpose     : " . $visitpurpose_name . "\n");
      $print->text("Pax         : " . $pax . "\n");
      $print->text("Cashier     : " . $cashier . "\n");

      $print->text("Status      : ");
      $print->setEmphasis(true);
      if ($data_order->status == 'pending') {
        $print->text("NOT PAID" . "\n");
      } else if ($data_order->status == 'cancel') {
        $print->text("CANCELED" . " / " . $data_order->cancel_notes . "\n");
      }
      $print->setEmphasis(false);

      $print->text(self::separator("-", $charPerLine));
      $displaySubtotal = 0;
      foreach ($data_order_detail as $itemmenu) {
        // Subtotal di struk itu SEBELUM diskon -- pakai dpp (net-of-tax, sebelum diskon) x
        // (1 + tax_rate) buat balikin harga aslinya, BUKAN $itemmenu->total (yang sekarang udah
        // net-of-discount sejak restrukturisasi kolom), biar "Subtotal - Discount = Grand Total".
        $displaySubtotal += $itemmenu->qty * $itemmenu->dpp * (1 + $itemmenu->tax_rate / 100);
        $print->text(self::threeline($itemmenu->qty, $itemmenu->menu_name, number_format($itemmenu->total, 0, ',', '.'), $charPerLine));
        $totalItemDiscount = $itemmenu->discount_amount;
        $listpackagedetail = DB::select("
        SELECT
          trod.*,
          mi.name as menu_name,
          mp.name as promo_name
        FROM tr_order_detail_package trod
        JOIN mr_item_conv mic on mic.id = trod.menu_id
        JOIN mr_item mi on mi.id = mic.item_id
        LEFT JOIN mr_promo mp on mp.id = trod.promo_id

        WHERE tr_order_detail_ulid = ?", [$itemmenu->ulid]);

        foreach ($listpackagedetail as $itempackage) {
          if (count($listpackagedetail) > 0) {
            $displaySubtotal += $itemmenu->qty * $itempackage->qty * $itempackage->dpp * (1 + $itempackage->tax_rate / 100);
            $print->text(self::threeline("", " " . $itempackage->qty . " " . $itempackage->menu_name, number_format($itempackage->total, 0, ',', '.'), $charPerLine));
            $totalItemDiscount += $itempackage->discount_amount;

            if ($itempackage->notes != null || $itempackage->notes != '') {
              $print->text(self::threeline("", " *" . $itempackage->notes, '', $charPerLine));
            }
          }
        }

        if ($totalItemDiscount > 0) {
          $promoNameLabel = $itemmenu->promo_name ? " % " . $itemmenu->promo_name : " % Promo";
          $print->text(self::threeline("", $promoNameLabel, "-" . number_format($totalItemDiscount, 0, ',', '.'), $charPerLine));
        }

        if ($itemmenu->notes != null || $itemmenu->notes != '') {
          $print->text(self::line('notes :' . $itemmenu->notes, "",  0));
        }
      }

      $print->text(self::separator("-", $charPerLine));
      $print->text($data_order->total_item . " Items" . "\n");
      $print->setJustification(Printer::JUSTIFY_RIGHT);

      $isInclusiveTax = self::ResolveFlagInclusiveTax($data_order);
      $taxBreakdown = self::GetTaxBreakdownByType($data_order->order_number);

      $print->text("\n");
      if ($data_order->total_discount > 0) {
        $print->text(self::threeline2("", "Subtotal :", number_format($displaySubtotal, 0, ',', '.'), $charPerLine));
        $print->text(self::threeline2("", "Discount :", "-" . number_format($data_order->total_discount, 0, ',', '.'), $charPerLine));
        $print->text(self::separator("-", $charPerLine));
      }

      if ($isInclusiveTax === false) {
        foreach ($taxBreakdown as $taxTypeLabel => $taxAmount) {
          $print->text(self::threeline2("", $taxTypeLabel . " :", number_format($taxAmount, 0, ',', '.'), $charPerLine));
        }
      }

      $print->setEmphasis(true);
      $print->setTextSize(1, 2); // gedene
      $print->text(self::threeline2("", "Grand Total :", number_format($data_order->total_billing, 0, ',', '.'), $charPerLine));
      $print->setTextSize(1, 1); //normal e
      $print->setEmphasis(false);

      $print->text("\n");

      if ($isInclusiveTax !== false && !empty($taxBreakdown)) {
        $taxLabel = implode(' & ', array_keys($taxBreakdown));
        $print->text(self::threeline2("", "Price Inclusive of " . $taxLabel . " :", number_format($data_order->total_tax, 0, ',', '.'), $charPerLine));
      }
      $print->text(self::separator("-", $charPerLine));
      $print->setJustification(Printer::JUSTIFY_CENTER);

      $print->feed(2);
      $print->cut();
      $print->close();
    } catch (\Throwable $e) {
      Log::info($e);
    }
  }

  // static function PrintReport($ulid, $is_dayshift_detail = false)
  // {
  //   $datareport = null;
  //   if (!$is_dayshift_detail) {
  //     try {
  //       $datareport = DayShiftServices::GetReport($ulid);
  //     } catch (\Throwable $e) {
  //       throw $e;
  //     }
  //   } else {
  //     try {
  //       $datareport = DayShiftServices::GetReportByShiftDetail($ulid);
  //     } catch (\Throwable $e) {
  //       throw $e;
  //     }
  //   }
  //   $branch = BranchModel::first();
  //   $setting = SettingModel::first();
  //   $data_station = StationModel::where('id', $setting->default_station)->first();
  //   if (!$data_station) {
  //     return;
  //   }
  //   // Log::info($datareport);

  //   $konektor = new WindowsPrintConnector($data_station->printer_name);
  //   $print = new Printer($konektor);
  //   $charPerLine = $data_station->line_character;

  //   $print->setJustification(Printer::JUSTIFY_CENTER);
  //   $print->setEmphasis();
  //   $print->text("START\n");
  //   $print->setEmphasis(false);
  //   $print->text(self::separator("*", $charPerLine));
  //   $print->setEmphasis();
  //   $print->text("DAYSHIFT REPORT\n");
  //   $print->setEmphasis(false);
  //   $print->setJustification(Printer::JUSTIFY_LEFT);
  //   $print->text(self::separator("=", $charPerLine));


  //   $print->feed(1);
  //   $print->text("Branch Name      : " . $branch->branch_name . "\n");
  //   $print->text("Cashier          : " . "JUSE" . "\n");
  //   $print->text("Print Time       : " . now() . "\n");

  //   if ($datareport['dayshift']->start_time && $datareport['dayshift']->end_time) {
  //     $print->text("\nShift Number     : " .  $datareport['dayshift']->shift_queue . "\n");
  //     $print->text("Shift Start      : " . $datareport['dayshift']->start_time . "\n");
  //     $print->text("Shift End        : " . $datareport['dayshift']->end_time . "\n");
  //   } else {
  //     $print->text("\nShift Number     : END DAY\n");
  //     $print->text("Day Start        : " . $datareport['dayshift']->dayin_time . "\n");
  //     $print->text("Day End          : " . (($datareport['dayshift']->dayout_time != null) ? $datareport['dayshift']->dayout_time : 'RUNNING') . "\n");
  //   }


  //   $print->text(self::separator("-", $charPerLine));
  //   $print->feed(1);
  //   $print->setJustification(Printer::JUSTIFY_CENTER);
  //   // $print->setEmphasis();
  //   $print->text("SUMMARY REPORT\n");
  //   // $print->feed(1);
  //   // $print->setEmphasis(false);
  //   $print->setJustification(Printer::JUSTIFY_LEFT);
  //   $sales_recap = $datareport['sales_recapitulation'];

  //   $print->text(self::kirikakan("Pending             :", number_format($sales_recap[0]["amount"], 0, ',', '.'), $charPerLine));
  //   // $print->text(self::kirikakan("Sales               :", number_format($sales_total, 0, ',', '.'), $charPerLine));
  //   $print->text(self::kirikakan("Discount            :", 0, $charPerLine));
  //   $print->text(self::separator("-", $charPerLine));
  //   $print->setEmphasis(true);
  //   $print->text(self::kirikakan("Net Sales           :", number_format($sales_recap[1]["amount"], 0, ',', '.'), $charPerLine));
  //   $print->setEmphasis(false);
  //   $print->text(self::separator("-", $charPerLine));
  //   $print->text(self::kirikakan("Delivery Cost Total :", number_format($sales_recap[2]["amount"], 0, ',', '.'), $charPerLine));
  //   $print->text(self::kirikakan("OrderFee Total      :", number_format($sales_recap[3]["amount"], 0, ',', '.'), $charPerLine));
  //   $print->text(self::kirikakan("SC                  :", number_format($sales_recap[4]["amount"], 0, ',', '.'), $charPerLine));
  //   $print->text(self::kirikakan("PB1                 :", number_format($sales_recap[6]["amount"], 0, ',', '.'), $charPerLine));
  //   $print->text(self::kirikakan("VAT                 :", number_format($sales_recap[7]["amount"], 0, ',', '.'), $charPerLine));
  //   $print->text(self::kirikakan("Platform Fee        :", number_format($sales_recap[5]["amount"], 0, ',', '.'), $charPerLine));
  //   $print->text(self::kirikakan("Voucher Sales       :", 0, $charPerLine));
  //   $print->text(self::separator("-", $charPerLine));
  //   $print->setEmphasis(true);
  //   $print->text(self::kirikakan("Gross Sales         :", number_format($sales_recap[8]["amount"], 0, ',', '.'), $charPerLine));
  //   $print->setEmphasis(false);
  //   $print->text(self::separator("-", $charPerLine));
  //   $print->text(self::kirikakan("Number Of Pax       :", $sales_recap[9]["amount"], $charPerLine));
  //   $print->text(self::kirikakan("Avg NetSales /Pax   :", number_format($sales_recap[10]["amount"], 0, ',', '.'), $charPerLine));
  //   $print->text(self::kirikakan("Avg GrossSales /Pax :", number_format($sales_recap[11]["amount"], 0, ',', '.'), $charPerLine));
  //   $print->text(self::kirikakan("Number Of Bills     :", $sales_recap[12]["amount"], $charPerLine));
  //   $print->text(self::kirikakan("Avg NetSales /Bill  :", number_format($sales_recap[13]["amount"], 0, ',', '.'), $charPerLine));
  //   $print->text(self::kirikakan("Avg GrossSales /Bill:", number_format($sales_recap[14]["amount"], 0, ',', '.'), $charPerLine));
  //   $print->text(self::separator("-", $charPerLine));
  //   $print->feed(1);
  //   $print->setJustification(Printer::JUSTIFY_CENTER);
  //   // $print->setEmphasis();
  //   $print->text("PAYMENT METHOD REPORT\n");
  //   // $print->feed(1);
  //   // $print->setEmphasis(false);
  //   $print->setJustification(Printer::JUSTIFY_LEFT);
  //   $totalpayment = 0;
  //   foreach ($datareport['payment_recapitulation'] as $item) {
  //     $totalpayment += $item->payment_amount;
  //     $print->text(self::kirikakan($item->payment_method_name, number_format($item->payment_amount, 0, ',', '.'), $charPerLine));
  //   }
  //   $print->text(self::separator("-", $charPerLine));
  //   $print->setEmphasis(true);
  //   $print->text(self::kirikakan("Total Payment", number_format($totalpayment, 0, ',', '.'), $charPerLine));
  //   $print->setEmphasis(false);
  //   $print->text(self::separator("-", $charPerLine));

  //   // ///////////////////////////////////////////////
  //   $print->feed(1);
  //   $print->setJustification(Printer::JUSTIFY_CENTER);
  //   // $print->setEmphasis();
  //   $print->text("NET SALES BY MENU\n");
  //   // $print->feed(1);
  //   // $print->setEmphasis(false);
  //   $print->setJustification(Printer::JUSTIFY_LEFT);
  //   foreach ($datareport['sales_by_menu'] as $item) {
  //     $print->text(self::threeline($item->qty, $item->menu_name, number_format($item->sub_total, 0, ',', '.'), $charPerLine));
  //   }
  //   $print->text(self::separator("-", $charPerLine));

  //   // ////////////////////////////////////////////////////
  //   $print->feed(1);
  //   $print->setJustification(Printer::JUSTIFY_CENTER);
  //   // $print->setEmphasis();
  //   $print->text("NET SALES BY CATEGORY\n");
  //   // $print->feed(1);
  //   // $print->setEmphasis(false);
  //   $print->setJustification(Printer::JUSTIFY_LEFT);
  //   foreach ($datareport['sales_by_category'] as $item) {
  //     $print->text(self::threeline($item->qty, $item->category_name, number_format($item->sub_total, 0, ',', '.'), $charPerLine));
  //   }
  //   $print->text(self::separator("-", $charPerLine));

  //   // ////////////////////////////////////////////////////
  //   $print->feed(1);
  //   $print->setJustification(Printer::JUSTIFY_CENTER);
  //   // $print->setEmphasis();
  //   $print->text("NET SALES BY TABLE\n");
  //   // $print->feed(1);
  //   // $print->setEmphasis(false);
  //   $print->setJustification(Printer::JUSTIFY_LEFT);
  //   foreach ($datareport['sales_by_table'] as $item) {
  //     $print->text(self::threeline($item->total_order, $item->table_name, number_format($item->total_amount, 0, ',', '.'), $charPerLine));
  //   }
  //   $print->text(self::separator("-", $charPerLine));


  //   $print->feed(1);
  //   $print->text(self::separator("*", $charPerLine));
  //   $print->setJustification(Printer::JUSTIFY_CENTER);
  //   $print->setEmphasis();
  //   $print->text("END\n");


  //   $print->feed(2);
  //   $print->cut();
  //   $print->close();
  // }
  static function PrintEndDay($dayshift_ulid)
  {
    $datareport = null;

    try {
      $datareport = DayShiftServices::GetReportDayshiftorEndDay($dayshift_ulid);
    } catch (\Throwable $e) {
      throw $e;
    }

    // Cashier di struk End Day = yang beneran endday (tr_dayshift.dayout_user_id, udah
    // dipastikan keisi oleh DayShiftServices::EndDay()) -- data dayshift-nya udah ada di
    // $datareport, tinggal lookup nama usernya.
    $dayin_user_fullname = DB::table('mr_user')
      ->where('id', $datareport['dayshift']->dayout_user_id)
      ->value('fullname') ?? '';



    // Log::info($datareport["sales_recapitulation"]);
    // return;


    $branch = BranchModel::first();
    $setting = SettingModel::first();
    $data_station = StationModel::where('id', $setting->default_station)->first();
    if (!$data_station) {
      return;
    }
    // Log::info($datareport);

    $konektor = new WindowsPrintConnector($data_station->printer_name);
    $print = new Printer($konektor);
    $charPerLine = $data_station->line_character;

    $print->setJustification(Printer::JUSTIFY_CENTER);
    // $print->setEmphasis();
    // $print->text("START\n");
    // $print->setEmphasis(false);
    $print->text(self::separator("-", $charPerLine));
    $print->setEmphasis();
    $print->text("END OF DAY REPORT\n");
    $print->setEmphasis(false);
    $print->setJustification(Printer::JUSTIFY_LEFT);
    // $print->text(self::separator("=", $charPerLine));


    $print->feed(1);
    $print->text("Branch Name      : " . $branch->branch_name . "\n");
    $print->text("Cashier          : " . $dayin_user_fullname . "\n");
    $print->text("Print Time       : " . now() . "\n");

    // $jumlahsparate = count($datareport['dayshift_detail']);
    // if ($jumlahsparate == 0) {
    // $print->text("\nShift Number     : " .  1 . "\n");
    // } else {
    // $print->text("\nShift Number     : " .  $jumlahsparate + 1 . "\n");
    // }
    // if ($jumlahsparate >= 1) {
    Log::info($datareport['dayshift_detail']);
    // $print->text("Shift Start      : " . $datareport['dayshift_detail']->last()->shift_time . "\n");
    // } else {
    $print->feed(1);

    $print->text("Day Start        : " . $datareport['dayshift']->dayin_time . "\n");
    $print->text("Day End          : " . $datareport['dayshift']->dayout_time . "\n");
    // }
    // return;
    // if($datareport['dayshift']->dayout_time)
    // $print->text("Shift End        : "  . "\n");
    // } else {
    //   $print->text("\nShift Number     : END DAY\n");
    //   $print->text("Day Start        : " . $datareport['dayshift']->dayin_time . "\n");
    //   $print->text("Day End          : " . (($datareport['dayshift']->dayout_time != null) ? $datareport['dayshift']->dayout_time : 'RUNNING') . "\n");
    // }


    $print->text(self::separator("-", $charPerLine));
    $print->feed(1);
    $print->setJustification(Printer::JUSTIFY_CENTER);
    $print->setEmphasis();
    $print->text("SUMMARY REPORT\n");
    // $print->feed(1);
    $print->setEmphasis(false);
    $print->setJustification(Printer::JUSTIFY_LEFT);
    $sales_recap = $datareport['sales_recapitulation'];

    $print->text(self::kirikakan("On Hold             :", number_format($sales_recap[0]["amount"], 0, ',', '.'), $charPerLine));
    $print->text(self::kirikakan("Pending             :", number_format($sales_recap[1]["amount"], 0, ',', '.'), $charPerLine));
    $print->text(self::kirikakan("Sales               :", number_format($sales_recap[2]["amount"], 0, ',', '.'), $charPerLine));
    // $print->text(self::kirikakan("Sales               :", number_format($sales_total, 0, ',', '.'), $charPerLine));
    $print->text(self::kirikakan("Discount            :", number_format($sales_recap[18]["amount"], 0, ',', '.'), $charPerLine));
    $print->text(self::kirikakan("SC                  :", number_format($sales_recap[5]["amount"], 0, ',', '.'), $charPerLine));
    $print->text(self::kirikakan("PB1                 :", number_format($sales_recap[7]["amount"], 0, ',', '.'), $charPerLine));
    $print->text(self::kirikakan("VAT                 :", number_format($sales_recap[8]["amount"], 0, ',', '.'), $charPerLine));
    $print->text(self::separator("-", $charPerLine));
    $print->setEmphasis(true);
    $print->text(self::kirikakan("Net Sales           :", number_format($sales_recap[2]["amount"], 0, ',', '.'), $charPerLine));
    $print->text(self::kirikakan("Gross Sales         :", number_format($sales_recap[9]["amount"], 0, ',', '.'), $charPerLine));
    $print->setEmphasis(false);
    $print->text(self::separator("-", $charPerLine));

    // $print->text(self::kirikakan("Delivery Cost Total :", number_format($sales_recap[3]["amount"], 0, ',', '.'), $charPerLine));
    // $print->text(self::kirikakan("OrderFee Total      :", number_format($sales_recap[4]["amount"], 0, ',', '.'), $charPerLine));
    // $print->text(self::kirikakan("Platform Fee        :", number_format($sales_recap[8]["amount"], 0, ',', '.'), $charPerLine));
    // $print->text(self::kirikakan("Voucher Sales       :", 0, $charPerLine));
    // $print->text(self::separator("-", $charPerLine));
    // $print->setEmphasis(true);
    // $print->setEmphasis(false);

    // $print->text(self::separator("-", $charPerLine));
    // $print->text(self::kirikakan("Number Of Pax       :", $sales_recap[10]["amount"], $charPerLine));
    // $print->text(self::kirikakan("Avg NetSales /Pax   :", number_format($sales_recap[11]["amount"], 0, ',', '.'), $charPerLine));
    // $print->text(self::kirikakan("Avg GrossSales /Pax :", number_format($sales_recap[12]["amount"], 0, ',', '.'), $charPerLine));
    // $print->text(self::kirikakan("Number Of Bills     :", $sales_recap[13]["amount"], $charPerLine));
    // $print->text(self::kirikakan("Avg NetSales /Bill  :", number_format($sales_recap[14]["amount"], 0, ',', '.'), $charPerLine));
    // $print->text(self::kirikakan("Avg GrossSales /Bill:", number_format($sales_recap[15]["amount"], 0, ',', '.'), $charPerLine));
    // $print->text(self::separator("-", $charPerLine));
    // $print->feed(1);
    $print->setJustification(Printer::JUSTIFY_CENTER);
    $print->setEmphasis();
    $print->feed(1);
    $print->text("PAYMENT METHOD SUMMARY\n");
    $print->setEmphasis(false);
    $print->setJustification(Printer::JUSTIFY_LEFT);
    $totalpayment = 0;
    foreach ($datareport['payment_recapitulation'] as $item) {
      $totalpayment += $item->payment_amount;
      $print->text(self::kirikakan($item->payment_method_name, number_format($item->payment_amount, 0, ',', '.'), $charPerLine));
      $print->text(self::kirikakan(" - Qty", $item->qty, $charPerLine));
      $print->text(self::kirikakan(" - Total", number_format($item->payment_amount, 0, ',', '.'), $charPerLine));
    }
    $print->text(self::separator("-", $charPerLine));
    $print->setEmphasis(true);
    $print->text(self::kirikakan("Total Payment", number_format($totalpayment, 0, ',', '.'), $charPerLine));
    $print->setEmphasis(false);
    // $print->text(self::separator("-", $charPerLine));

    // ///////////////////////////////////////////////
    $print->feed(1);
    // $print->setJustification(Printer::JUSTIFY_CENTER);
    // // $print->setEmphasis();

    // $print->text("NET SALES BY MENU\n");
    // $print->setJustification(Printer::JUSTIFY_LEFT);
    // foreach ($datareport['sales_by_menu'] as $item) {
    //   $print->text(self::threeline($item->qty, $item->menu_name, number_format($item->sub_total, 0, ',', '.'), $charPerLine));
    // }
    // $print->text(self::separator("-", $charPerLine));

    // // ////////////////////////////////////////////////////
    // $print->feed(1);
    // $print->setJustification(Printer::JUSTIFY_CENTER);
    // $print->text("NET SALES BY CATEGORY\n");
    // $print->setJustification(Printer::JUSTIFY_LEFT);
    // foreach ($datareport['sales_by_category'] as $item) {
    //   $print->text(self::threeline($item->qty, $item->category_name, number_format($item->sub_total, 0, ',', '.'), $charPerLine));
    // }
    // $print->text(self::separator("-", $charPerLine));

    // // ////////////////////////////////////////////////////
    // $print->feed(1);
    // $print->setJustification(Printer::JUSTIFY_CENTER);
    // // $print->setEmphasis();
    // $print->text("NET SALES BY TABLE\n");
    // $print->setJustification(Printer::JUSTIFY_LEFT);
    // foreach ($datareport['sales_by_table'] as $item) {
    //   $print->text(self::threeline($item->total_order, $item->table_name, number_format($item->total_amount, 0, ',', '.'), $charPerLine));
    // }

    // $print->text(self::separator("-", $charPerLine));


    $print->feed(1);
    // $print->text(self::separator("*", $charPerLine));
    $print->setJustification(Printer::JUSTIFY_CENTER);
    $print->setEmphasis();
    // $print->text("END\n");


    $print->feed(2);
    $print->cut();
    $print->close();
  }

  // PrintReportDaysift sama kayak PrintEndDay, bedanya nampilin 3 cashier sekaligus:
  // yang start day, yang end day, dan yang lagi nge-print laporan ini sekarang.
  static function PrintReportDaysift($dayshift_ulid, $request = null)
  {
    $datareport = null;

    try {
      $datareport = DayShiftServices::GetReportDayshiftorEndDay($dayshift_ulid);
    } catch (\Throwable $e) {
      throw $e;
    }

    $cashier_start_fullname = DB::table('mr_user')
      ->where('id', $datareport['dayshift']->dayin_user_id)
      ->value('fullname') ?? '';

    $cashier_end_fullname = DB::table('mr_user')
      ->where('id', $datareport['dayshift']->dayout_user_id)
      ->value('fullname') ?? '';

    $cashier_print_fullname = self::getLoggedInUserFullname($request) ?? '';

    $branch = BranchModel::first();
    $setting = SettingModel::first();
    $data_station = StationModel::where('id', $setting->default_station)->first();
    if (!$data_station) {
      return;
    }

    $konektor = new WindowsPrintConnector($data_station->printer_name);
    $print = new Printer($konektor);
    $charPerLine = $data_station->line_character;

    $print->setJustification(Printer::JUSTIFY_CENTER);
    $print->text(self::separator("-", $charPerLine));
    $print->setEmphasis(true);
    $print->text("END OF DAY REPORT\n");
    $print->setEmphasis(false);
    $print->setJustification(Printer::JUSTIFY_LEFT);

    $print->feed(1);
    $print->text("Branch Name      : " . $branch->branch_name . "\n");
    $print->text("Cashier Start    : " . $cashier_start_fullname . "\n");
    $print->text("Cashier End      : " . $cashier_end_fullname . "\n");
    $print->text("Cashier Print    : " . $cashier_print_fullname . "\n");
    $print->text("Print Time       : " . now() . "\n");

    $print->feed(1);

    $print->text("Day Start        : " . $datareport['dayshift']->dayin_time . "\n");
    $print->text("Day End          : " . $datareport['dayshift']->dayout_time . "\n");

    $print->text(self::separator("-", $charPerLine));
    $print->feed(1);
    $print->setJustification(Printer::JUSTIFY_CENTER);
    $print->setEmphasis(true);
    $print->text("SUMMARY REPORT\n");
    $print->setEmphasis(false);
    $print->setJustification(Printer::JUSTIFY_LEFT);
    $sales_recap = $datareport['sales_recapitulation'];

    $print->text(self::kirikakan("On Hold             :", number_format($sales_recap[0]["amount"], 0, ',', '.'), $charPerLine));
    $print->text(self::kirikakan("Pending             :", number_format($sales_recap[1]["amount"], 0, ',', '.'), $charPerLine));
    $print->text(self::kirikakan("Sales               :", number_format($sales_recap[2]["amount"], 0, ',', '.'), $charPerLine));
    $print->text(self::kirikakan("Discount            :", number_format($sales_recap[18]["amount"], 0, ',', '.'), $charPerLine));
    $print->text(self::kirikakan("SC                  :", number_format($sales_recap[5]["amount"], 0, ',', '.'), $charPerLine));
    $print->text(self::kirikakan("PB1                 :", number_format($sales_recap[7]["amount"], 0, ',', '.'), $charPerLine));
    $print->text(self::kirikakan("VAT                 :", number_format($sales_recap[8]["amount"], 0, ',', '.'), $charPerLine));
    $print->text(self::separator("-", $charPerLine));
    $print->setEmphasis(true);
    $print->text(self::kirikakan("Net Sales           :", number_format($sales_recap[2]["amount"], 0, ',', '.'), $charPerLine));
    $print->text(self::kirikakan("Gross Sales         :", number_format($sales_recap[9]["amount"], 0, ',', '.'), $charPerLine));
    $print->setEmphasis(false);
    $print->text(self::separator("-", $charPerLine));

    $print->setJustification(Printer::JUSTIFY_CENTER);
    $print->feed(1);
    $print->setEmphasis(true);
    $print->text("PAYMENT METHOD SUMMARY\n");
    $print->setEmphasis(false);
    $print->setJustification(Printer::JUSTIFY_LEFT);
    $totalpayment = 0;
    foreach ($datareport['payment_recapitulation'] as $item) {
      $totalpayment += $item->payment_amount;
      $print->text(self::kirikakan($item->payment_method_name, number_format($item->payment_amount, 0, ',', '.'), $charPerLine));
      $print->text(self::kirikakan(" - Qty", $item->qty, $charPerLine));
      $print->text(self::kirikakan(" - Total", number_format($item->payment_amount, 0, ',', '.'), $charPerLine));
    }
    $print->text(self::separator("-", $charPerLine));
    $print->setEmphasis(true);
    $print->text(self::kirikakan("Total Payment", number_format($totalpayment, 0, ',', '.'), $charPerLine));
    $print->setEmphasis(false);
    // $print->text(self::separator("-", $charPerLine));

    $print->feed(1);
    $print->setJustification(Printer::JUSTIFY_CENTER);
    $print->setEmphasis();

    $print->feed(2);
    $print->cut();
    $print->close();
  }

  static function PrintCurrentShift($dayshift_ulid, $request = null)
  {
    $datareport = null;
    try {
      $datareport = DayShiftServices::GetReportCurrentShift($dayshift_ulid);
    } catch (\Throwable $e) {
      throw $e;
    }

    // Log::info($datareport["sales_recapitulation"]);
    // return;


    $branch = BranchModel::first();
    $setting = SettingModel::first();
    $data_station = StationModel::where('id', $setting->default_station)->first();
    if (!$data_station) {
      return;
    }
    // Log::info($datareport);

    $konektor = new WindowsPrintConnector($data_station->printer_name);
    $print = new Printer($konektor);
    $charPerLine = $data_station->line_character;

    $print->setJustification(Printer::JUSTIFY_CENTER);
    // $print->setEmphasis();
    // $print->text("START\n");
    // $print->setEmphasis(false);
    $print->text(self::separator("-", $charPerLine));
    $print->setEmphasis();
    $print->text("SHIFT REPORT\n");
    $print->setEmphasis(false);
    $print->setJustification(Printer::JUSTIFY_LEFT);
    // $print->text(self::separator("=", $charPerLine));

    // Cashier di struk shift report ini seharusnya yang lagi login/nge-print sekarang,
    // bukan histori ganti shift terakhir (yang bisa kosong kalau belum ada baris
    // tr_dayshift_detail, atau nunjuk ke orang lain dari yang minta cetak).
    $user_fullname = self::getLoggedInUserFullname($request);
    if (!$user_fullname) {
      // fallback ke user shift terakhir kalau token gak ada/invalid, biar tetap ada isinya
      try {
        $dayshift = DB::table('tr_dayshift_detail')
          ->join('mr_user', 'tr_dayshift_detail.shift_user_id', '=', 'mr_user.id')
          ->select('mr_user.fullname')->where('tr_dayshift_detail.dayshift_ulid', $dayshift_ulid)->latest('tr_dayshift_detail.shift_time')->first();

        $user_fullname = $dayshift->fullname ?? '';
      } catch (\Throwable $e) {
        $user_fullname = '';
      }
    }

    $print->feed(1);
    $print->text("Branch Name      : " . $branch->branch_name . "\n");
    $print->text("Cashier          : " . $user_fullname . "\n");
    $print->text("Print Time       : " . now() . "\n");

    $jumlahsparate = count($datareport['dayshift_detail']);
    if ($jumlahsparate == 0) {
      $print->text("\nShift Number     : " .  1 . "\n");
    } else {
      $print->text("\nShift Number     : " .  $jumlahsparate + 1 . "\n");
    }
    if ($jumlahsparate >= 1) {
      Log::info($datareport['dayshift_detail']);
      $print->text("Shift Start      : " . $datareport['dayshift_detail']->last()->shift_time . "\n");
    } else {
      $print->text("Shift Start      : " . $datareport['dayshift']->dayin_time . "\n");
    }
    // return;
    // if($datareport['dayshift']->dayout_time)
    $print->text("Shift End        : "  . "\n");
    // } else {
    //   $print->text("\nShift Number     : END DAY\n");
    //   $print->text("Day Start        : " . $datareport['dayshift']->dayin_time . "\n");
    //   $print->text("Day End          : " . (($datareport['dayshift']->dayout_time != null) ? $datareport['dayshift']->dayout_time : 'RUNNING') . "\n");
    // }


    $print->text(self::separator("-", $charPerLine));
    $print->feed(1);
    $print->setJustification(Printer::JUSTIFY_CENTER);
    $print->setEmphasis();
    $print->text("SUMMARY REPORT\n");
    // $print->feed(1);
    $print->setEmphasis(false);
    $print->setJustification(Printer::JUSTIFY_LEFT);
    $sales_recap = $datareport['sales_recapitulation'];

    $print->text(self::kirikakan("On Hold             :", number_format($sales_recap[0]["amount"], 0, ',', '.'), $charPerLine));
    $print->text(self::kirikakan("Pending             :", number_format($sales_recap[1]["amount"], 0, ',', '.'), $charPerLine));
    $print->text(self::kirikakan("Sales               :", number_format($sales_recap[2]["amount"], 0, ',', '.'), $charPerLine));
    // $print->text(self::kirikakan("Sales               :", number_format($sales_total, 0, ',', '.'), $charPerLine));
    $print->text(self::kirikakan("Discount            :", number_format($sales_recap[18]["amount"], 0, ',', '.'), $charPerLine));
    $print->text(self::kirikakan("SC                  :", number_format($sales_recap[5]["amount"], 0, ',', '.'), $charPerLine));
    $print->text(self::kirikakan("PB1                 :", number_format($sales_recap[7]["amount"], 0, ',', '.'), $charPerLine));
    $print->text(self::kirikakan("VAT                 :", number_format($sales_recap[8]["amount"], 0, ',', '.'), $charPerLine));
    $print->text(self::separator("-", $charPerLine));
    $print->setEmphasis(true);
    $print->text(self::kirikakan("Net Sales           :", number_format($sales_recap[2]["amount"], 0, ',', '.'), $charPerLine));
    $print->text(self::kirikakan("Gross Sales         :", number_format($sales_recap[9]["amount"], 0, ',', '.'), $charPerLine));
    $print->setEmphasis(false);
    $print->text(self::separator("-", $charPerLine));

    // $print->text(self::kirikakan("Delivery Cost Total :", number_format($sales_recap[3]["amount"], 0, ',', '.'), $charPerLine));
    // $print->text(self::kirikakan("OrderFee Total      :", number_format($sales_recap[4]["amount"], 0, ',', '.'), $charPerLine));
    // $print->text(self::kirikakan("Platform Fee        :", number_format($sales_recap[8]["amount"], 0, ',', '.'), $charPerLine));
    // $print->text(self::kirikakan("Voucher Sales       :", 0, $charPerLine));
    // $print->text(self::separator("-", $charPerLine));
    // $print->setEmphasis(true);
    // $print->setEmphasis(false);

    // $print->text(self::separator("-", $charPerLine));
    // $print->text(self::kirikakan("Number Of Pax       :", $sales_recap[10]["amount"], $charPerLine));
    // $print->text(self::kirikakan("Avg NetSales /Pax   :", number_format($sales_recap[11]["amount"], 0, ',', '.'), $charPerLine));
    // $print->text(self::kirikakan("Avg GrossSales /Pax :", number_format($sales_recap[12]["amount"], 0, ',', '.'), $charPerLine));
    // $print->text(self::kirikakan("Number Of Bills     :", $sales_recap[13]["amount"], $charPerLine));
    // $print->text(self::kirikakan("Avg NetSales /Bill  :", number_format($sales_recap[14]["amount"], 0, ',', '.'), $charPerLine));
    // $print->text(self::kirikakan("Avg GrossSales /Bill:", number_format($sales_recap[15]["amount"], 0, ',', '.'), $charPerLine));
    // $print->text(self::separator("-", $charPerLine));
    // $print->feed(1);
    $print->setJustification(Printer::JUSTIFY_CENTER);
    $print->setEmphasis();
    $print->feed(1);
    $print->text("PAYMENT METHOD SUMMARY\n");
    $print->setEmphasis(false);
    $print->setJustification(Printer::JUSTIFY_LEFT);
    $totalpayment = 0;
    foreach ($datareport['payment_recapitulation'] as $item) {
      $totalpayment += $item->payment_amount;
      $print->text(self::kirikakan($item->payment_method_name, number_format($item->payment_amount, 0, ',', '.'), $charPerLine));
      $print->text(self::kirikakan(" - Qty", $item->qty, $charPerLine));
      $print->text(self::kirikakan(" - Total", number_format($item->payment_amount, 0, ',', '.'), $charPerLine));
    }
    $print->text(self::separator("-", $charPerLine));
    $print->setEmphasis(true);
    $print->text(self::kirikakan("Total Payment", number_format($totalpayment, 0, ',', '.'), $charPerLine));
    $print->setEmphasis(false);
    // $print->text(self::separator("-", $charPerLine));

    // ///////////////////////////////////////////////
    $print->feed(1);
    // $print->setJustification(Printer::JUSTIFY_CENTER);
    // // $print->setEmphasis();

    // $print->text("NET SALES BY MENU\n");
    // $print->setJustification(Printer::JUSTIFY_LEFT);
    // foreach ($datareport['sales_by_menu'] as $item) {
    //   $print->text(self::threeline($item->qty, $item->menu_name, number_format($item->sub_total, 0, ',', '.'), $charPerLine));
    // }
    // $print->text(self::separator("-", $charPerLine));

    // // ////////////////////////////////////////////////////
    // $print->feed(1);
    // $print->setJustification(Printer::JUSTIFY_CENTER);
    // $print->text("NET SALES BY CATEGORY\n");
    // $print->setJustification(Printer::JUSTIFY_LEFT);
    // foreach ($datareport['sales_by_category'] as $item) {
    //   $print->text(self::threeline($item->qty, $item->category_name, number_format($item->sub_total, 0, ',', '.'), $charPerLine));
    // }
    // $print->text(self::separator("-", $charPerLine));

    // // ////////////////////////////////////////////////////
    // $print->feed(1);
    // $print->setJustification(Printer::JUSTIFY_CENTER);
    // // $print->setEmphasis();
    // $print->text("NET SALES BY TABLE\n");
    // $print->setJustification(Printer::JUSTIFY_LEFT);
    // foreach ($datareport['sales_by_table'] as $item) {
    //   $print->text(self::threeline($item->total_order, $item->table_name, number_format($item->total_amount, 0, ',', '.'), $charPerLine));
    // }

    // $print->text(self::separator("-", $charPerLine));


    $print->feed(1);
    // $print->text(self::separator("*", $charPerLine));
    $print->setJustification(Printer::JUSTIFY_CENTER);
    $print->setEmphasis();
    // $print->text("END\n");


    $print->feed(2);
    $print->cut();
    $print->close();
  }

  static function PrintPerShiftv2($dayshift_detail_ulid)
  {
    $datareport = null;
    $shiftcurent = DayShiftDetailModel::where("ulid", $dayshift_detail_ulid)->first();

    try {
      $datareport = DayShiftServices::GetReportPerShift($dayshift_detail_ulid);
    } catch (\Throwable $e) {
      throw $e;
    }

    // Log::info($datareport["sales_recapitulation"]);
    // return;


    $branch = BranchModel::first();
    $setting = SettingModel::first();
    $data_station = StationModel::where('id', $setting->default_station)->first();
    if (!$data_station) {
      return;
    }
    // Log::info($datareport);

    $konektor = new WindowsPrintConnector($data_station->printer_name);
    $print = new Printer($konektor);
    $charPerLine = $data_station->line_character;

    $print->setJustification(Printer::JUSTIFY_CENTER);
    // $print->setEmphasis();
    // $print->text("START\n");
    // $print->setEmphasis(false);
    $print->text(self::separator("-", $charPerLine));
    $print->setEmphasis();
    $print->text("SHIFT REPORT\n");
    $print->setEmphasis(false);
    $print->setJustification(Printer::JUSTIFY_LEFT);
    // $print->text(self::separator("=", $charPerLine));

    $user_fullname = '';
    try {
      $dayshift = DB::table('tr_dayshift_detail')
        ->join('mr_user', 'tr_dayshift_detail.shift_user_id', '=', 'mr_user.id')
        ->select('mr_user.fullname')->where('tr_dayshift_detail.ulid', $dayshift_detail_ulid)->first();

      $user_fullname = $dayshift->fullname ?? '';
    } catch (\Throwable $e) {
      throw $e;
    }

    $print->feed(1);
    $print->text("Branch Name      : " . $branch->branch_name . "\n");
    $print->text("Cashier          : " . $user_fullname . "\n");
    $print->text("Print Time       : " . now() . "\n");

    // $jumlahsparate = count($datareport['dayshift_detail']);
    $nomer = 1;
    if ($shiftcurent) {
      foreach ($datareport['dayshift_detail'] as $kecilkecil) {
        if ($kecilkecil->ulid == $shiftcurent->ulid) {
          break;
        }
        $nomer = $nomer + 1;
      }
    }

    // if ($jumlahsparate == 0) {
    //   $print->text("\nShift Number     : " .   . "\n");
    // } else {
    $print->text("\nShift Number     : " .  $nomer . "\n");
    // }
    // if ($shiftcurent) {
    //   if(count( $datareport['dayshift_detail']))
    //   Log::info($datareport['dayshift_detail']);
    //   $print->text("Shift Start      : " . $datareport['dayshift_detail']->last()->shift_time . "\n");
    // } else {
    //   $print->text("Shift Start      : " . $datareport['dayshift']->dayin_time . "\n");
    // }
    $jumlahsparate = count($datareport['dayshift_detail']);

    if ($nomer == 1) {
      // Log::info($datareport['dayshift_detail']);
      $print->text("Shift Start      : " . $datareport['dayshift']->dayin_time . "\n");
    } else {
      $print->text("Shift Start      : " . $datareport['dayshift_detail'][$nomer - 2]->shift_time . "\n");
    }

    // return;
    // if($datareport['dayshift']->dayout_time)
    $print->text("Shift End        : " . $shiftcurent->shift_time . "\n");
    // } else {
    //   $print->text("\nShift Number     : END DAY\n");
    //   $print->text("Day Start        : " . $datareport['dayshift']->dayin_time . "\n");
    //   $print->text("Day End          : " . (($datareport['dayshift']->dayout_time != null) ? $datareport['dayshift']->dayout_time : 'RUNNING') . "\n");
    // }


    $print->text(self::separator("-", $charPerLine));
    $print->feed(1);
    $print->setJustification(Printer::JUSTIFY_CENTER);
    $print->setEmphasis();
    $print->text("SUMMARY REPORT\n");
    // $print->feed(1);
    $print->setEmphasis(false);
    $print->setJustification(Printer::JUSTIFY_LEFT);
    $sales_recap = $datareport['sales_recapitulation'];

    $print->text(self::kirikakan("On Hold             :", number_format($sales_recap[0]["amount"], 0, ',', '.'), $charPerLine));
    $print->text(self::kirikakan("Pending             :", number_format($sales_recap[1]["amount"], 0, ',', '.'), $charPerLine));
    $print->text(self::kirikakan("Sales               :", number_format($sales_recap[2]["amount"], 0, ',', '.'), $charPerLine));
    // $print->text(self::kirikakan("Sales               :", number_format($sales_total, 0, ',', '.'), $charPerLine));
    $print->text(self::kirikakan("Discount            :", number_format($sales_recap[18]["amount"], 0, ',', '.'), $charPerLine));
    $print->text(self::kirikakan("SC                  :", number_format($sales_recap[5]["amount"], 0, ',', '.'), $charPerLine));
    $print->text(self::kirikakan("PB1                 :", number_format($sales_recap[7]["amount"], 0, ',', '.'), $charPerLine));
    $print->text(self::kirikakan("VAT                 :", number_format($sales_recap[8]["amount"], 0, ',', '.'), $charPerLine));
    $print->text(self::separator("-", $charPerLine));
    $print->setEmphasis(true);
    $print->text(self::kirikakan("Net Sales           :", number_format($sales_recap[2]["amount"], 0, ',', '.'), $charPerLine));
    $print->text(self::kirikakan("Gross Sales         :", number_format($sales_recap[9]["amount"], 0, ',', '.'), $charPerLine));
    $print->setEmphasis(false);
    $print->text(self::separator("-", $charPerLine));

    // $print->text(self::kirikakan("Delivery Cost Total :", number_format($sales_recap[3]["amount"], 0, ',', '.'), $charPerLine));
    // $print->text(self::kirikakan("OrderFee Total      :", number_format($sales_recap[4]["amount"], 0, ',', '.'), $charPerLine));
    // $print->text(self::kirikakan("Platform Fee        :", number_format($sales_recap[8]["amount"], 0, ',', '.'), $charPerLine));
    // $print->text(self::kirikakan("Voucher Sales       :", 0, $charPerLine));
    // $print->text(self::separator("-", $charPerLine));
    // $print->setEmphasis(true);
    // $print->setEmphasis(false);

    // $print->text(self::separator("-", $charPerLine));
    // $print->text(self::kirikakan("Number Of Pax       :", $sales_recap[10]["amount"], $charPerLine));
    // $print->text(self::kirikakan("Avg NetSales /Pax   :", number_format($sales_recap[11]["amount"], 0, ',', '.'), $charPerLine));
    // $print->text(self::kirikakan("Avg GrossSales /Pax :", number_format($sales_recap[12]["amount"], 0, ',', '.'), $charPerLine));
    // $print->text(self::kirikakan("Number Of Bills     :", $sales_recap[13]["amount"], $charPerLine));
    // $print->text(self::kirikakan("Avg NetSales /Bill  :", number_format($sales_recap[14]["amount"], 0, ',', '.'), $charPerLine));
    // $print->text(self::kirikakan("Avg GrossSales /Bill:", number_format($sales_recap[15]["amount"], 0, ',', '.'), $charPerLine));
    // $print->text(self::separator("-", $charPerLine));
    // $print->feed(1);
    $print->setJustification(Printer::JUSTIFY_CENTER);
    $print->setEmphasis();
    $print->feed(1);
    $print->text("PAYMENT METHOD SUMMARY\n");
    $print->setEmphasis(false);
    $print->setJustification(Printer::JUSTIFY_LEFT);
    $totalpayment = 0;
    foreach ($datareport['payment_recapitulation'] as $item) {
      $totalpayment += $item->payment_amount;
      $print->text(self::kirikakan($item->payment_method_name, number_format($item->payment_amount, 0, ',', '.'), $charPerLine));
      $print->text(self::kirikakan(" - Qty", $item->qty, $charPerLine));
      $print->text(self::kirikakan(" - Total", number_format($item->payment_amount, 0, ',', '.'), $charPerLine));
    }
    $print->text(self::separator("-", $charPerLine));
    $print->setEmphasis(true);
    $print->text(self::kirikakan("Total Payment", number_format($totalpayment, 0, ',', '.'), $charPerLine));
    $print->setEmphasis(false);
    // $print->text(self::separator("-", $charPerLine));

    // ///////////////////////////////////////////////
    $print->feed(1);
    // $print->setJustification(Printer::JUSTIFY_CENTER);
    // // $print->setEmphasis();

    // $print->text("NET SALES BY MENU\n");
    // $print->setJustification(Printer::JUSTIFY_LEFT);
    // foreach ($datareport['sales_by_menu'] as $item) {
    //   $print->text(self::threeline($item->qty, $item->menu_name, number_format($item->sub_total, 0, ',', '.'), $charPerLine));
    // }
    // $print->text(self::separator("-", $charPerLine));

    // // ////////////////////////////////////////////////////
    // $print->feed(1);
    // $print->setJustification(Printer::JUSTIFY_CENTER);
    // $print->text("NET SALES BY CATEGORY\n");
    // $print->setJustification(Printer::JUSTIFY_LEFT);
    // foreach ($datareport['sales_by_category'] as $item) {
    //   $print->text(self::threeline($item->qty, $item->category_name, number_format($item->sub_total, 0, ',', '.'), $charPerLine));
    // }
    // $print->text(self::separator("-", $charPerLine));

    // // ////////////////////////////////////////////////////
    // $print->feed(1);
    // $print->setJustification(Printer::JUSTIFY_CENTER);
    // // $print->setEmphasis();
    // $print->text("NET SALES BY TABLE\n");
    // $print->setJustification(Printer::JUSTIFY_LEFT);
    // foreach ($datareport['sales_by_table'] as $item) {
    //   $print->text(self::threeline($item->total_order, $item->table_name, number_format($item->total_amount, 0, ',', '.'), $charPerLine));
    // }

    // $print->text(self::separator("-", $charPerLine));


    $print->feed(1);
    // $print->text(self::separator("*", $charPerLine));
    $print->setJustification(Printer::JUSTIFY_CENTER);
    $print->setEmphasis();
    // $print->text("END\n");


    $print->feed(2);
    $print->cut();
    $print->close();
  }

  // static function PrintPerShiftv2ase($dayshift_detail_ulid)
  // {
  //   $datareport = null;

  //   try {
  //     $datareport = DayShiftServices::GetReportPerShift($dayshift_detail_ulid);
  //   } catch (\Throwable $e) {
  //     throw $e;
  //   }

  //   // Log::info($datareport["sales_recapitulation"][2]);
  //   // return;


  //   $branch = BranchModel::first();
  //   $setting = SettingModel::first();
  //   $data_station = StationModel::where('id', $setting->default_station)->first();
  //   if (!$data_station) {
  //     return;
  //   }
  //   // Log::info($datareport);

  //   $konektor = new WindowsPrintConnector($data_station->printer_name);
  //   $print = new Printer($konektor);
  //   $charPerLine = $data_station->line_character;

  //   $print->setJustification(Printer::JUSTIFY_CENTER);
  //   $print->setEmphasis();
  //   $print->text("START\n");
  //   $print->setEmphasis(false);
  //   $print->text(self::separator("*", $charPerLine));
  //   $print->setEmphasis();
  //   $print->text("DAYSHIFT REPORT\n");
  //   $print->setEmphasis(false);
  //   $print->setJustification(Printer::JUSTIFY_LEFT);
  //   $print->text(self::separator("=", $charPerLine));


  //   $print->feed(1);
  //   $print->text("Branch Name      : " . $branch->branch_name . "\n");
  //   $print->text("Cashier          : " . "JUSE" . "\n");
  //   $print->text("Print Time       : " . now() . "\n");

  //   if ($datareport['dayshift']->start_time && $datareport['dayshift']->end_time) {
  //     $print->text("\nShift Number     : " .  $datareport['dayshift']->shift_queue . "\n");
  //     $print->text("Shift Start      : " . $datareport['dayshift']->start_time . "\n");
  //     $print->text("Shift End        : " . $datareport['dayshift']->end_time . "\n");
  //   } else {
  //     $print->text("\nShift Number     : END DAY\n");
  //     $print->text("Day Start        : " . $datareport['dayshift']->dayin_time . "\n");
  //     $print->text("Day End          : " . (($datareport['dayshift']->dayout_time != null) ? $datareport['dayshift']->dayout_time : 'RUNNING') . "\n");
  //   }


  //   $print->text(self::separator("-", $charPerLine));
  //   $print->feed(1);
  //   $print->setJustification(Printer::JUSTIFY_CENTER);
  //   // $print->setEmphasis();
  //   $print->text("SUMMARY REPORT\n");
  //   // $print->feed(1);
  //   // $print->setEmphasis(false);
  //   $print->setJustification(Printer::JUSTIFY_LEFT);
  //   $sales_recap = $datareport['sales_recapitulation'];

  //   $print->text(self::kirikakan("Pending             :", number_format($sales_recap[1]["amount"], 0, ',', '.'), $charPerLine));
  //   // $print->text(self::kirikakan("Sales               :", number_format($sales_total, 0, ',', '.'), $charPerLine));
  //   $print->text(self::kirikakan("Discount            :", 0, $charPerLine));
  //   $print->text(self::separator("-", $charPerLine));
  //   $print->setEmphasis(true);
  //   $print->text(self::kirikakan("Net Sales           :", number_format($sales_recap[2]["amount"], 0, ',', '.'), $charPerLine));
  //   $print->setEmphasis(false);
  //   $print->text(self::separator("-", $charPerLine));
  //   $print->text(self::kirikakan("Delivery Cost Total :", number_format($sales_recap[3]["amount"], 0, ',', '.'), $charPerLine));
  //   $print->text(self::kirikakan("OrderFee Total      :", number_format($sales_recap[4]["amount"], 0, ',', '.'), $charPerLine));
  //   $print->text(self::kirikakan("SC                  :", number_format($sales_recap[5]["amount"], 0, ',', '.'), $charPerLine));
  //   $print->text(self::kirikakan("PB1                 :", number_format($sales_recap[6]["amount"], 0, ',', '.'), $charPerLine));
  //   $print->text(self::kirikakan("VAT                 :", number_format($sales_recap[7]["amount"], 0, ',', '.'), $charPerLine));
  //   $print->text(self::kirikakan("Platform Fee        :", number_format($sales_recap[8]["amount"], 0, ',', '.'), $charPerLine));
  //   $print->text(self::kirikakan("Voucher Sales       :", 0, $charPerLine));
  //   $print->text(self::separator("-", $charPerLine));
  //   $print->setEmphasis(true);
  //   $print->text(self::kirikakan("Gross Sales         :", number_format($sales_recap[9]["amount"], 0, ',', '.'), $charPerLine));
  //   $print->setEmphasis(false);
  //   $print->text(self::separator("-", $charPerLine));
  //   $print->text(self::kirikakan("Number Of Pax       :", $sales_recap[10]["amount"], $charPerLine));
  //   $print->text(self::kirikakan("Avg NetSales /Pax   :", number_format($sales_recap[11]["amount"], 0, ',', '.'), $charPerLine));
  //   $print->text(self::kirikakan("Avg GrossSales /Pax :", number_format($sales_recap[12]["amount"], 0, ',', '.'), $charPerLine));
  //   $print->text(self::kirikakan("Number Of Bills     :", $sales_recap[13]["amount"], $charPerLine));
  //   $print->text(self::kirikakan("Avg NetSales /Bill  :", number_format($sales_recap[14]["amount"], 0, ',', '.'), $charPerLine));
  //   $print->text(self::kirikakan("Avg GrossSales /Bill:", number_format($sales_recap[15]["amount"], 0, ',', '.'), $charPerLine));
  //   $print->text(self::separator("-", $charPerLine));
  //   $print->feed(1);
  //   $print->setJustification(Printer::JUSTIFY_CENTER);
  //   // $print->setEmphasis();
  //   $print->text("PAYMENT METHOD REPORT\n");
  //   // $print->feed(1);
  //   // $print->setEmphasis(false);
  //   $print->setJustification(Printer::JUSTIFY_LEFT);
  //   $totalpayment = 0;
  //   foreach ($datareport['payment_recapitulation'] as $item) {
  //     $totalpayment += $item->payment_amount;
  //     $print->text(self::kirikakan($item->payment_method_name, number_format($item->payment_amount, 0, ',', '.'), $charPerLine));
  //   }
  //   $print->text(self::separator("-", $charPerLine));
  //   $print->setEmphasis(true);
  //   $print->text(self::kirikakan("Total Payment", number_format($totalpayment, 0, ',', '.'), $charPerLine));
  //   $print->setEmphasis(false);
  //   $print->text(self::separator("-", $charPerLine));

  //   // ///////////////////////////////////////////////
  //   $print->feed(1);
  //   $print->setJustification(Printer::JUSTIFY_CENTER);
  //   // $print->setEmphasis();
  //   $print->text("NET SALES BY MENU\n");
  //   // $print->feed(1);
  //   // $print->setEmphasis(false);
  //   $print->setJustification(Printer::JUSTIFY_LEFT);
  //   foreach ($datareport['sales_by_menu'] as $item) {
  //     $print->text(self::threeline($item->qty, $item->menu_name, number_format($item->sub_total, 0, ',', '.'), $charPerLine));
  //   }
  //   $print->text(self::separator("-", $charPerLine));

  //   // ////////////////////////////////////////////////////
  //   $print->feed(1);
  //   $print->setJustification(Printer::JUSTIFY_CENTER);
  //   // $print->setEmphasis();
  //   $print->text("NET SALES BY CATEGORY\n");
  //   // $print->feed(1);
  //   // $print->setEmphasis(false);
  //   $print->setJustification(Printer::JUSTIFY_LEFT);
  //   foreach ($datareport['sales_by_category'] as $item) {
  //     $print->text(self::threeline($item->qty, $item->category_name, number_format($item->sub_total, 0, ',', '.'), $charPerLine));
  //   }
  //   $print->text(self::separator("-", $charPerLine));

  //   // ////////////////////////////////////////////////////
  //   $print->feed(1);
  //   $print->setJustification(Printer::JUSTIFY_CENTER);
  //   // $print->setEmphasis();
  //   $print->text("NET SALES BY TABLE\n");
  //   // $print->feed(1);
  //   // $print->setEmphasis(false);
  //   $print->setJustification(Printer::JUSTIFY_LEFT);
  //   foreach ($datareport['sales_by_table'] as $item) {
  //     $print->text(self::threeline($item->total_order, $item->table_name, number_format($item->total_amount, 0, ',', '.'), $charPerLine));
  //   }
  //   $print->text(self::separator("-", $charPerLine));


  //   $print->feed(1);
  //   $print->text(self::separator("*", $charPerLine));
  //   $print->setJustification(Printer::JUSTIFY_CENTER);
  //   $print->setEmphasis();
  //   $print->text("END\n");


  //   $print->feed(2);
  //   $print->cut();
  //   $print->close();
  // }

  public static function PrintTest(int $station_id): string
  {
    $data_station = StationModel::where('id', $station_id)->first();
    if (!$data_station) {
      return 'Station tidak ditemukan';
    }

    try {

      if ($data_station->printer_type == 1) {
        $konektor = new WindowsPrintConnector($data_station->printer_name);
        $print = new Printer($konektor);
        $charPerLine = $data_station->line_character ?: 40;

        $print->setJustification(Printer::JUSTIFY_CENTER);
        $print->setEmphasis(true);
        $print->setTextSize(1, 2);
        $print->text("TEST PRINT\n");
        $print->setTextSize(1, 1);
        $print->setEmphasis(false);
        $print->text(self::separator("=", $charPerLine));
        $print->setJustification(Printer::JUSTIFY_LEFT);
        $print->text("Station   : " . $data_station->name . "\n");
        $print->text("Printer   : " . $data_station->printer_name . "\n");
        $print->text("Char/Line : " . $charPerLine . "\n");
        $print->text("Time      : " . now() . "\n");
        $print->text(self::separator("=", $charPerLine));
        $print->setJustification(Printer::JUSTIFY_CENTER);
        $print->text("Printer OK\n");
        $print->feed(2);
        $print->cut();
        $print->close();
      } else if ($data_station->printer_type == 2) {

        $ngeprintasek = new GeneralLabel;
        $ngeprintasek->setMargin(0, 2);
        $ngeprintasek->setNamePrinter($data_station->printer_name);
        $ngeprintasek->setText("Print OK!");
        $ngeprintasek->sikat();
      } else {
        return 'ERR';
      }
      return 'OK';
    } catch (\Throwable $e) {
      Log::warning('PrintTest error: ' . $e->getMessage());
      return $e->getMessage();
    }
  }
}
