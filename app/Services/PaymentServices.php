<?php

namespace App\Services;

use App\Models\BranchModel;
use App\Models\TrOrderModel;
use App\Models\TrOrderPaymentModel;
use App\Models\TrPaymentDetailModel;
use App\Models\TrPaymentModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use stdClass;


class PaymentServices
{


  public static function GenerateOrderNumber()
  {
    // ORDER NUMBER KOMPOSISI
    // <MODUL><BRANCH CODE><TIMESTAMP>
    $branch_data = BranchModel::first();
    // MODULE TAKING ORDER = TO
    $kode_modul = "PP";
    $komposisi = $kode_modul . $branch_data->branch_code . time();
    return $komposisi;
  }



  public static function SavePayment(Request $datajson)
  {
    $response = new stdClass;
    $response->success = false;
    Log::info($datajson);

    $payment_number = self::GenerateOrderNumber();
    try {



      $dataorder_current = TrOrderModel::where('order_number', $datajson->order_number)
        ->first();

      // return $response;
      if ($dataorder_current) {
        if ($dataorder_current->payment_number != null) {
          $response->message = "order " . $datajson->order_number . " has paid!";
          return $response;
        }
      } else {
        $response->message = "order " . $datajson->order_number . " not found!";
        return $response;
      }


      DB::beginTransaction();

      TrOrderModel::where('order_number', $datajson->order_number)->update([
        'payment_number' => $payment_number,
        'status' => 'paid',
        'order_out' => now(),
        'payment_at' => now()
      ]);


      $payment_detail = [];

      foreach ($datajson->payment_detail as $item) {
        $payment_detail[] = [
          'payment_number' => $payment_number,
          'payment_method_id' => $item['payment_method_id'],
          'payment_amount' => $item['payment_amount'],
          'card_number' => $item['card_number'],
          'bank_name' => $item['bank_name'],
          'verification_code' => $item['verification_code'],
          'account_name' => $item['account_name']
        ];
      }

      TrOrderPaymentModel::insert($payment_detail);

      DB::commit();


      PrintServices::PrintPayment($datajson->order_number);


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
}
