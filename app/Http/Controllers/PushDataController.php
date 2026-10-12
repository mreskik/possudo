<?php

namespace App\Http\Controllers;

use App\Services\PushDataServices;
use Illuminate\Http\Request;

class PushDataController extends Controller
{
    //
    function PushDataOrder(Request $request)
    {


        try {
            $services = new PushDataServices;

            $result = $services->pushDataOrder();

            return response()->json([
                "code" => 0,
                "data" => $result
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                "code" => 100,
                "message" => $e->getMessage()
            ]);
        }
    }

    function PushDataOrderDetail(Request $request)
    {


        try {
            $services = new PushDataServices;

            $result = $services->pushDataOrderDetail();

            return response()->json([
                "code" => 0,
                "data" => $result
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                "code" => 100,
                "message" => $e->getMessage()
            ]);
        }
    }

    function PushDataOrderDetailPackage(Request $request)
    {


        try {
            $services = new PushDataServices;

            $result = $services->pushDataOrderDetailPackage();

            return response()->json([
                "code" => 0,
                "data" => $result
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                "code" => 100,
                "message" => $e->getMessage()
            ]);
        }
    }

    function PushDataOrderPayment(Request $request)
    {


        try {
            $services = new PushDataServices;

            $result = $services->PushDataOrderPayment();

            return response()->json([
                "code" => 0,
                "data" => $result
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                "code" => 100,
                "message" => $e->getMessage()
            ]);
        }
    }

    function PushDataDayShift(Request $request)
    {
        try {
            $services = new PushDataServices;

            $result = $services->pushDataDayShift();

            return response()->json([
                "code" => 0,
                "data" => $result
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                "code" => 100,
                "message" => $e->getMessage()
            ]);
        }
    }

    function PushDataDayShiftDetail(Request $request)
    {
        try {
            $services = new PushDataServices;

            $result = $services->pushDataDayShiftDetail();

            return response()->json([
                "code" => 0,
                "data" => $result
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                "code" => 100,
                "message" => $e->getMessage()
            ]);
        }
    }

    function PushDataRemoveItemBeforeSave(Request $request)
    {
        try {
            $services = new PushDataServices;

            $result = $services->pushDataRemoveItemBeforeSave();

            return response()->json([
                "code" => 0,
                "data" => $result
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                "code" => 100,
                "message" => $e->getMessage()
            ]);
        }
    }

    function PushDataRemoveItemBeforeSavePackage(Request $request)
    {
        try {
            $services = new PushDataServices;

            $result = $services->pushDataRemoveItemBeforeSavePackage();

            return response()->json([
                "code" => 0,
                "data" => $result
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                "code" => 100,
                "message" => $e->getMessage()
            ]);
        }
    }

    // PushOrderSyncAll (2026-09-25): push GLOBAL 4 tabel order sekaligus (data-order, data-order-
    // detail, data-order-detail-package, data-order-payment) dalam 1 request -- TANPA parameter
    // order_number, sama semantik kayak sync:push (filter WHERE sync_at IS NULL di tiap
    // pushData*() service, bukan push 1 order tertentu). Dibuat biar caller (mis. tombol Send WA
    // di POS Vue nanti, belum di-wire di sini) bisa trigger "sync sekarang" tanpa nunggu job
    // sync:push (default 120 detik) ATAU nunggu EndDay, mengurangi risiko link struk publik
    // 404 (pos_order sudocore2 belum ke-sync) -- tapi endpoint ini SENDIRI gak nge-filter/
    // nge-tunggu 1 order spesifik, ttap "sync semua yang pending" apa adanya.
    //
    // Urutan SAMA persis kayak EndDay() (DayShiftServices.php) dan sync:push (SyncPush.php):
    // order dulu, baru detail/package/payment turunannya -- push order gagal duluan gak masuk
    // akal lanjut push detail-nya (foreign key pos_order_detail.order_number ngerujuk ke situ).
    // Tiap langkah GAGAL LANGSUNG DIHENTIKAN (bukan try/catch per-langkah kayak EndDay()) --
    // endpoint ini dipanggil on-demand sinkron, caller PERLU tau persis langkah mana yang gagal
    // buat mutusin retry, beda dari EndDay() yang push-nya cuma "usaha terbaik" background.
    function PushOrderSyncAll(Request $request)
    {
        try {
            $services = new PushDataServices;

            $result = [
                'data_order' => $services->pushDataOrder(),
                'data_order_detail' => $services->pushDataOrderDetail(),
                'data_order_detail_package' => $services->pushDataOrderDetailPackage(),
                'data_order_payment' => $services->PushDataOrderPayment(),
            ];

            return response()->json([
                "code" => 0,
                "data" => $result
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                "code" => 100,
                "message" => $e->getMessage()
            ]);
        }
    }

    // PushDataSoldOut: tombol manual "Push" di Menu Management page -- lihat komentar
    // PushDataServices::pushDataSoldOut() buat detail snapshot-replace-nya.
    function PushDataSoldOut(Request $request)
    {
        try {
            $services = new PushDataServices;

            $result = $services->pushDataSoldOut();

            return response()->json([
                "code" => 0,
                "data" => $result
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                "code" => 100,
                "message" => $e->getMessage()
            ]);
        }
    }
}
