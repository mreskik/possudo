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
    $konektor = new WindowsPrintConnector("BEVERAGE");
    $print = new Printer($konektor);


    /////////////////////
    resizeGambar("logo1.png", 165);

    $imageLogo = EscposImage::load("logo_resize.png", false);
    $charPerLine = 48;
    $textHeader =  "SUDO BREW BEKASI Jl. Bekasi Kota No 80 Bekasi";
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
        return $left . str_pad($right, $leftWidth, " ", STR_PAD_LEFT) . "\n";
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

    function threeline2($qty, $item, $harga, $width = 0)
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




    function separator($char = '-', $width = 0)
    {
        return str_repeat($char, $width) . "\n";
    }


    // $print->feed(1);

    // /

    $print->setJustification(Printer::JUSTIFY_CENTER);

    $print->bitImage($imageLogo);
    $print->text("\n");

    $print->setEmphasis(true);
    $print->text("$textHeader\n");
    $print->setEmphasis(false);
    // $print->text("$alamat\n\n");

    $print->setJustification(Printer::JUSTIFY_LEFT);
    $print->text(separator("-", $charPerLine));

    $print->text("No          : " . $orderNumber . "\n");
    $print->text("Sales No    : " . $salesNo . "\n");
    $print->text("Date        : " . $date . "\n");
    $print->text("Time In     : " . $timeIn . "\n");
    $print->text("Info        : " . $info . "\n");
    $print->text("Table       : " . $table . "\n");
    $print->text("Purpose     : " . $visitPurpose . "\n");
    $print->text("Pax         : " . $pax . "\n");
    $print->text("Cashier     : " . $cashier . "\n");
    $print->text("Status      : ");
    $print->setEmphasis(true);
    $print->text("PAID" . "\n");
    $print->setEmphasis(false);


    $print->text(separator("-", $charPerLine));
    $print->text(threeline(4, "Nasi Goreng Nusantara", "17.000", $charPerLine));
    $print->text(threeline(4, "Muhroom Truffle Soup", "30.000", $charPerLine));
    $print->text(separator("-", $charPerLine));
    $print->text("2 Items" . "\n");
    $print->setJustification(Printer::JUSTIFY_RIGHT);
    $print->text(threeline2("", "Delivery Cost :", "0", $charPerLine));
    $print->text(threeline2("", "Order Fee :", "0", $charPerLine));
    $print->text("\n");
    $print->setEmphasis(true);
    $print->setTextSize(1, 2); // gedene
    $print->text(threeline2("", "Grand Total :", "50.000", $charPerLine));
    $print->setTextSize(1, 1); //normal e
    $print->setEmphasis(false);

    $print->text("\n");
    $print->text(threeline2("", "QRIS BCA :", "50.000", $charPerLine));

    // $print->text("Order Fee : 5.000"."\n");
    // $print->text("Grand Total : 57.000"."\n");
    // $print->text("Qris BCA : 57.000"."\n");

    // $print->setJustification(Printer::JUSTIFY_LEFT);
    $print->text(separator("-", $charPerLine));
    $print->text(threeline2("", "Change :", "0", $charPerLine));
    $print->text(threeline2("", "Price Inclusive of PB1 :", "5.000", $charPerLine));

    $print->text(separator("-", $charPerLine));
    $print->setJustification(Printer::JUSTIFY_CENTER);
    $print->text($textFooter);
    // $print->text(line("Nasi Goreng", "15000",$charPerLine));
    // $print->text(line("Es Teh", "5000", $charPerLine));

    // $print->text(separator("-", $charPerLine));

    // $print->setEmphasis(true);
    // $print->text(line("TOTAL", "20000", $charPerLine));
    // $print->setEmphasis(false);

    // $print->feed(2);
    // $print->setJustification(Printer::JUSTIFY_CENTER);
    // $print->text("Terima kasih\n");
    $print->feed(2);
    $print->cut();
    $print->close();
} catch (Exception $e) {
    print "errrorr : " . $e;
}
