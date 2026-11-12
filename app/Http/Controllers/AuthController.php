<?php

namespace App\Http\Controllers;

use App\Models\MasterUserModel;
use App\Models\SessionModel;
use App\Models\TerminalModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Throwable;

class AuthController extends Controller
{
    //
    public function login(Request $request)
    {
        try {

            // terminal_id wajib sejak 2026-11-12 -- dipakai PrintServices::RetrieveReceiptStationIdFromSession()
            // buat resolve printer struk ikut terminal TEMPAT KASIR LOGIN, bukan lagi mr_setting.default_station
            // global (lihat PrintServices.php). Divalidasi exists + is_active, sama pola resolveWorkerTerminal().
            $terminal_id = $request->input('terminal_id');
            if (!$terminal_id) {
                return response()->json([
                    "code" => 100,
                    "message" => "terminal_id is required",
                ]);
            }
            $terminal = TerminalModel::where('id', $terminal_id)->where('is_active', true)->first();
            if (!$terminal) {
                return response()->json([
                    "code" => 100,
                    "message" => "terminal not found or inactive",
                ]);
            }

            $user = MasterUserModel::where('sandi', $request->input('pin'))->first();
            $session_id = Str::uuid()->toString();

            if (!$user) {
                return response()->json([
                    "code" => 100,
                    "message" => "Incorrect password!",
                ]);
            }

            // terminal_id BUKAN kolom mr_user -- ditambahkan manual ke array sebelum di-encode,
            // tidak otomatis ikut seperti field mr_user lainnya.
            $sessionData = $user->toArray();
            $sessionData['terminal_id'] = (int) $terminal_id;

            SessionModel::create([
                "session_id" => $session_id,
                "data" => json_encode($sessionData)
            ]);

            // $cookie = cookie("sudo_pos_session", $session_id);


            return response()->json([
                "code" => 0,
                "message" => "success login!",
                "data" => [
                    "token" => $session_id,
                ]
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                "code" => 100,
                "message" => $e->getMessage(),
            ]);
        }
    }

    public function logout(Request $request)
    {
        try {
            $token = $request->bearerToken();

            SessionModel::where("session_id", $token)->delete();

            return response()->json([
                "code" => 0,
                "message" => "success logout!",
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                "code" => 100,
                "message" => $e->getMessage(),
            ]);
        }
    }

    public function info(Request $request)
    {
        try {

            $token = $request->bearerToken();

            $data = SessionModel::where("session_id", $token)->first();
            $data_user = json_decode($data->data);

            return response()->json([
                "code" => 0,
                "data" => $data_user
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                "code" => 100,
                "message" => $e->getMessage()
            ]);
        }
    }
}
