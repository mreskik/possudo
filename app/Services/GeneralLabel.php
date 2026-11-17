<?php

namespace App\Services;

// require 'vendor/autoload.php';

use \Mike42\Escpos\PrintConnectors\WindowsPrintConnector;
use \Mike42\Escpos\PrintConnectors\NetworkPrintConnector;
use \Mike42\Escpos\Printer;
use \Mike42\Escpos\CapabilityProfile;
use Mike42\Escpos\PrintConnectors\PrintConnector;

class GeneralLabel
{
  protected $textnya = [], $top = 0, $left = 0;
  protected  $connector;
  protected $printer;

  // setNamePrinter: terima $data_station (object, bukan cuma nama string lagi, 2026-11-17) --
  // diselaraskan dengan PrintServices::resolvePrintConnector() biar label/stiker JUGA bisa
  // pakai Network Printer Connection (printer_connection=2), bukan cuma struk ESC/POS thermal.
  // Backward-compat: kalau dikasih string polos (caller lama yang belum di-update), tetap
  // jalan sebagai WindowsPrintConnector seperti sebelumnya -- gak ada breaking change.
  public function setNamePrinter($station)
  {
    try {
      if (is_string($station)) {
        $this->connector = new WindowsPrintConnector($station);
        return;
      }

      if ((int) $station->printer_connection === 2) {
        $port = (int) ($station->port ?: 9100);
        $this->connector = new NetworkPrintConnector($station->printer_name, $port);
        return;
      }

      $this->connector = new WindowsPrintConnector($station->printer_name);
    } catch (\Throwable $e) {
      // bug pre-existing (ditemukan 2026-11-17, bukan disebabkan perubahan Network Connection
      // di atas) -- $this->printer masih null di titik ini (instance Printer baru dibuat SETELAH
      // connector sukses, lihat sikat()), jadi ->close() di sini SELALU gagal dengan "Call to a
      // member function close() on null", nutupin exception asli (misal pesan network refused
      // yang justru penting buat debug). Guard null check, biarkan $e asli yang nyampe ke luar.
      if ($this->printer !== null) {
        $this->printer->close();
      }
      throw $e;
    }
  }

  // $font: kode font TSPL command TEXT (lihat sikat()) -- default "2" sama kayak sebelumnya,
  // pakai "TSS24.BF2" (font bawaan TSC yang miring) buat baris yang mau dicetak italic.
  public function setText(string $text, string $font = "2")
  {
    $this->textnya[] = ["text" => $text, "font" => $font];
  }
  public function setMargin(int $left, int $top)
  {
    $this->left = $left;
    $this->top = $top;
  }

  public function sikat()
  {
    try {

      // if ($this->connector == null) {
      //   return print "printer name belum di setting!";
      // }

      $inisialisasi = "
      SIZE 40 mm,60 mm\n
      GAP 2 mm,0 mm\n
      DIRECTION 0\n
      CLS\n
      ";
      foreach ($this->textnya as $i) {
        $inisialisasi = $inisialisasi . "TEXT $this->left,$this->top,\"{$i['font']}\",0,1,1,\"{$i['text']}\"\n ";
        $this->top += 25; //jarak antar spasi teks
      }

      $inisialisasi = $inisialisasi . "PRINT 1\n";
      $this->printer = new Printer($this->connector, CapabilityProfile::load("simple"));
      $this->printer->getPrintConnector()->write($inisialisasi);
      $this->printer->close();
    } catch (\Throwable $e) {
      $this->printer->close();
      throw $e;
    }
  }
}
