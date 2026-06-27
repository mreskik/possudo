<?php

namespace App\Services;

use App\Models\BranchModel;
use App\Models\MasterTableSectionPrintCategorySettingModel;
use App\Models\MasterVisitPurposeModel;
use App\Models\SettingModel;
use App\Models\StationModel;
use App\Models\TableSectionModel;
use App\Models\TrOrderModel;
use App\Models\TrPaymentModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use \Mike42\Escpos\PrintConnectors\WindowsPrintConnector;
use \Mike42\Escpos\Printer;
use \Mike42\Escpos\EscposImage;

class PrintServices
{
  public static function resizeGambar($source = "", $newWidth = 150)
  {
    //tes
    if ($source == "" || !file_exists($source)) {
      return "Gambar tidak ditemukan";
    }

    list($width, $height, $type) = getimagesize($source);

    // load sesuai format
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

    // hitung height proporsional
    $newHeight = ($height / $width) * $newWidth;

    // buat canvas baru (background putih)
    $resized = imagecreatetruecolor($newWidth, $newHeight);
    $white = imagecolorallocate($resized, 255, 255, 255);
    imagefill($resized, 0, 0, $white);

    // resize + copy
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

    // generate nama file baru (biar fleksibel)
    imagepng($resized, "logo_resize.png");

    // bersihin memory
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

      $data_order = TrOrderModel::where('order_number', $order_number)->first();
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

      // if (count($data_order_detail) > 0) {
      //   return;
      // }


      $data_visitpurpose = MasterVisitPurposeModel::where('id', $data_order->visit_purpose_id)->first();

      $konektor = new WindowsPrintConnector($data_station->printer_name);
      $print = new Printer($konektor);
      /////////////////////
      // self::resizeGambar(public_path("img/logo1.png"), 165);

      $imageLogo = EscposImage::load(public_path("logo_resize.png"), false);
      $order_number = $data_order->order_number;
      $charPerLine = $data_station->line_character;
      $table_section_name = $table_section->name;
      $visitpurpose_name = $data_visitpurpose->name;
      $last_batch = $data_order->total_batch;
      $pax = $data_order->pax;
      $order_in  = $data_order->order_in;
      $order_queue = $data_order->order_queue;
      $info  = $data_order->order_name;
      $cashier  = "JUSE";

      $print->feed(1);
      $print->setJustification(Printer::JUSTIFY_CENTER);
      $print->setEmphasis(true);
      $print->setTextSize(1, 2);
      $print->text("Table Checker\n");
      $print->setTextSize(1, 1);
      $print->setEmphasis(false);
      $print->text(self::separator("=", $charPerLine));
      $print->setEmphasis(true);
      $print->setTextSize(1, 2);
      $print->text("Queue : " . $order_queue . "\n");
      $print->setTextSize(1, 1);
      $print->setEmphasis(false);
      $print->text(self::separator("=", $charPerLine));
      $print->setEmphasis(true);
      $print->setTextSize(1, 2);
      $print->text("Table : " . $table_section_name . "\n");
      $print->setTextSize(1, 1);
      $print->setEmphasis(false);
      $print->text(self::separator("=", $charPerLine));

      $print->setJustification(Printer::JUSTIFY_LEFT);
      $print->text("Order       : " . $order_number . "\n");
      $print->text("Date        : " . $order_in . "\n");
      $print->text("Purpose     : " . $visitpurpose_name . "\n");
      $print->text("Waiter      : " . $cashier . "\n");
      $print->text("Sender      : " . $cashier . "\n");
      // $print->text("Info        : " . $info . "\n");
      $print->text("Batch       : " . $last_batch . "\n");
      $print->text("Customer    : " . $info . "\n");

      $print->text(self::separator("=", $charPerLine));
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


      $data_order = TrOrderModel::where('order_number', $order_number)->first();

      $data_order_detail = DB::select("
      SELECT
      trod.*,
      mi.name as menu_name
      FROM tr_order_detail trod
      JOIN mr_item_conv mic on mic.id = trod.menu_id
      JOIN mr_item mi on mi.id = mic.item_id
      WHERE trod.order_number = ? and trod.done_print = false
      ", [$order_number]);

      // if (count($data_order_detail) > 0) {
      //   return;
      // }

      $data_visitpurpose = MasterVisitPurposeModel::where('id', $data_order->visit_purpose_id)->first();

      $konektor = new WindowsPrintConnector($data_station->printer_name);
      $print = new Printer($konektor);
      /////////////////////
      // self::resizeGambar(public_path("img/logo1.png"), 165);

      $imageLogo = EscposImage::load(public_path("logo_resize.png"), false);

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
      $cashier  = "JUSE";

      //////////////////// end inisialisasi

      $print->feed(1);
      $print->setJustification(Printer::JUSTIFY_LEFT);
      $print->setEmphasis(true);
      $print->setTextSize(2, 2);
      $print->text($order_number . "\n");
      $print->text($order_in . "\n");
      $print->text("QUEUE : " . $order_queue . "\n");
      $print->text($table_section_name . "\n");
      $print->feed(1);
      $print->text($visitpurpose_name . "\n");
      $print->feed(1);
      $print->setEmphasis(false);
      $print->setTextSize(1, 1);

      $print->setJustification(Printer::JUSTIFY_LEFT);
      $print->text("Info        : " . $info . "\n");
      $print->text("Waiter      : " . $cashier . "\n");
      $print->text("Sender      : " . $cashier . "\n");
      $print->text("Batch       : " . $last_batch . "\n");
      $print->text("Pax         : " . $pax . "\n");

      $print->text(self::separator("=", $charPerLine));
      $print->setJustification(Printer::JUSTIFY_CENTER);
      $print->setEmphasis(true);
      $print->setTextSize(1, 2);
      $print->text("MAIN CHECKER" . "\n");
      $print->setTextSize(1, 1);
      $print->setEmphasis(false);
      $print->text(self::separator("=", $charPerLine));
      $print->setJustification(Printer::JUSTIFY_LEFT);
      $print->setTextSize(1, 2);
      foreach ($data_order_detail as $itemmenu) {

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
      $print->setTextSize(1, 2);
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


      $data_order = TrOrderModel::where('order_number', $order_number)->first();
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


      foreach ($data_order_detail as $order_item) {
        // $package = [];
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

                WHERE trod.tr_order_detail_ulid = ?", [$order_item->ulid]);
        $order_item->package_detail = $listpackagedetail;
      }




      foreach ($data_order_detail as $item) {

        if (count($item->package_detail) == 0) {

          //ITEM BIASA
          $total_qty = $item->qty;
          foreach ($datasubcategorystation as $stationnary) {
            if ($item->subcategory_id == $stationnary->sub_category_id) {
              $data_station = StationModel::where('id', $stationnary->station_id)->first();
              if ($data_station) {
                for ($i = 1; $i <= $total_qty; $i++) {
                  $ngeprint = new GeneralLabel;

                  $ngeprint->setNamePrinter($data_station->printer_name);
                  $ngeprint->setText($data_order->order_in);
                  $ngeprint->setText($data_order->order_queue . " | " . $data_order->order_name . " | $i/$total_qty");
                  $ngeprint->setText($data_visitpurpose->name);
                  $ngeprint->setText($item->name);
                  if ($item->notes && $item->notes != '') {
                    $ngeprint->setText(' notes : ' . $item->notes);
                  }
                  $ngeprint->sikat();
                }
              }
            }
          }
        } else {


          //VERSI PACKAGE
          $total_qty = $item->qty;
          foreach ($datasubcategorystation as $stationnary) {
            if ($item->subcategory_id == $stationnary->sub_category_id) {
              $data_station = StationModel::where('id', $stationnary->station_id)->first();

              if ($data_station) {
                for ($i = 1; $i <= $total_qty; $i++) {
                  $ngeprint = new GeneralLabel;
                  $ngeprint->setMargin(1, 5);

                  $ngeprint->setNamePrinter($data_station->name);
                  $ngeprint->setText($data_order->order_in);
                  $ngeprint->setText($data_order->order_queue . " | " . $data_order->order_name . " | $i/$total_qty");
                  $ngeprint->setText($data_visitpurpose->name);
                  $ngeprint->setText($item->name);

                  //itempackage
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

                  foreach ($listpackagedetail as $itempackage) {

                    foreach ($datasubcategorystation as $stationnaryB) {

                      if ($itempackage->subcategory_id == $stationnaryB->sub_category_id) {

                        $data_station2 = StationModel::where('id', $stationnaryB->station_id)->first();
                        if ($data_station2) {
                          $ngeprint->setText(" " . $itempackage->qty . "x " . $itempackage->name);


                          if ($itempackage->notes && $itempackage->notes != '') {
                            $ngeprint->setText('   * ' . $itempackage->notes);
                          }
                        }
                      }
                    }
                  }
                  if ($item->notes && $item->notes != '') {
                    $ngeprint->setText(' notes : ' . $item->notes);
                  }
                  $ngeprint->sikat();
                }
              }
            }
          }
        }
      }
    } catch (\Throwable $e) {
      Log::info($e);
    }
  }


  public static function PrintPayment(string $order_number)
  {
    try {

      $data_order = TrOrderModel::where('order_number', $order_number)->first();

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
      mi.name as menu_name
      FROM tr_order_detail trod
      JOIN mr_item_conv mic on mic.id = trod.menu_id
      JOIN mr_item mi on mi.id = mic.item_id
      WHERE trod.order_number = ? 
      ", [$order_number]);


      $data_visitpurpose = MasterVisitPurposeModel::where('id', $data_order->visit_purpose_id)->first();
      $konektor = new WindowsPrintConnector($data_station->printer_name);
      $print = new Printer($konektor);
      $imageLogo = EscposImage::load(public_path("logo_resize.png"), false);
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
      $cashier  = "JUSE";

      $print->setJustification(Printer::JUSTIFY_CENTER);

      $print->bitImage($imageLogo);
      $print->text("\n");

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
      $print->text("Date        : " . $data_order->order_date . "\n");
      $print->text("Time In     : " . $data_order->order_in . "\n");
      $print->text("Info        : " . $info . "\n");
      $print->text("Table       : " . $table_section_name . "\n");
      $print->text("Purpose     : " . $visitpurpose_name . "\n");
      $print->text("Pax         : " . $pax . "\n");
      $print->text("Chasier     : " . $cashier . "\n");
      $print->text("Status      : ");
      $print->setEmphasis(true);
      $print->text(strtoupper($data_order->status) . "\n");
      $print->setEmphasis(false);
      $print->text(self::separator("-", $charPerLine));
      foreach ($data_order_detail as $itemmenu) {

        $print->text(self::threeline($itemmenu->qty, $itemmenu->menu_name, number_format($itemmenu->total, 0, ',', '.'), $charPerLine));
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
            $print->text(self::threeline("", " " . $itempackage->qty . " " . $itempackage->menu_name, number_format($itempackage->total, 0, ',', '.'), $charPerLine));
            if ($itempackage->notes != null || $itempackage->notes != '') {
              $print->text(self::threeline("", " *" . $itempackage->notes, '', $charPerLine));
            }
          }
        }
        if ($itemmenu->notes != null || $itemmenu->notes != '') {
          $print->text(self::line('notes :' . $itemmenu->notes, "",  0));
        }
      }
      $print->text(self::separator("-", $charPerLine));
      $print->text($data_order->total_item . " Items" . "\n");
      $print->setJustification(Printer::JUSTIFY_RIGHT);
      $print->text(self::threeline2("", "Delivery Cost :", "0", $charPerLine)); // disiini
      $print->text(self::threeline2("", "Order Fee :", "0", $charPerLine));
      $print->text("\n");
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
      $print->text(self::threeline2("", "Price Inclusive of PB1 :", number_format($data_order->total_tax, 0, ',', '.'), $charPerLine));

      $print->text(self::separator("-", $charPerLine));
      $print->setJustification(Printer::JUSTIFY_CENTER);
      $print->text($textFooter);

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
      $data_order = TrOrderModel::where('order_number', $order_number)->first();

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
      mi.name as menu_name
      FROM tr_order_detail trod
      JOIN mr_item_conv mic on mic.id = trod.menu_id
      JOIN mr_item mi on mi.id = mic.item_id
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
      $cashier  = "JUSE";

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
      $print->text("Date        : " . $data_order->order_date . "\n");
      $print->text("Time In     : " . $order_in . "\n");
      $print->text("Info        : " . $info . "\n");
      $print->text("Table       : " . $table_section->name . "\n");
      $print->text("Purpose     : " . $visitpurpose_name . "\n");
      $print->text("Pax         : " . $pax . "\n");
      $print->text("Chasier     : " . $cashier . "\n");

      $print->text("Status      : ");
      $print->setEmphasis(true);
      if ($data_order->status == 'pending') {
        $print->text("NOT PAID" . "\n");
      } else if ($data_order->status == 'cancel') {
        $print->text("CANCELED" . "\n");
      }
      $print->setEmphasis(false);

      $print->text(self::separator("-", $charPerLine));
      foreach ($data_order_detail as $itemmenu) {
        $print->text(self::threeline($itemmenu->qty, $itemmenu->menu_name, number_format($itemmenu->total, 0, ',', '.'), $charPerLine));
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
            $print->text(self::threeline("", " " . $itempackage->qty . " " . $itempackage->menu_name, number_format($itempackage->total, 0, ',', '.'), $charPerLine));
            if ($itempackage->notes != null || $itempackage->notes != '') {
              $print->text(self::threeline("", " *" . $itempackage->notes, '', $charPerLine));
            }
          }
        }
        if ($itemmenu->notes != null || $itemmenu->notes != '') {
          $print->text(self::line('notes :' . $itemmenu->notes, "",  0));
        }
      }

      $print->text(self::separator("-", $charPerLine));
      $print->text($data_order->total_item . " Items" . "\n");
      $print->setJustification(Printer::JUSTIFY_RIGHT);
      $print->setEmphasis(true);
      $print->setTextSize(1, 2); // gedene
      $print->text(self::threeline2("", "Grand Total :", number_format($data_order->total_billing, 0, ',', '.'), $charPerLine));
      $print->setTextSize(1, 1); //normal e
      $print->setEmphasis(false);

      $print->text("\n");

      $print->text(self::threeline2("", "Price Inclusive of PB1 :", number_format($data_order->total_tax, 0, ',', '.'), $charPerLine));
      $print->text(self::separator("-", $charPerLine));
      $print->setJustification(Printer::JUSTIFY_CENTER);

      $print->feed(2);
      $print->cut();
      $print->close();
    } catch (\Throwable $e) {
      Log::info($e);
    }
  }

  static function PrintReport($ulid, $is_dayshift_detail = false)
  {
    $datareport = null;
    if (!$is_dayshift_detail) {
      $datareport = DayShiftServices::GetReport($ulid);
    } else {
      try {
        $datareport = DayShiftServices::GetReportByShiftDetail($ulid);
      } catch (\Throwable $e) {
        throw $e;
      }
    }
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
    $print->setEmphasis();
    $print->text("START\n");
    $print->setEmphasis(false);
    $print->text(self::separator("*", $charPerLine));
    $print->setEmphasis();
    $print->text("DAYSHIFT REPORT\n");
    $print->setEmphasis(false);
    $print->setJustification(Printer::JUSTIFY_LEFT);
    $print->text(self::separator("=", $charPerLine));


    $print->feed(1);
    $print->text("Branch Name      : " . $branch->branch_name . "\n");
    $print->text("Chasier          : " . "JUSE" . "\n");
    $print->text("Print Time       : " . now() . "\n");

    if ($datareport['dayshift']->start_time && $datareport['dayshift']->end_time) {
      $print->text("\nShift Number     : " .  $datareport['dayshift']->shift_queue . "\n");
      $print->text("Shift Start      : " . $datareport['dayshift']->start_time . "\n");
      $print->text("Shift End        : " . $datareport['dayshift']->end_time . "\n");
    } else {
      $print->text("\nShift Number     : END DAY\n");
      $print->text("Day Start        : " . $datareport['dayshift']->dayin_time . "\n");
      $print->text("Day End          : " . (($datareport['dayshift']->dayout_time != null) ? $datareport['dayshift']->dayout_time : 'RUNNING') . "\n");
    }


    $print->text(self::separator("-", $charPerLine));
    $print->feed(1);
    $print->setJustification(Printer::JUSTIFY_CENTER);
    // $print->setEmphasis();
    $print->text("SUMMARY REPORT\n");
    // $print->feed(1);
    // $print->setEmphasis(false);
    $print->setJustification(Printer::JUSTIFY_LEFT);
    $sales_recap = $datareport['sales_recapitulation'];

    $print->text(self::kirikakan("Pending Total       :", number_format($sales_recap[0]["amount"], 0, ',', '.'), $charPerLine));
    // $print->text(self::kirikakan("Sales Total         :", number_format($sales_total, 0, ',', '.'), $charPerLine));
    $print->text(self::kirikakan("Discount Total      :", 0, $charPerLine));
    $print->text(self::separator("-", $charPerLine));
    $print->setEmphasis(true);
    $print->text(self::kirikakan("Net Sales Total     :", number_format($sales_recap[1]["amount"], 0, ',', '.'), $charPerLine));
    $print->setEmphasis(false);
    $print->text(self::separator("-", $charPerLine));
    $print->text(self::kirikakan("Delivery Cost Total :", number_format($sales_recap[2]["amount"], 0, ',', '.'), $charPerLine));
    $print->text(self::kirikakan("OrderFee Total      :", number_format($sales_recap[3]["amount"], 0, ',', '.'), $charPerLine));
    $print->text(self::kirikakan("SC Total            :", number_format($sales_recap[4]["amount"], 0, ',', '.'), $charPerLine));
    $print->text(self::kirikakan("PB1 Total           :", number_format($sales_recap[6]["amount"], 0, ',', '.'), $charPerLine));
    $print->text(self::kirikakan("VAT Total           :", number_format($sales_recap[7]["amount"], 0, ',', '.'), $charPerLine));
    $print->text(self::kirikakan("Platform Fee        :", number_format($sales_recap[5]["amount"], 0, ',', '.'), $charPerLine));
    $print->text(self::kirikakan("Voucher Sales       :", 0, $charPerLine));
    $print->text(self::separator("-", $charPerLine));
    $print->setEmphasis(true);
    $print->text(self::kirikakan("Gross Sales         :", number_format($sales_recap[8]["amount"], 0, ',', '.'), $charPerLine));
    $print->setEmphasis(false);
    $print->text(self::separator("-", $charPerLine));
    $print->text(self::kirikakan("Number Of Pax       :", $sales_recap[9]["amount"], $charPerLine));
    $print->text(self::kirikakan("Avg NetSales /Pax   :", number_format($sales_recap[10]["amount"], 0, ',', '.'), $charPerLine));
    $print->text(self::kirikakan("Avg GrossSales /Pax :", number_format($sales_recap[11]["amount"], 0, ',', '.'), $charPerLine));
    $print->text(self::kirikakan("Number Of Bills     :", $sales_recap[12]["amount"], $charPerLine));
    $print->text(self::kirikakan("Avg NetSales /Bill  :", number_format($sales_recap[13]["amount"], 0, ',', '.'), $charPerLine));
    $print->text(self::kirikakan("Avg GrossSales /Bill:", number_format($sales_recap[14]["amount"], 0, ',', '.'), $charPerLine));
    $print->text(self::separator("-", $charPerLine));
    $print->feed(1);
    $print->setJustification(Printer::JUSTIFY_CENTER);
    // $print->setEmphasis();
    $print->text("PAYMENT METHOD REPORT\n");
    // $print->feed(1);
    // $print->setEmphasis(false);
    $print->setJustification(Printer::JUSTIFY_LEFT);
    $totalpayment = 0;
    foreach ($datareport['payment_recapitulation'] as $item) {
      $totalpayment += $item->payment_amount;
      $print->text(self::kirikakan($item->payment_method_name, number_format($item->payment_amount, 0, ',', '.'), $charPerLine));
    }
    $print->text(self::separator("-", $charPerLine));
    $print->setEmphasis(true);
    $print->text(self::kirikakan("Total Payment", number_format($totalpayment, 0, ',', '.'), $charPerLine));
    $print->setEmphasis(false);
    $print->text(self::separator("-", $charPerLine));

    // ///////////////////////////////////////////////
    $print->feed(1);
    $print->setJustification(Printer::JUSTIFY_CENTER);
    // $print->setEmphasis();
    $print->text("NET SALES BY MENU\n");
    // $print->feed(1);
    // $print->setEmphasis(false);
    $print->setJustification(Printer::JUSTIFY_LEFT);
    foreach ($datareport['sales_by_menu'] as $item) {
      $print->text(self::threeline($item->qty, $item->menu_name, number_format($item->sub_total, 0, ',', '.'), $charPerLine));
    }
    $print->text(self::separator("-", $charPerLine));

    // ////////////////////////////////////////////////////
    $print->feed(1);
    $print->setJustification(Printer::JUSTIFY_CENTER);
    // $print->setEmphasis();
    $print->text("NET SALES BY CATEGORY\n");
    // $print->feed(1);
    // $print->setEmphasis(false);
    $print->setJustification(Printer::JUSTIFY_LEFT);
    foreach ($datareport['sales_by_category'] as $item) {
      $print->text(self::threeline($item->qty, $item->category_name, number_format($item->sub_total, 0, ',', '.'), $charPerLine));
    }
    $print->text(self::separator("-", $charPerLine));

    // ////////////////////////////////////////////////////
    $print->feed(1);
    $print->setJustification(Printer::JUSTIFY_CENTER);
    // $print->setEmphasis();
    $print->text("NET SALES BY TABLE\n");
    // $print->feed(1);
    // $print->setEmphasis(false);
    $print->setJustification(Printer::JUSTIFY_LEFT);
    foreach ($datareport['sales_by_table'] as $item) {
      $print->text(self::threeline($item->total_order, $item->table_name, number_format($item->total_amount, 0, ',', '.'), $charPerLine));
    }
    $print->text(self::separator("-", $charPerLine));


    $print->feed(1);
    $print->text(self::separator("*", $charPerLine));
    $print->setJustification(Printer::JUSTIFY_CENTER);
    $print->setEmphasis();
    $print->text("END\n");


    $print->feed(2);
    $print->cut();
    $print->close();
  }
}
