<?php

namespace App\Http\Controllers;

use App\Services\ConfigService;
use App\Services\SetupServices;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SetupController extends Controller
{

  protected SetupServices $setupservices;
  protected string $server_endpoint;

  public function __construct()
  {
    $this->server_endpoint = env('SERVER_ENDPOINT');
    $this->setupservices = new SetupServices();
  }

  public function getBranchList(Request $request)
  {
    $username = $request->input('username', '');
    $password = $request->input('password', '');

    $response = Http::withOptions(['verify' => config('services.http_verify_ssl')])
      ->post($this->server_endpoint . '/pos/setup/get_branch_list', [
        "username" => $username,
        "password" => $password
      ]);

    return $response;
  }

  public function getDataBranch(int $branch_id, Request $request)
  {
    $username = $request->input('username', '');
    $password = $request->input('password', '');

    try {
      $response = $this->setupservices->getDatabranch($username, $password, $branch_id);

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function ChangeStatusInstall(int $status)
  {
    try {
      ConfigService::ChangeStatusInstall($status);

      return response()->json([
        'code' => 0,
        'message' => 'success'
      ]);
    } catch (\Throwable $e) {
      return response()->json([
        'code' => 100,
        'message' => $e->getMessage()
      ]);
    }
  }

  // GetInstallStatus -- baca balik flag_install_status, ditambahkan 2026-10-27. Murni backend --
  // frontend (SetupPage.vue) BELUM diubah buat pakai ini, tetap pakai cek branch_name lama.
  public function GetInstallStatus()
  {
    try {
      return response()->json([
        'code' => 0,
        'data' => [
          'is_installed' => ConfigService::GetInstallStatus(),
        ],
      ]);
    } catch (\Throwable $e) {
      return response()->json([
        'code' => 100,
        'message' => $e->getMessage()
      ]);
    }
  }
}
