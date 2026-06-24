<?php

namespace App\Http\Controllers;

use App\Models\MasterUserModel;
use App\Models\SessionModel;
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

            $sandi = md5($request->input('pin'));
            $user = MasterUserModel::where('sandi', $sandi)->first();
            $session_id = Str::uuid()->toString();

            if (!$user) {
                return response()->json([
                    "code" => 100,
                    "message" => "Sandi salah !",
                ]);
            }

            SessionModel::create([
                "session_id" => $session_id,
                "data" => json_encode($user)
            ]);

            $cookie = cookie("sudo_pos_session", $session_id);

            return response()->json([
                "code" => 0,
                "message" => "success login!",
                "data" => $user
            ])->withCookie($cookie);
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
            $session_id = Cookie::get("sudo_pos_session");

            SessionModel::where("session_id", $session_id)->delete();

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

            $session_id = Cookie::get("sudo_pos_session");

            $data = SessionModel::where("session_id", $session_id)->first();
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
