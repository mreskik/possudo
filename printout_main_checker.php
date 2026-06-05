<?php

require 'vendor/autoload.php';

use \Mike42\Escpos\PrintConnectors\WindowsPrintConnector;
use \Mike42\Escpos\Printer;
use \Mike42\Escpos\EscposImage;

// inisialisasi

function resizeGambar($source = "", $newWidth = 150)
{
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

try {
    $konektor = new WindowsPrintConnector("POS-80");
    $print = new Printer($konektor);


    /////////////////////
    resizeGambar("logo1.png", 165);

    $imageLogo = EscposImage::load("logo_resize.png", false);
    $charPerLine = 48;
    $textheader =  "SUDO BREW BEKASI
Jl. Bekasi Kota No 80 Bekasi";
    $textFooter =  "SUDO BREW BEKASI";
    // $alamat = "Jl. Pulo Sirih Boulevard Blok FE 374 Pekayon";
    $orderNumber  = "ERD2026040120365";
    $salesNo  = "SERD202604012033424";
    $date  = date("d-m-Y i:H");
    $timeIn  = date("d-m-Y i:H");
    $info  = "Baskara";
    $table  = "Quick Service";
    $visitPurpose  = "DINE IN";
    $pax  = 3;
    $cashier  = "JUSE";

    //////////////////// end inisialisasi

    function line($left, $right, $width = 0)
    {
        $leftWidth = $width - strlen($right);
        return str_pad($left, $leftWidth) . $right . "\n";
    }

    function threeline($qty, $item, $harga, $width = 0)
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



    function separator($char = '-', $width = 0)
    {
        return str_repeat($char, $width) . "\n";
    }



    // /

    // $print->setJustification(Printer::JUSTIFY_CENTER);

    // $print->bitImage($imageLogo);
    // $print->text("\n");

    // $print->setEmphasis(true);
    // $print->text("$branchName\n");
    // $print->setEmphasis(false);
    // $print->text("$alamat\n\n");




    $print->feed(1);
    $print->setJustification(Printer::JUSTIFY_LEFT);
    // $print->setTextSize(20,20)
    $print->setEmphasis(true);
    $print->setTextSize(2, 2);
    $print->text("STNAKO92138312732\n");
    $print->text($date . "\n");
    $print->text("QUEUE : 32" . "\n");
    $print->text("QUICK SERVICE" . "\n");
    $print->feed(1);
    $print->text("DINE IN" . "\n");
    $print->feed(1);
    $print->setEmphasis(false);
    $print->setTextSize(1, 1);


    $print->setJustification(Printer::JUSTIFY_LEFT);
    $print->text("Info        : " . $info . "\n");
    $print->text("Waiter      : " . $cashier . "\n");
    $print->text("Sender      : " . $cashier . "\n");
    $print->text("Batch       : 1" . "\n");
    $print->text("Pax         : 1" . "\n");

    $print->text(separator("=", $charPerLine));
    $print->setJustification(Printer::JUSTIFY_CENTER);
    $print->setEmphasis(true);
    $print->setTextSize(1, 2);
    $print->text("MAIN CHECKER" . "\n");
    $print->setTextSize(1, 1);
    $print->setEmphasis(false);
    $print->text(separator("=", $charPerLine));
    $print->setJustification(Printer::JUSTIFY_LEFT);
    // $print->setEmphasis(true);
    $print->setTextSize(1, 2);
    $print->text("  " . "1 EXPRESSO" . "\n");
    $print->text("    " . "1 HOT" . "\n");
    $print->text("  " . "1 BUTTERINO SHIROKON" . "\n");
    $print->text("    " . "1 ICE S" . "\n");
    $print->text("  " . "1 CHESTNUT ORENJI LATTE" . "\n");
    $print->text("    " . "1 HOT" . "\n");
    $print->setTextSize(1, 2);
    // $print->setEmphasis(false);
    $print->text(separator("-"), $charPerLine);
    $print->text(separator("-"), $charPerLine);
    $print->feed(1);

    $print->cut();
    $print->close();
} catch (Exception $e) {
    print "errrorr : " . $e;
}
