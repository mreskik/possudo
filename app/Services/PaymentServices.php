<?php

namespace App\Services;

use App\Models\BranchModel;
use App\Models\MasterMemberModel;
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


  public static function GenerateOrderNumber()
  {
    // ORDER NUMBER KOMPOSISI
    // <MODUL><BRANCH CODE><TIMESTAMP>
    $branch_data = BranchModel::first();
    // MODULE TAKING ORDER = TO
    $kode_modul = "PP";
    $komposisi = $kode_modul . $branch_data->branch_code . $branch_data->payment_number . time();
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
          'card_number' => $item['card_number'],
          'bank_name' => $item['bank_name'],
          'verification_code' => $item['verification_code'],
          'account_name' => $item['account_name']
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
        "total_change" => 0,
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
