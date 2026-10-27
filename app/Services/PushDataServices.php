<?php

namespace App\Services;

use App\Models\DaySiftModel;
use App\Models\DayShiftDetailModel;
use App\Models\TrOrderDetailModel;
use App\Models\TrOrderDetailPackageModel;
use App\Models\TrOrderModel;
use App\Models\TrOrderPaymentModel;
use App\Models\TrRemoveItemBeforeSaveModel;
use App\Models\TrRemoveItemBeforeSavePackageModel;
use App\Models\BranchModel;
use App\Models\MasterItemModel;
use Carbon\Carbon;
use DateTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

// connectTimeout(5)/timeout(30) dipasang di semua request Http:: push di bawah (2026-09-18) --
// sebelumnya gak ada timeout sama sekali, jadi kalau internet mati pas tombol Sync/Install
// dipencet, request bisa gantung lama nungguin OS-level TCP timeout tanpa cara buat user
// ngebatalin. timeout-nya lebih longgar dari SetupServices::syncRequest() (15s) karena payload
// push bisa lebih besar (banyak order/dayshift sekaligus dalam 1 request).
class PushDataServices
{
  protected string $endpoint = '';
  // protected strin
  public function __construct()
  {
    $this->endpoint = env('SERVER_ENDPOINT');
  }

  // requireBranchToken (2026-10-16): token branch SEKARANG WAJIB dikirim ke semua endpoint
  // /pos/push/* (sebelumnya TANPA otentikasi apa pun -- siapa pun yang tau URL bisa nyuntik
  // data order/dayshift/payment palsu buat branch_id manapun, lihat APIANDORDER
  // middleware.BranchTokenAuth di pushRouter). Diambil FRESH dari mr_branch tiap kali fungsi
  // push dipanggil (BUKAN disimpen sekali di constructor) -- biar kalau device baru SELESAI
  // install SAAT proses background (sync:push) lagi jalan, putaran BERIKUTNYA otomatis dapat
  // token baru tanpa perlu restart service apa pun. Dipanggil PALING AWAL (sebelum query data
  // lokal) -- device yang belum install gagal cepat, gak buang-buang query/HTTP request buat
  // data yang toh gak akan pernah ke-push tanpa token.
  protected function requireBranchToken(): string
  {
    $branch = BranchModel::first();
    if (!$branch || !$branch->token) {
      throw new \Exception('branch belum di-install, push dilewati');
    }
    return $branch->token;
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
    // order_date murni tanggal kalender (bukan instant waktu), jadi jangan
    // diinterpretasikan pakai timezone lokal (Asia/Jakarta) sebelum dikonversi
    // ke UTC -- itu bikin tanggalnya mundur 1 hari (00:00 WIB = 17:00 UTC hari
    // sebelumnya). Interpretasikan sebagai UTC supaya tanggalnya tidak bergeser.
    $date = Carbon::createFromFormat('Y-m-d H:i:s', $datetime_or_timestamp . " 00:00:00", 'UTC');
    $formatted = $date->toISOString(true);
    return $formatted;

    // 2026-06-19T00:00:00.000000Z
  }

