<?php

namespace App\Services;

use App\Models\BranchModel;
use App\Models\MasterMemberModel;
use App\Models\MasterPaymentMethodModel;
use App\Models\TrOrderDetailModel;
use App\Models\TrOrderModel;
use App\Models\TrOrderPaymentModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use stdClass;


class PaymentServices
{
  // calculateMdrAmount: mdr_amount SNAPSHOT (2026-10-01) -- payment_amount x
  // mr_payment_method.mdr / 100, dihitung SAAT payment dibuat (bukan dihitung ulang
  // belakangan). CUMA informasi, TIDAK ada jurnal/posting akuntansi yang nyentuh nilai ini.
  // payment_method_id gak ketemu -- dianggap mdr 0 (bukan exception, biar SavePayment() gak
  // gagal total gara-gara lookup mdr doang).
  private static function calculateMdrAmount(int $paymentMethodId, float $paymentAmount): float
  {
    $paymentMethod = MasterPaymentMethodModel::find($paymentMethodId);
    if (!$paymentMethod || !$paymentMethod->mdr) {
      return 0;
    }
    return round($paymentAmount * (float) $paymentMethod->mdr / 100, 2);
  }


  public static function GenerateOrderNumber($terminal_id)
  {
    // ORDER NUMBER KOMPOSISI
    // <MODUL><TERMINAL ID><BRANCH CODE><time order in >
    $branch_data = BranchModel::first();
    // MODULE TAKING ORDER = TO
    $kode_modul = "PS";
    $daydetail = now()->format("YmdHis");
    $komposisi = $kode_modul .$terminal_id. $branch_data->branch_code . $daydetail;
    return $komposisi;
  }



  public static function SavePayment(Request $datajson)
  {
    $response = new stdClass;
    $response->success = false;
    Log::info($datajson);

    try {
      $dataorder_current = TrOrderModel::where('order_number', $datajson->order_number)->first();
      
      if ($dataorder_current) {
        if ($dataorder_current->payment_number != null) {
          $response->message = "order " . $datajson->order_number . " has paid!";
          return $response;
        }
      } else {
        $response->message = "order " . $datajson->order_number . " not found!";
        return $response;
      }

      $payment_number = self::GenerateOrderNumber($dataorder_current->terminal_id);

      DB::beginTransaction();

      TrOrderModel::where('order_number', $datajson->order_number)->update([
        'payment_number' => $payment_number,
        'status' => 'paid',
        'order_out' => now(),
        'payment_at' => now(),
        'sync_at' => null,
        'member_id' => $datajson->member_id
      ]);


      $payment_detail = [];

      foreach ($datajson->payment_detail as $item) {
        $payment_detail[] = [
          'ulid' => (string)Str::ulid(),
          'payment_number' => $payment_number,
          'payment_method_id' => $item['payment_method_id'],
          'payment_amount' => $item['payment_amount'],
          // change_amount: kembalian tunai, cuma keisi kalau frontend ngirim (CASH), default 0
          // buat method lain -- lihat migration change_amount di tr_order_payment.
          'change_amount' => $item['change_amount'] ?? 0,
          // info (migration 2026_09_30, GANTI card_number/bank_name/account_name/
          // verification_code) -- 1 field bebas teks, diisi kasir apa adanya buat metode
          // pembayaran non-cash (kartu/EDC dst).
          'info' => $item['info'] ?? null,
          // mdr_amount (migration 2026_10_01) -- dihitung SERVER-SIDE dari mr_payment_method.mdr,
          // BUKAN dari client (gak ada di $item sama sekali, biar gak bisa dimanipulasi frontend).
          'mdr_amount' => self::calculateMdrAmount((int) $item['payment_method_id'], (float) $item['payment_amount']),
        ];
      }

      TrOrderPaymentModel::insert($payment_detail);

      DB::commit();


      // Kiosk yang terminal-nya di-set flag_printer_frontend (browser yang nge-print struk,
      // bukan printer server) -- skip PrintPayment() server-side. Endpoint buat frontend
      // ambil data struk by order_number nyusul terpisah (belum digarap). POS gak kepengaruh
      // sama sekali, tetap selalu print server-side kayak biasa.
      $terminal = DB::table('mr_terminal')->where('id', $dataorder_current->terminal_id)->first();
      $skipReceiptPrint = $dataorder_current->order_source === 'kiosk'
        && $terminal
        && $terminal->flag_printer_frontend;

      if (!$skipReceiptPrint) {
        PrintServices::PrintPayment($datajson->order_number);
      }

      // order Kiosk sengaja gak nge-print kitchen pas SaveOrder (nunggu kepastian bayar dulu,
      // lihat OrderServices::SaveOrder()) -- baru di sini, abis payment sukses. Ini SELALU
      // jalan buat kiosk, gak kepengaruh flag_printer_frontend -- dapur tetap butuh tau apa
      // yang harus dimasak, terlepas dari cara struk customer di-print.
      if ($dataorder_current->order_source === 'kiosk') {
        PrintServices::PrintTableChecker2($dataorder_current->table_section_id, $datajson->order_number);
        PrintServices::PrintMainChecker2($dataorder_current->table_section_id, $datajson->order_number);
        PrintServices::PrintPriparationStation($dataorder_current->table_section_id, $datajson->order_number);

        TrOrderDetailModel::where('order_number', $datajson->order_number)->update([
          "done_print" => true,
        ]);

        // tr_kiosk_order_notif: penanda toast "order kiosk baru" di POS -- SENGAJA di sini
        // (payment sukses), BUKAN di SaveOrder() -- order kiosk yang dibuat tapi gak jadi
        // dibayar gak boleh ikut nongol jadi notif. Sama semangatnya kayak mb_order (mobile)
        // yang cuma nongol pas status='paid'. Lihat OrderNotifServices.
        DB::table('tr_kiosk_order_notif')->insert([
          'order_number' => $datajson->order_number,
          'flag_confirm' => false,
        ]);
      }

      $response->success = true;
      $response->paymentNumber = $payment_number;

      if ($response->success) {
        return $response;
      } else {
        $response->message = 'gagal!';
        return $response;
      }
    } catch (\Throwable $e) {

      $response->message = $e->getMessage();
      return $response;
    }
  }

