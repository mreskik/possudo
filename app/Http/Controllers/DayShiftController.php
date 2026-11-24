<?php

namespace App\Http\Controllers;

use App\Models\DaySiftModel;
use App\Models\DayShiftDetailModel;
use App\Models\TrOrderModel;
use App\Services\DayShiftServices;
use App\Services\PrintServices;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DayShiftController extends Controller
{
    //
    function CurrentDay(Request $request)
    {
        try {
            $data = DayShiftServices::GetDayShift();
            return response()->json([
                "code" => 0,
                "data" => $data
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                "code" => 0,
                "data" => $e->getMessage()
            ]);
        }
    }

    function StartDay(Request $request)
    {
        try {
            $start_cash = $request->input("start_cash");
            //belum filter

            $data = DayShiftServices::StartDay($start_cash, $request);

            return response()->json([
                "code" => 0,
                "message" => $data
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                "code" => 0,
                "message" => $e->getMessage()
            ]);
        }
    }

    function Endshift(Request $request)
    {
        try {

            $dayshift_ulid = $request->dayshift_ulid;
            //belum filter
            $data = DayShiftServices::EndShift($dayshift_ulid, $request);
            return response()->json([
                "code" => 0,
                "message" => $data
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                "code" => 0,
                "message" => $e->getMessage()
            ]);
        }
    }
    function EndDay(Request $request)
    {
        try {
            // Pending order guard (2026-11-17) -- diadopsi dari pendingOrder() (reuse query yang
            // sama, status='pending' global, TIDAK difilter per dayshift_ulid, sama persis
            // behavior endpoint GET pending-order yang sudah ada). Dicek SEBELUM panggil
            // DayShiftServices::EndDay() sama sekali -- kalau ada order pending, End Day ditolak
            // total (gak masuk ke tahap VALIDASI/SAVE/PUSH/JURNAL apapun), FE dikasih balik daftar
            // order pending-nya langsung (bukan cuma pesan error) biar bisa ditampilkan ke kasir.
            $pendingOrders = TrOrderModel::where('status', 'pending')->get();
            if ($pendingOrders->isNotEmpty()) {
                return response()->json([
                    "code" => 100,
                    "message" => "cannot end day because there are still pending orders, please complete or cancel them first",
                    "data" => $pendingOrders
                ]);
            }

            $result = DayShiftServices::EndDay($request);

            return response()->json([
                "code" => 0,
                "message" => $result
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                "code" => 100,
                "message" => $e->getMessage()
            ]);
        }
    }

    function ListDayShiftDetail(Request $request)
    {
        try {
            $dayshift_ulid = $request->dayshift_ulid;
            $data = DayShiftDetailModel::where('dayshift_ulid', $dayshift_ulid)->get();

            return response()->json([
                "code" => 0,
                "data" => $data
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                "code" => 100,
                "message" => $e->getMessage()
            ]);
        }
    }

    function Report(Request $request)
    {
        try {

            $data = DayShiftServices::GetReportCurrentShiftforTampilan($request->dayshift_ulid);

            return response()->json([
                "code" => 0,
                "data" => $data
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                "code" => 0,
                "message" => $e->getMessage()
            ]);
        }
    }

    // ReportV2 -- struktur response key-value, 1:1 sama kayak section yang dicetak
    // PrintServices::PrintEndDay() (SALES SUMMARY/SALES TYPE SUMMARY/ORDER SOURCE SUMMARY/ITEM
    // CATEGORY SUMMARY/STAFF SALES SUMMARY/PAYMENT METHOD SUMMARY/CASH FLOW SUMMARY).
    // DEPRECATED (2026-11-24) -- usang & kurang lengkap, PAKAI ReportV3() SAJA. sales_summary V2 masih
    // baca recap lewat index posisi (bergeser) dan belum punya field baru. Dibiarkan ada (belum dihapus)
    // karena FE lama masih bisa memanggilnya. Lihat DOKUMENTASI API/POS/POS/DAYSHIFT/LIST AND DETAIL.md.
    function ReportV2(Request $request)
    {
        try {

            $data = DayShiftServices::GetReportSummaryV2($request->dayshift_ulid);

            return response()->json([
                "code" => 0,
                "data" => $data
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                "code" => 0,
                "message" => $e->getMessage()
            ]);
        }
    }

    // ReportV3 (2026-11-13) -- SAMA PERSIS ReportV2, cuma minus "sales_by_menu" & "sales_by_table"
    // dari response (lihat DayShiftServices::GetReportSummaryV3()).
    function ReportV3(Request $request)
    {
        try {

            $data = DayShiftServices::GetReportSummaryV3($request->dayshift_ulid);

            return response()->json([
                "code" => 0,
                "data" => $data
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                "code" => 0,
                "message" => $e->getMessage()
            ]);
        }
    }

    function DayShiftList(Request $request)
    {
        try {
            $dayshiftlist = DayShiftServices::GetDayshiftList();

            return response()->json([
                "code" => 0,
                "data" => $dayshiftlist
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                "code" => 100,
                "message" => $e->getMessage()
            ]);
        }
    }

    function printReport(Request $request)
    {
        try {
            $dayshift_ulid = $request->dayshift_ulid;
            // PrintServices::PrintReport($dayshift_ulid);
            return response()->json([
                "code" => 0,
                "message" => "ashiappp"
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                "code" => 100,
                "message" => $e->getMessage()
            ]);
        }
    }

    function printReportByShift(Request $request)
    {
        try {
            $dayshift_detail_ulid = $request->dayshift_detail_ulid;
            // Non-blocking (2026-11-23) -- station_id di-resolve DI SINI (masih punya $request
            // HTTP asli) SEBELUM spawn command, lihat catatan lengkap di OrderServices::SaveOrder().
            $station_id = PrintServices::RetrieveReceiptStationIdFromSession($request);
            PrintServices::dispatchPrintJob($request, 'per-shift-v2', ['dayshift_detail_ulid' => $dayshift_detail_ulid, 'station_id' => $station_id ?? '']);
            return response()->json([
                "code" => 0,
                "message" => "ashiappp"
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                "code" => 100,
                "message" => $e->getMessage()
            ]);
        }
    }

    function printCurrentShiftReport(Request $request)
    {
        try {
            $dayshift_ulid = $request->dayshift_ulid;
            // Non-blocking (2026-11-23) -- lihat catatan di printReportByShift() di atas.
            $station_id = PrintServices::RetrieveReceiptStationIdFromSession($request);
            $user_fullname = PrintServices::getLoggedInUserFullname($request);
            PrintServices::dispatchPrintJob($request, 'current-shift', ['dayshift_ulid' => $dayshift_ulid, 'station_id' => $station_id ?? '', 'user_fullname' => $user_fullname ?? '']);
            return response()->json([
                "code" => 0,
                "message" => "success print current shift report"
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                "code" => 100,
                "message" => $e->getMessage()
            ]);
        }
    }

    // printEndayReport "(On Running)" (2026-11-23, REFACTOR) -- endpoint ini SEKARANG KHUSUS
    // dayshift yang MASIH BERJALAN (dayout_time NULL), DITOLAK kalau dayshift udah di-End Day --
    // beda dari printReportDaysift() (endpoint lain, TETAP bisa buat dayshift mana pun, aktif
    // atau udah tutup, TANPA guard). Secara internal manggil PrintReportDaysift() (BUKAN
    // PrintEndDay() lagi) -- struk 3-baris-kasir (Start/End/Print), bukan 1-baris.
    function printEndayReport(Request $request)
    {
        try {
            $dayshift_ulid = $request->dayshift_ulid;

            $current_dayshift = DaySiftModel::where('ulid', $dayshift_ulid)->first();
            if (!$current_dayshift) {
                return response()->json([
                    "code" => 100,
                    "message" => "dayshift not found!"
                ]);
            }
            if ($current_dayshift->dayout_time !== null) {
                return response()->json([
                    "code" => 100,
                    "message" => "dayshift sudah di-End Day, gunakan print-report-dayshift untuk cetak ulang!"
                ]);
            }

            // Non-blocking (2026-11-23) -- lihat catatan di printReportByShift() di atas.
            $station_id = PrintServices::RetrieveReceiptStationIdFromSession($request);
            $cashier_print_fullname = PrintServices::getLoggedInUserFullname($request);
            PrintServices::dispatchPrintJob($request, 'report-dayshift', ['dayshift_ulid' => $dayshift_ulid, 'station_id' => $station_id ?? '', 'cashier_print_fullname' => $cashier_print_fullname ?? '']);
            return response()->json([
                "code" => 0,
                "message" => "success print endday report"
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                "code" => 100,
                "message" => $e->getMessage()
            ]);
        }
    }

    function printReportDaysift(Request $request)
    {
        try {
            $dayshift_ulid = $request->dayshift_ulid;
            // Non-blocking (2026-11-23) -- lihat catatan di printReportByShift() di atas.
            $station_id = PrintServices::RetrieveReceiptStationIdFromSession($request);
            $cashier_print_fullname = PrintServices::getLoggedInUserFullname($request);
            PrintServices::dispatchPrintJob($request, 'report-dayshift', ['dayshift_ulid' => $dayshift_ulid, 'station_id' => $station_id ?? '', 'cashier_print_fullname' => $cashier_print_fullname ?? '']);
            return response()->json([
                "code" => 0,
                "message" => "success print report dayshift"
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                "code" => 100,
                "message" => $e->getMessage()
            ]);
        }
    }

    function pendingOrder()
    {
        try {
            $data = TrOrderModel::where('status', 'pending')->get();

            return response()->json([
                'code' => 0,
                'data' => $data
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                "code" => 100,
                "message" => $e->getMessage()
            ]);
        }
    }
}
