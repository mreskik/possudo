<?php

namespace App\Services;

use App\Models\TrOrderDetailModel;
use App\Models\TrOrderDetailPackageModel;
use App\Models\TrOrderModel;
use App\Models\TrOrderPaymentModel;
use Carbon\Carbon;
use DateTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class PushDataServices
{
  protected string $endpoint = '';
  // protected strin
  public function __construct()
  {
    $this->endpoint = env('SERVER_ENDPOINT');
  }

  protected function formatedDateTimeToTimeTime(string $datetime_or_timestamp)
  {
    $date = Carbon::createFromFormat('Y-m-d H:i:s', $datetime_or_timestamp, config("app.timezone"));
    $formatted = $date->toISOString(true);
    return $formatted;

    // 2026-06-19T02:03:35.000000Z
  }

  protected function formatedDateToTimeTime(string $datetime_or_timestamp)
  {
    $date = Carbon::createFromFormat('Y-m-d H:i:s', $datetime_or_timestamp . " 00:00:00", config("app.timezone"));
    $formatted = $date->toISOString(true);
    return $formatted;

    // 2026-06-19T02:03:35.000000Z
  }

  function pushDataOrder()
  {
    $datetime = now()->toISOString(true);
    // $datetime = now();
    try {
      $list_data_order = TrOrderModel::where('sync_at', null)->get();

      //penyesuaian untuk timestamp/datetime to format go time.Time
      foreach ($list_data_order as $item) {
        if ($item->order_in != null) {
          $item->order_in = $this->formatedDateTimeToTimeTime($item->order_in);
        }

        if ($item->order_out != null) {
          $item->order_out = $this->formatedDateTimeToTimeTime($item->order_out);
        }

        if ($item->cancel_at != null) {
          $item->cancel_at = $this->formatedDateTimeToTimeTime($item->cancel_at);
        }

        if ($item->void_at != null) {
          $item->void_at = $this->formatedDateTimeToTimeTime($item->void_at);
        }

        if ($item->payment_at != null) {
          $item->payment_at = $this->formatedDateTimeToTimeTime($item->payment_at);
        }

        if ($item->moved_at != null) {
          $item->moved_at = $this->formatedDateTimeToTimeTime($item->moved_at);
        }

        if ($item->order_date != null) {
          $item->order_date = $this->formatedDateToTimeTime($item->order_date);
        }

        //set sync at
        $item->sync_at = $datetime;
      }


      // return $list_data_order;


      $response = Http::asJson()->post($this->endpoint . "/pos/push/data_order", [
        "list_order" => $list_data_order
      ]);


      if ($response->json('code') == 0) {

        DB::beginTransaction();
        //update di lokal pos bahwa sudah sync at
        foreach ($list_data_order as $item) {
          TrOrderModel::where("order_number", $item->order_number)->update([
            "sync_at" => $datetime
          ]);
        }
        DB::commit();
        return 'success';
      } else {
        throw new \Exception($response->json('message'));
      }
    } catch (\Throwable $e) {
      if (DB::transactionLevel() > 0) {
        DB::rollBack();
      }
      throw $e;
    }
  }

  function pushDataOrderDetail()
  {
    $datetime = now()->toISOString(true);
    // $datetime = now();
    try {

      $list_data_order_detail = TrOrderDetailModel::where('sync_at', null)->get();

      foreach ($list_data_order_detail as $item) {

        $item->flag_inclusive_tax = (bool)$item->flag_inclusive_tax;
        $item->done_print = (bool)$item->done_print;
        $item->is_free_item_promo = (bool)$item->is_free_item_promo;


        if ($item->cancel_at != null) {
          $item->cancel_at = $this->formatedDateTimeToTimeTime($item->cancel_at);
        }

        if ($item->tax_rate == null) {
          $item->tax_rate = '0';
        }

        if ($item->sync_at == null) {
          $item->sync_at = $datetime;
        }
      }

      // return $list_data_order_detail;


      $response = Http::asJson()->post($this->endpoint . "/pos/push/data_order_detail", [
        "list_order_detail" => $list_data_order_detail
      ]);
      if ($response->json('code') == 0) {

        DB::beginTransaction();
        //update di lokal pos bahwa sudah sync at
        foreach ($list_data_order_detail as $item) {
          TrOrderDetailModel::where("ulid", $item->ulid)->update([
            "sync_at" => $datetime
          ]);
        }

        DB::commit();
        return 'success';
      } else {
        throw new \Exception($response->json('message'));
      }
    } catch (\Throwable $e) {
      if (DB::transactionLevel() > 0) {
        DB::rollBack();
      }
      throw $e;
    }
  }

  function pushDataOrderDetailPackage()
  {

    $datetime = now()->toISOString(true);
    try {

      $list_data_order_detail_package = TrOrderDetailPackageModel::where('sync_at', null)->get();

      foreach ($list_data_order_detail_package as $item) {

        $item->flag_inclusive_tax = (bool)$item->flag_inclusive_tax;
        $item->done_print = (bool)$item->done_print;

        if ($item->tax_rate == null) {
          $item->tax_rate = '0';
        }

        if ($item->sync_at == null) {
          $item->sync_at = $datetime;
        }
      }

      // return $list_data_order_detail_package;


      $response = Http::asJson()->post($this->endpoint . "/pos/push/data_order_detail_package", [
        "list_order_detail_package" => $list_data_order_detail_package
      ]);
      if ($response->json('code') == 0) {

        DB::beginTransaction();
        //update di lokal pos bahwa sudah sync at
        foreach ($list_data_order_detail_package as $item) {
          TrOrderDetailPackageModel::where("ulid", $item->ulid)->update([
            "sync_at" => $datetime
          ]);
        }

        DB::commit();
        return 'success';
      } else {
        throw new \Exception($response->json('message'));
      }
    } catch (\Throwable $e) {
      if (DB::transactionLevel() > 0) {
        DB::rollBack();
      }
      throw $e;
    }
  }

  function pushDataOrderPayment()
  {

    $datetime = now()->toISOString(true);
    try {

      $list_data_order_payment = TrOrderPaymentModel::where('sync_at', null)->get();

      foreach ($list_data_order_payment as $item) {
        // $item->flag_inclusive_tax = (bool)$item->flag_inclusive_tax;
        // $item->done_print = (bool)$item->done_print;

        // if ($item->tax_rate == null) {
        //   $item->tax_rate = '0';
        // }

        if ($item->sync_at == null) {
          $item->sync_at = $datetime;
        }
      }

      // return $list_data_order_detail_package;


      $response = Http::asJson()->post($this->endpoint . "/pos/push/data_order_payment", [
        "list_order_payment" => $list_data_order_payment
      ]);
      if ($response->json('code') == 0) {

        DB::beginTransaction();
        //update di lokal pos bahwa sudah sync at
        foreach ($list_data_order_payment as $item) {
          TrOrderPaymentModel::where("ulid", $item->ulid)->update([
            "sync_at" => $datetime
          ]);
        }

        DB::commit();
        return 'success';
      } else {
        throw new \Exception($response->json('message'));
      }
    } catch (\Throwable $e) {
      if (DB::transactionLevel() > 0) {
        DB::rollBack();
      }
      throw $e;
    }
  }
}