  public static function EditPayment(Request $datajson)
  {
    try {
      $dataorder = TrOrderModel::where('order_number', $datajson->order_number)->first();
      if (!$dataorder) {
        throw new \Exception("order number $datajson->order_number tidak ditemukan!");
      }
      if ($dataorder->payment_number == null || $dataorder->payment_number == '') {
        throw new \Exception("order number $datajson->order_number belum pernah payment!");
      }

      Log::info($dataorder->total_billing);
      Log::info("" . $datajson->total_payment);

      if ($dataorder->total_billing != $datajson->total_payment) {
        throw new \Exception("Jumlah nominal payment tidak sama dengan order!");
      }
      DB::beginTransaction();
      $datapaymentlama = TrOrderPaymentModel::where('payment_number', $dataorder->payment_number)->delete();

      $payment_detail = [];
      foreach ($datajson->payment_detail as $item) {
        $payment_detail[] = [
          'ulid' => (string)Str::ulid(),
          'payment_number' => $dataorder->payment_number,
          'payment_method_id' => $item['payment_method_id'],
          'payment_amount' => $item['payment_amount'],
          'change_amount' => $item['change_amount'] ?? 0,
          'info' => $item['info'] ?? null,
          'mdr_amount' => self::calculateMdrAmount((int) $item['payment_method_id'], (float) $item['payment_amount']),
        ];
      }

      TrOrderPaymentModel::insert($payment_detail);
      DB::commit();

      return "update payment success!";
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public static function ViewPayment(string $order_number)
  {
    try {

      $dataorder = TrOrderModel::where('order_number', $order_number)->first();
      if (!$dataorder) {
        throw new \Exception("order number $order_number tidak ditemukan!");
      }
      if ($dataorder->payment_number == null || $dataorder->payment_number == '') {
        throw new \Exception("order number $order_number belum pernah payment!");
      }

      $datapaymentdetail = TrOrderPaymentModel::where("payment_number", $dataorder->payment_number)->get();
      $member = $dataorder->member_id ? MasterMemberModel::find($dataorder->member_id) : null;
      $data_payment = [
        "order_number" => $dataorder->order_number,
        "payment_number" => $dataorder->payment_number,
        "total_payment" => $dataorder->total_billing,
        "item_voucher" => 0,
        "total_amount_voucher" => 0,
        // total_change: jumlah change_amount semua payment_detail (biasanya cuma 1 baris CASH
        // yang keisi, tapi dijumlah semua jaga-jaga split payment ada >1 baris CASH).
        "total_change" => $datapaymentdetail->sum('change_amount'),
        "payment_detail" => $datapaymentdetail,
        "member_id" => $dataorder->member_id,
        "member_name" => $member->name ?? null,
      ];

      return $data_payment;
    } catch (\Throwable $err) {
      throw $err;
    }
  }
}
