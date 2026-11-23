<?php

namespace App\Services;

use App\Models\BranchModel;
use App\Models\MasterMemberModel;
use App\Models\MasterPaymentMethodModel;
use App\Models\TableSectionModel;
use App\Models\TrOrderDetailModel;
use App\Models\TrOrderDetailPackageModel;
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

      // Guard payment type OTHER gak boleh digabung (2026-11-17) -- kalau payment_detail lebih
      // dari 1 baris (split payment) DAN salah satu baris itu payment_method_id-nya tergolong
      // payment_method_type_id = 4 (OTHER), tolak total SEBELUM payment_number di-generate/
      // transaksi dibuka -- OTHER harus berdiri sendiri (1 baris doang), gak boleh split sama
      // metode lain apa pun.
      if (count($datajson->payment_detail) > 1) {
        foreach ($datajson->payment_detail as $item) {
          $paymentMethod = MasterPaymentMethodModel::find($item['payment_method_id']);
          if ($paymentMethod && (int) $paymentMethod->payment_method_type_id === 4) {
            $response->message = "payment method type other harus 1 tidak boleh digabung";
            return $response;
          }
        }
      }

      // Guard payment type OTHER gak boleh dipakai kalau ada item yang ke-apply discount
      // (2026-11-17) -- walau cuma 1 baris (lolos guard di atas), kalau salah satu baris
      // payment_detail-nya type OTHER, DAN order ini punya item (tr_order_detail) ATAU
      // sub-item package (tr_order_detail_package) yang discount_amount > 0, tolak total.
      // Dicek SEBELUM payment_number di-generate/transaksi dibuka, sama pola guard di atas.
      //
      // $isOtherPayment (2026-11-17, BARU) -- ke-set true di loop yang SAMA (guard OTHER di
      // atas udah mastiin payment_detail PASTI cuma 1 baris kalau ada OTHER, jadi lookup di
      // sini aman dipakai buat nentuin status order di bawah -- TANPA lookup ulang).
      $isOtherPayment = false;
      foreach ($datajson->payment_detail as $item) {
        $paymentMethod = MasterPaymentMethodModel::find($item['payment_method_id']);
        if ($paymentMethod && (int) $paymentMethod->payment_method_type_id === 4) {
          $hasDiscountItem = TrOrderDetailModel::where('order_number', $datajson->order_number)
            ->where('discount_amount', '>', 0)
            ->exists();
          $hasDiscountPackage = TrOrderDetailPackageModel::whereIn(
            'tr_order_detail_ulid',
            TrOrderDetailModel::where('order_number', $datajson->order_number)->pluck('ulid')
          )->where('discount_amount', '>', 0)->exists();

          if ($hasDiscountItem || $hasDiscountPackage) {
            $response->message = "payment method type other cannot be applied to a discounted order";
            return $response;
          }
          $isOtherPayment = true;
          break;
        }
      }

      $payment_number = self::GenerateOrderNumber($dataorder_current->terminal_id);

      DB::beginTransaction();

      // member_id SENGAJA TIDAK ditulis di sini lagi (2026-11-17, keputusan user) -- member_id
      // order sepenuhnya cuma dari SaveOrder() (dipilih di halaman Order), bukan bisa
      // diubah/dipilih lagi pas Save Payment. Kalau client tetap kirim member_id di body,
      // field itu diabaikan sepenuhnya.
      // status order 'other_cost' (2026-11-17, BARU) -- MENGGANTIKAN 'paid' kalau payment-nya
      // type OTHER (bukan status TAMBAHAN di luar paid). Order yang dibayar pakai OTHER TIDAK
      // PERNAH tercatat status=paid sama sekali. Konsekuensi: modul lain yang filter
      // status='paid' (laporan sales, EndDay, dll) TIDAK akan ikut menghitung order ini --
      // keputusan sadar user, scope perubahan ini CUMA status order, modul lain MENYUSUL
      // terpisah kalau memang perlu nanti.
      TrOrderModel::where('order_number', $datajson->order_number)->update([
        'payment_number' => $payment_number,
        'status' => $isOtherPayment ? 'other_cost' : 'paid',
        'order_out' => now(),
        'payment_at' => now(),
        'sync_at' => null,
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
        // station_id (2026-11-12, pengganti mr_setting.default_station): Kiosk/payment-gateway
        // callback manggil SavePayment() pakai $fakeRequest kosong (TANPA bearer token, lihat
        // PaymentGatewayServices::confirmPayment()) -- gak ada session kasir buat order_source
        // ini, jadi WAJIB dari $terminal->receipt_station_id (sudah di tangan, baris di atas).
        // POS (ada bearer token kasir asli) tetap dari session yang SEDANG LOGIN, bisa beda dari
        // $terminal yang tercatat di order (reprint/cetak dari terminal lain tetap keluar di
        // printer tempat kasir berdiri sekarang, lihat PrintServices::RetrieveReceiptStationIdFromSession()).
        $station_id = $dataorder_current->order_source === 'kiosk'
          ? ($terminal->receipt_station_id ?? null)
          : PrintServices::RetrieveReceiptStationIdFromSession($datajson);
        // Non-blocking (2026-11-23) -- lihat catatan lengkap di OrderServices::SaveOrder().
        PrintServices::dispatchPrintJob($datajson, 'payment', ['order_number' => $datajson->order_number, 'station_id' => $station_id ?? '']);
      }

      // order Kiosk sengaja gak nge-print kitchen pas SaveOrder (nunggu kepastian bayar dulu,
      // lihat OrderServices::SaveOrder()) -- baru di sini, abis payment sukses. Ini SELALU
      // jalan buat kiosk, gak kepengaruh flag_printer_frontend -- dapur tetap butuh tau apa
      // yang harus dimasak, terlepas dari cara struk customer di-print.
      // print_checker_on (2026-11-04): SELAIN Kiosk, non-Kiosk yang table section-nya di-set
      // 'save_payment' juga nge-print checker di sini (bukan di SaveOrder(), lihat
      // OrderServices::SaveOrder()) -- 2 kondisi independen, digabung OR.
      $tablesection_current_payment = TableSectionModel::where('id', $dataorder_current->table_section_id)->first();
      if (
        $dataorder_current->order_source === 'kiosk'
        || ($tablesection_current_payment && $tablesection_current_payment->print_checker_on === 'save_payment')
      ) {
        // Non-blocking (2026-11-23) -- lihat catatan lengkap di OrderServices::SaveOrder().
        PrintServices::dispatchPrintJob($datajson, 'table-checker2', ['order_number' => $datajson->order_number, 'table_section_id' => $dataorder_current->table_section_id]);
        PrintServices::dispatchPrintJob($datajson, 'main-checker2', ['order_number' => $datajson->order_number, 'table_section_id' => $dataorder_current->table_section_id]);
        PrintServices::dispatchPrintJob($datajson, 'priparation-station', ['order_number' => $datajson->order_number, 'table_section_id' => $dataorder_current->table_section_id]);

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
        throw new \Exception("order number $datajson->order_number not found!");
      }

      // Guard order status='other_cost' TIDAK BISA diedit sama sekali (2026-11-17) -- dicek
      // PALING AWAL (sebelum guard payment_number/total_billing lain), order yang statusnya
      // other_cost (dibayar pakai payment type OTHER, lihat SavePayment()) gak bisa diubah
      // payment-nya lewat endpoint ini, titik -- gak ada skenario valid buat edit order jenis
      // ini balik lagi.
      if ($dataorder->status === 'other_cost') {
        throw new \Exception("order with other cost status cannot be edited");
      }

      if ($dataorder->payment_number == null || $dataorder->payment_number == '') {
        throw new \Exception("order number $datajson->order_number has never been paid!");
      }

      Log::info($dataorder->total_billing);
      Log::info("" . $datajson->total_payment);

      if ($dataorder->total_billing != $datajson->total_payment) {
        throw new \Exception("Payment amount does not match the order!");
      }

      // Guard payment type OTHER gak boleh digabung (2026-11-17) -- sama pola persis
      // SavePayment(), dipasang juga di sini biar Edit Payment gak bisa dipakai buat
      // "ngelewatin" guard yang udah ada di Save Payment.
      if (count($datajson->payment_detail) > 1) {
        foreach ($datajson->payment_detail as $item) {
          $paymentMethod = MasterPaymentMethodModel::find($item['payment_method_id']);
          if ($paymentMethod && (int) $paymentMethod->payment_method_type_id === 4) {
            throw new \Exception("payment method type other harus 1 tidak boleh digabung");
          }
        }
      }

      // Guard payment type OTHER gak boleh dipakai kalau ada item yang ke-apply discount
      // (2026-11-17) -- sama pola persis SavePayment().
      foreach ($datajson->payment_detail as $item) {
        $paymentMethod = MasterPaymentMethodModel::find($item['payment_method_id']);
        if ($paymentMethod && (int) $paymentMethod->payment_method_type_id === 4) {
          $hasDiscountItem = TrOrderDetailModel::where('order_number', $datajson->order_number)
            ->where('discount_amount', '>', 0)
            ->exists();
          $hasDiscountPackage = TrOrderDetailPackageModel::whereIn(
            'tr_order_detail_ulid',
            TrOrderDetailModel::where('order_number', $datajson->order_number)->pluck('ulid')
          )->where('discount_amount', '>', 0)->exists();

          if ($hasDiscountItem || $hasDiscountPackage) {
            throw new \Exception("payment method type other cannot be applied to a discounted order");
          }
          break;
        }
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
        throw new \Exception("order number $order_number not found!");
      }
      if ($dataorder->payment_number == null || $dataorder->payment_number == '') {
        throw new \Exception("order number $order_number has never been paid!");
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
