<?php

require 'vendor/autoload.php';

use \Mike42\Escpos\PrintConnectors\WindowsPrintConnector;
use \Mike42\Escpos\Printer;
use \Mike42\Escpos\CapabilityProfile;


class GeneralLabel
{
  protected $textnya = [], $jarakenter = 30, $connector, $printer;


  public function setNamePrinter($name)
  {
    try {
      $this->connector = new WindowsPrintConnector($name);
    } catch (Exception $e) {
      return $e->getMessage();
    }
  }

  public function setText($text)
  {
    $this->textnya[] = $text;
  }

  public function sikat()
  {
    try {

      if ($this->connector == null) {
        return print "printer name belum di setting!";
      }

      $inisialisasi = "
      SIZE 40 mm,60 mm\n
      GAP 2 mm,0 mm\n
      DIRECTION 0\n
      CLS\n
      ";
      foreach ($this->textnya as $i) {
        $inisialisasi = $inisialisasi . "TEXT 50,$this->jarakenter,\"2\",0,1,1,\"$i\"\n ";
        $this->jarakenter += 25;
      }
      $inisialisasi = $inisialisasi . "PRINT 1\n";
      $this->printer = new Printer($this->connector, CapabilityProfile::load("simple"));
      $this->printer->getPrintConnector()->write($inisialisasi);
      $this->printer->close();
    } catch (Exception $e) {
      return $e->getMessage();
    }
  }
}



try {
  $ngeprint = new GeneralLabel;

  $ngeprint->setNamePrinter("LABELE");
  $ngeprint->setText("17/04/2026 10:45:51");
  $ngeprint->setText("34 | Agung | 2/4");
  $ngeprint->setText("DINE IN");
  $ngeprint->setText("ESPRESSO");
  $ngeprint->setText("1 x HOT");
  $ngeprint->setText("ESPRESSO");
  $ngeprint->setText("1 x HOT");
  $ngeprint->setText("ESPRESSO");
  $ngeprint->setText("1 x HOT");
  $ngeprint->setText("ESPRESSO");
  $ngeprint->setText("1 x HOT");
  $ngeprint->sikat();
} catch (Exception $e) {
  print $e->getMessage();
}
