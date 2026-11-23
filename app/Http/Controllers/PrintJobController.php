<?php

namespace App\Http\Controllers;

use App\Services\PrintServices;
use Illuminate\Http\Request;

// PrintJobController: endpoint internal (lihat routes/api.php group 'internal/print', middleware
// 'restrict.localhost') yang NYATA MENJALANKAN operasi print fisik -- dipanggil non-blocking lewat
// HTTP self-call dari PrintServices::dispatchPrintJob() (OrderServices::SaveOrder() dkk kirim
// request ke sini dengan timeout PENDEK, lalu lanjut tanpa nunggu).
//
// ignore_user_abort(true) + set_time_limit(0) WAJIB di baris PERTAMA tiap method -- supaya proses
// print TETAP jalan sampai selesai WALAU request pemanggil udah "pergi" (timeout di sisi
// dispatchPrintJob()). Tanpa ini, PHP defaultnya BISA menghentikan eksekusi di tengah begitu
// mendeteksi koneksi client putus -- print bisa terpotong (lihat PrintServices::dispatchPrintJob()
// buat alasan arsitektur lengkap).
//
// Tiap method murni WRAPPER TIPIS ke fungsi PrintServices::PrintXxx() yang SUDAH ADA, TANPA logic
// tambahan -- sama prinsip kayak 11 Artisan Command versi proc_open yang digantikan controller ini
// (DIHAPUS 2026-11-23, lihat riwayat -- proc_open bikin Apache mpm_winnt Windows error "pipe has
// been ended" krn Windows handle inheritance, HTTP self-call ini gak spawn proses OS sama sekali).
//
// PrintTest SENGAJA TIDAK ADA DI SINI -- tetap synchronous di SettingController (return string
// hasil test yang harus langsung ditunjukkan ke user, non-blocking bikin user gak tau hasilnya).
class PrintJobController extends Controller
{
    public function tableChecker2(Request $request)
    {
        ignore_user_abort(true);
        set_time_limit(0);

        PrintServices::PrintTableChecker2(
            (int) $request->input('table_section_id'),
            (string) $request->input('order_number'),
            (bool) $request->input('test', false)
        );

        return response()->json(['code' => 0]);
    }

    public function mainChecker2(Request $request)
    {
        ignore_user_abort(true);
        set_time_limit(0);

        PrintServices::PrintMainChecker2(
            (int) $request->input('table_section_id'),
            (string) $request->input('order_number'),
            (bool) $request->input('test', false)
        );

        return response()->json(['code' => 0]);
    }

    public function priparationStation(Request $request)
    {
        ignore_user_abort(true);
        set_time_limit(0);

        PrintServices::PrintPriparationStation(
            (int) $request->input('table_section_id'),
            (string) $request->input('order_number'),
            (bool) $request->input('test', false)
        );

        return response()->json(['code' => 0]);
    }

    public function payment(Request $request)
    {
        ignore_user_abort(true);
        set_time_limit(0);

        $stationId = $request->input('station_id');

        PrintServices::PrintPayment(
            (string) $request->input('order_number'),
            ($stationId === null || $stationId === '') ? null : (int) $stationId
        );

        return response()->json(['code' => 0]);
    }

    public function topup(Request $request)
    {
        ignore_user_abort(true);
        set_time_limit(0);

        PrintServices::PrintTopup(
            (string) $request->input('reference_number'),
            (int) $request->input('station_id')
        );

        return response()->json(['code' => 0]);
    }

    public function bill(Request $request)
    {
        ignore_user_abort(true);
        set_time_limit(0);

        $stationId = $request->input('station_id');

        PrintServices::PrintBill(
            (string) $request->input('order_number'),
            ($stationId === null || $stationId === '') ? null : (int) $stationId
        );

        return response()->json(['code' => 0]);
    }

    public function endDay(Request $request)
    {
        ignore_user_abort(true);
        set_time_limit(0);

        $stationId = $request->input('station_id');

        PrintServices::PrintEndDay(
            $request->input('dayshift_ulid'),
            null,
            ($stationId === null || $stationId === '') ? null : (int) $stationId
        );

        return response()->json(['code' => 0]);
    }

    public function reportDaysift(Request $request)
    {
        ignore_user_abort(true);
        set_time_limit(0);

        $stationId = $request->input('station_id');
        $cashierPrintFullname = $request->input('cashier_print_fullname');

        PrintServices::PrintReportDaysift(
            $request->input('dayshift_ulid'),
            null,
            ($stationId === null || $stationId === '') ? null : (int) $stationId,
            ($cashierPrintFullname === null || $cashierPrintFullname === '') ? null : (string) $cashierPrintFullname
        );

        return response()->json(['code' => 0]);
    }

    public function currentShift(Request $request)
    {
        ignore_user_abort(true);
        set_time_limit(0);

        $stationId = $request->input('station_id');
        $userFullname = $request->input('user_fullname');

        PrintServices::PrintCurrentShift(
            $request->input('dayshift_ulid'),
            null,
            ($stationId === null || $stationId === '') ? null : (int) $stationId,
            ($userFullname === null || $userFullname === '') ? null : (string) $userFullname
        );

        return response()->json(['code' => 0]);
    }

    public function perShiftV2(Request $request)
    {
        ignore_user_abort(true);
        set_time_limit(0);

        $stationId = $request->input('station_id');

        PrintServices::PrintPerShiftv2(
            $request->input('dayshift_detail_ulid'),
            null,
            ($stationId === null || $stationId === '') ? null : (int) $stationId
        );

        return response()->json(['code' => 0]);
    }
}
