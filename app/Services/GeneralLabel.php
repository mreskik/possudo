<?php

namespace App\Services;

// require 'vendor/autoload.php';

use \Mike42\Escpos\PrintConnectors\WindowsPrintConnector;
use \Mike42\Escpos\Printer;
use \Mike42\Escpos\CapabilityProfile;
use Mike42\Escpos\PrintConnectors\PrintConnector;

class GeneralLabel
{
  protected $textnya = [], $top = 0, $left = 0;
  protected  $connector;
  protected $printer;

  public function setNamePrinter(string $name)
  {
    try {
      $this->connector = new WindowsPrintConnector($name);
    } catch (\Throwable $e) {
      $this->printer->close();
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
