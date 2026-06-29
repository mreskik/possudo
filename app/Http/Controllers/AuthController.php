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