  function pushDataOrder()
  {
    $datetime = now()->toISOString(true);
    // $datetime = now();
    try {
      $token = $this->requireBranchToken();
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


      $response = Http::asJson()->withToken($token)->withHeaders(BranchAuthHeaderService::build())->connectTimeout(5)->timeout(30)->withOptions(['verify' => config('services.http_verify_ssl')])->post($this->endpoint . "/pos/push/data_order", [
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
      $token = $this->requireBranchToken();

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


      $response = Http::asJson()->withToken($token)->withHeaders(BranchAuthHeaderService::build())->connectTimeout(5)->timeout(30)->withOptions(['verify' => config('services.http_verify_ssl')])->post($this->endpoint . "/pos/push/data_order_detail", [
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
      $token = $this->requireBranchToken();

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


      $response = Http::asJson()->withToken($token)->withHeaders(BranchAuthHeaderService::build())->connectTimeout(5)->timeout(30)->withOptions(['verify' => config('services.http_verify_ssl')])->post($this->endpoint . "/pos/push/data_order_detail_package", [
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
      $token = $this->requireBranchToken();

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


      $response = Http::asJson()->withToken($token)->withHeaders(BranchAuthHeaderService::build())->connectTimeout(5)->timeout(30)->withOptions(['verify' => config('services.http_verify_ssl')])->post($this->endpoint . "/pos/push/data_order_payment", [
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

  function pushDataDayShift()
  {
    $datetime = now()->toISOString(true);
    try {
      $token = $this->requireBranchToken();
      $list_data_dayshift = DaySiftModel::where('sync_at', null)->get();

      foreach ($list_data_dayshift as $item) {
        if ($item->dayin_time != null) {
          $item->dayin_time = $this->formatedDateTimeToTimeTime($item->dayin_time);
        }

        if ($item->dayout_time != null) {
          $item->dayout_time = $this->formatedDateTimeToTimeTime($item->dayout_time);
        }

        $item->sync_at = $datetime;
      }

      $response = Http::asJson()->withToken($token)->withHeaders(BranchAuthHeaderService::build())->connectTimeout(5)->timeout(30)->withOptions(['verify' => config('services.http_verify_ssl')])->post($this->endpoint . "/pos/push/data_dayshift", [
        "list_dayshift" => $list_data_dayshift
      ]);

      if ($response->json('code') == 0) {
        DB::beginTransaction();
        foreach ($list_data_dayshift as $item) {
          DaySiftModel::where("ulid", $item->ulid)->update([
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

  function pushDataDayShiftDetail()
  {
    $datetime = now()->toISOString(true);
    try {
      $token = $this->requireBranchToken();
      $list_data_dayshift_detail = DayShiftDetailModel::where('sync_at', null)->get();

      foreach ($list_data_dayshift_detail as $item) {
        if ($item->shift_time != null) {
          $item->shift_time = $this->formatedDateTimeToTimeTime($item->shift_time);
        }

        $item->sync_at = $datetime;
      }

      $response = Http::asJson()->withToken($token)->withHeaders(BranchAuthHeaderService::build())->connectTimeout(5)->timeout(30)->withOptions(['verify' => config('services.http_verify_ssl')])->post($this->endpoint . "/pos/push/data_dayshift_detail", [
        "list_dayshift_detail" => $list_data_dayshift_detail
      ]);

      if ($response->json('code') == 0) {
        DB::beginTransaction();
        foreach ($list_data_dayshift_detail as $item) {
          DayShiftDetailModel::where("ulid", $item->ulid)->update([
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

  // pushDataRemoveItemBeforeSave/Package (2026-09-18): sama pola persis fungsi push di atas --
  // endpoint terima di APIANDORDER udah ada (pushdata_handler.go PushDataPosRemoveItemBeforeSave
  // /...Package), nulis ke pos_remove_item_before_save(_package) di sudocore2 (migration 213/214).
  function pushDataRemoveItemBeforeSave()
  {
    $datetime = now()->toISOString(true);
    try {
      $token = $this->requireBranchToken();
      $list_data = TrRemoveItemBeforeSaveModel::where('sync_at', null)->get();

      foreach ($list_data as $item) {
        if ($item->created_at != null) {
          $item->created_at = $this->formatedDateTimeToTimeTime($item->created_at);
        }
        $item->sync_at = $datetime;
      }

      $response = Http::asJson()->withToken($token)->withHeaders(BranchAuthHeaderService::build())->connectTimeout(5)->timeout(30)->withOptions(['verify' => config('services.http_verify_ssl')])->post($this->endpoint . "/pos/push/data_remove_item_before_save", [
        "list_remove_item_before_save" => $list_data
      ]);

      if ($response->json('code') == 0) {
        DB::beginTransaction();
        foreach ($list_data as $item) {
          TrRemoveItemBeforeSaveModel::where("ulid", $item->ulid)->update([
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

  function pushDataRemoveItemBeforeSavePackage()
  {
    $datetime = now()->toISOString(true);
    try {
      $token = $this->requireBranchToken();
      $list_data = TrRemoveItemBeforeSavePackageModel::where('sync_at', null)->get();

      foreach ($list_data as $item) {
        if ($item->sync_at == null) {
          $item->sync_at = $datetime;
        }
      }

      $response = Http::asJson()->withToken($token)->withHeaders(BranchAuthHeaderService::build())->connectTimeout(5)->timeout(30)->withOptions(['verify' => config('services.http_verify_ssl')])->post($this->endpoint . "/pos/push/data_remove_item_before_save_package", [
        "list_remove_item_before_save_package" => $list_data
      ]);

      if ($response->json('code') == 0) {
        DB::beginTransaction();
        foreach ($list_data as $item) {
          TrRemoveItemBeforeSavePackageModel::where("ulid", $item->ulid)->update([
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

  // pushDataSoldOut: tombol manual "Push" di Menu Management page (2026-10-09) -- BEDA POLA
  // dari fungsi push lain di atas (bukan sync_at-based/append-only). mr_item TIDAK punya kolom
  // sync_at, dan sold out itu STATE yang naik-turun (bukan histori transaksi) -- jadi tiap klik
  // kirim SNAPSHOT PENUH item_id yang LAGI flag_soldout=true saat ini, bukan cuma yang "baru
  // berubah". ERP (lewat APIANDORDER /pos/push/sold_out) REPLACE TOTAL baris branch ini di
  // master_item_sold_out (DELETE+INSERT) -- jadi item yang baru di-unsoldout-kan otomatis
  // kehapus dari ERP di push berikutnya, gak perlu kirim daftar "hapus" terpisah.
  //
  // item_id yang dikirim = mr_item.id (item FISIK, SAMA PERSIS master_item.id di ERP -- lihat
  // SetupServices::getMasterItem(), upsert langsung by id tanpa remap) -- BUKAN
  // item_conv_detail_id/mr_item_conv.id (itu level varian/pricing, flag_soldout nempel di item
  // fisiknya, bukan di variannya).
  function pushDataSoldOut()
  {
    try {
      $branch = BranchModel::first();
      if (!$branch || !$branch->token) {
        throw new \Exception('local branch data not found');
      }
      $token = $branch->token;

      $itemIds = MasterItemModel::where('flag_soldout', true)->pluck('id');

      $response = Http::asJson()->withToken($token)->withHeaders(BranchAuthHeaderService::build())->connectTimeout(5)->timeout(30)->withOptions(['verify' => config('services.http_verify_ssl')])->post($this->endpoint . "/pos/push/sold_out", [
        "branch_id" => $branch->id,
        "item_ids" => $itemIds,
      ]);

      if ($response->json('code') == 0) {
        return 'success';
      } else {
        throw new \Exception($response->json('message'));
      }
    } catch (\Throwable $e) {
      throw $e;
    }
  }
}
