<?php

namespace App\Http\Controllers;

use App\Models\SettingModel;
use App\Models\StationModel;
use App\Services\PrintServices;
use App\Services\SettingServices;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SettingController extends Controller
{
    //
    // save adalah endpoint tunggal untuk semua tab di halaman Setting — body dipisah per
    // section (general_setting, kiosk, dst nanti), tiap section opsional, cuma yang
    // dikirim aja yang diproses.
    public function save(Request $request)
    {
        try {
            $messages = [];

            if ($request->has('general_setting')) {
                $messages[] = SettingServices::Save($request->input('general_setting'));
            }

            if ($request->has('terminal')) {
                $messages[] = SettingServices::SaveTerminalSetting($request->input('terminal'));
            }

            if (count($messages) === 0) {
                return response()->json([
                    'code' => 100,
                    'message' => 'No section was sent (general_setting/terminal).',
                ]);
            }

            return response()->json([
                'code' => 0,
                "message" => "Success"
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'code' => 100,
                "message" => $e->getMessage()
            ]);
        }
    }

    // load -- dipakai GLOBAL STATE (globalstore.js, di-bootstrap guard router
    // CekStatusLogin setiap masuk area dashboard), dibaca FLAT (bukan section) di banyak
    // komponen (contoh: Navbar.vue -> globalstore.setting?.use_customer_display). JANGAN
    // diubah strukturnya jadi nested/section-based -- itu akan merusak semua pemakai
    // globalstore.setting di seluruh app. Kalau butuh bentuk section (general_setting +
    // terminal) simetris dengan save(), pakai GET /setting/detail (loadDetail()) di bawah,
    // khusus SettingPage.vue.
    public function load(Request $request)
    {
        try {
            $data = SettingModel::first()->makeHidden(['customer_display_left', 'customer_display_top', 'customer_display_name']);

            return response()->json([
                'code' => 0,
                "data" => $data,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'code' => 100,
                "message" => $e->getMessage()
            ]);
        }
    }

    // loadDetail (GET /setting/detail) -- khusus halaman Setting (SettingPage.vue), bentuk
    // response-nya SIMETRIS dengan body save() (section general_setting + terminal), supaya
    // 1 call bisa sekaligus isi form general setting DAN tabel Terminal Setting (sebelumnya
    // 2 request terpisah: load() + GetTerminalList()/master/terminal-list). TIDAK dipakai
    // modul lain -- beda dari load() di atas yang dipakai global state.
    public function loadDetail(Request $request)
    {
        try {
            $general_setting = SettingModel::first()->makeHidden(['customer_display_left', 'customer_display_top', 'customer_display_name']);

            // query sama seperti MasterController::GetTerminalList() (/master/terminal-list)
            // -- sengaja diduplikasi di sini (bukan reuse controller lain) biar section ini
            // independen, gampang dikembangkan terpisah dari endpoint master generic.
            $terminal = DB::table('mr_terminal as t')
                ->leftJoin('mr_pos_type as pt', 'pt.id', '=', 't.pos_type_id')
                ->where('t.is_active', true)
                ->select('t.*', 'pt.name as pos_type_name', 'pt.device_type as pos_type_device_type')
                ->get();

            return response()->json([
                'code' => 0,
                'data' => [
                    'general_setting' => $general_setting,
                    'terminal' => $terminal,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'code' => 100,
                "message" => $e->getMessage()
            ]);
        }
    }

    public function testPrintAll()
    {
        try {
            $stations = StationModel::all();
            $results = [];
            foreach ($stations as $station) {
                $msg = PrintServices::PrintTest($station->id);
                $results[] = [
                    'station_id'   => $station->id,
                    'station_name' => $station->name,
                    'printer_name' => $station->printer_name,
                    'result'       => $msg,
                ];
            }
            return response()->json(['code' => 0, 'data' => $results]);
        } catch (\Throwable $e) {
            return response()->json(['code' => 100, 'message' => $e->getMessage()]);
        }
    }

    public function testPrint(int $station_id)
    {
        try {
            $result = PrintServices::PrintTest($station_id);
            $code = $result === 'OK' ? 0 : 100;
            return response()->json(['code' => $code, 'message' => $result]);
        } catch (\Throwable $e) {
            return response()->json(['code' => 100, 'message' => $e->getMessage()]);
        }
    }

}
