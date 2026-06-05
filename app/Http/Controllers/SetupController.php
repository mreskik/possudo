<?php

namespace App\Http\Controllers;

use App\Models\BranchModel;
use App\Services\ConfigService;
use App\Services\SetupServices;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Override;

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

    $response = Http::post($this->server_endpoint . '/pos/setup/get_branch_list', [
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
      $response = $this->setupservices->getDataBranch($username, $password, $branch_id);

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());

      return response()->json([
        'code' => 100,
        'message' => $e->getMessage()
      ]);
    }
  }

  public function getStationList(int $branch_id, Request $request)
  {
    $username = $request->input('username', '');
    $password = $request->input('password', '');

    try {

      $response = $this->setupservices->getStationList($username, $password, $branch_id);

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message')
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());

      return response()->json([
        'code' => 100,
        'message' => $e->getMessage()
      ]);
    }
  }

  public function getCategoryList(int $branch_id, Request $request)
  {
    $username = $request->input('username', '');
    $password = $request->input('password', '');

    try {

      $response = $this->setupservices->getCategoryList($username, $password, $branch_id);

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message')
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());

      return response()->json([
        'code' => 100,
        'message' => $e->getMessage()
      ]);
    }
  }

  public function getSubCategoryList(int $branch_id, Request $request)
  {
    $username = $request->input('username', '');
    $password = $request->input('password', '');

    try {
      $response = $this->setupservices->getSubCategoryList($username, $password, $branch_id);
      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message')
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());

      return response()->json([
        'code' => 100,
        'message' => $e->getMessage()
      ]);
    }
  }

  public function getTableSectionList(int $branch_id, Request $request)
  {
    $username = $request->input('username', '');
    $password = $request->input('password', '');

    try {

      $response2 = $this->setupservices->getTableSectionPrintCategorySetting($username, $password, $branch_id);

      $response = $this->setupservices->getTableSectionList($username, $password, $branch_id);
      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message')
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());

      return response()->json([
        'code' => 100,
        'message' => $e->getMessage()
      ]);
    }
  }

  public function getTable(int $branch_id, Request $request)
  {
    $username = $request->input('username', '');
    $password = $request->input('password', '');

    try {
      $response = $this->setupservices->getTable($username, $password, $branch_id);
      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message')
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());

      return response()->json([
        'code' => 100,
        'message' => $e->getMessage()
      ]);
    }
  }

  public function getTax(int $branch_id, Request $request)
  {
    $username = $request->input('username', '');
    $password = $request->input('password', '');

    try {
      $response = $this->setupservices->getTax($username, $password, $branch_id);
      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message')
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json([
        'code' => 100,
        'message' => $e->getMessage()
      ]);
    }
  }

  public function getTerminal(int $branch_id, Request $request)
  {
    $username = $request->input('username', '');
    $password = $request->input('password', '');

    try {
      $response = $this->setupservices->getTerminal($username, $password, $branch_id);
      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message')
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json([
        'code' => 100,
        'message' => $e->getMessage()
      ]);
    }
  }

  ////

  public function GetMasterItem(int $branch_id, Request $request)
  {
    $username = $request->input('username', '');
    $password = $request->input('password', '');

    try {

      $response = $this->setupservices->GetMasterItem($username, $password, $branch_id);
      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message')
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json([
        'code' => 100,
        'message' => $e->getMessage()
      ]);
    }
  }


  public function getMasterItemConv(int $branch_id, Request $request)
  {
    $username = $request->input('username', '');
    $password = $request->input('password', '');

    try {
      $response = $this->setupservices->getMasterItemConv($username, $password, $branch_id);
      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message')
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json([
        'code' => 100,
        'message' => $e->getMessage()
      ]);
    }
  }

  public function getMasterItemPackage(int $branch_id, Request $request)
  {
    $username = $request->input('username', '');
    $password = $request->input('password', '');

    try {
      $response = $this->setupservices->getMasterItemPackage($username, $password, $branch_id);
      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message')
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json([
        'code' => 100,
        'message' => $e->getMessage()
      ]);
    }
  }


  public function getMasterItemPackageGroup(int $branch_id, Request $request)
  {
    $username = $request->input('username', '');
    $password = $request->input('password', '');

    try {
      $response = $this->setupservices->getMasterItemPackageGroup($username, $password, $branch_id);
      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message')
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json([
        'code' => 100,
        'message' => $e->getMessage()
      ]);
    }
  }

  public function getMasterItemPackageDetail(int $branch_id, Request $request)
  {
    $username = $request->input('username', '');
    $password = $request->input('password', '');

    try {
      $response = $this->setupservices->getMasterItemPackageDetail($username, $password, $branch_id);
      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message')
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json([
        'code' => 100,
        'message' => $e->getMessage()
      ]);
    }
  }

  public function getMasterPricelist(int $branch_id, Request $request)
  {
    $username = $request->input('username', '');
    $password = $request->input('password', '');

    try {
      $response = $this->setupservices->getMasterPricelist($username, $password, $branch_id);
      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message')
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json([
        'code' => 100,
        'message' => $e->getMessage()
      ]);
    }
  }

  public function getMasterPricelistDetail(int $branch_id, Request $request)
  {
    $username = $request->input('username', '');
    $password = $request->input('password', '');

    try {
      $response = $this->setupservices->getMasterPricelistDetail($username, $password, $branch_id);
      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message')
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json([
        'code' => 100,
        'message' => $e->getMessage()
      ]);
    }
  }

  public function getMasterPaymentMethod(int $branch_id, Request $request)
  {
    $username = $request->input('username', '');
    $password = $request->input('password', '');

    try {
      $response = $this->setupservices->getMasterPaymentMethod($username, $password, $branch_id);
      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message')
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json([
        'code' => 100,
        'message' => $e->getMessage()
      ]);
    }
  }

  public function getMasterPaymentMethodGroup(int $branch_id, Request $request)
  {
    $username = $request->input('username', '');
    $password = $request->input('password', '');

    try {
      $response = $this->setupservices->getMasterPaymentMethodGroup($username, $password, $branch_id);
      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message')
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json([
        'code' => 100,
        'message' => $e->getMessage()
      ]);
    }
  }

  public function getMasterPaymentMethodType(int $branch_id, Request $request)
  {
    $username = $request->input('username', '');
    $password = $request->input('password', '');

    try {
      $response = $this->setupservices->getMasterPaymentMethodType($username, $password, $branch_id);
      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message')
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json([
        'code' => 100,
        'message' => $e->getMessage()
      ]);
    }
  }

  public function getMasterPaymentMethodVisitPurpose(int $branch_id, Request $request)
  {
    $username = $request->input('username', '');
    $password = $request->input('password', '');

    try {
      $response = $this->setupservices->getMasterPaymentMethodVisitPurpose($username, $password, $branch_id);
      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message')
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json([
        'code' => 100,
        'message' => $e->getMessage()
      ]);
    }
  }

  public function getMasterBranchVisitPurpose(int $branch_id, Request $request)
  {
    $username = $request->input('username', '');
    $password = $request->input('password', '');

    try {
      $response = $this->setupservices->getMasterBranchVisitPurpose($username, $password, $branch_id);
      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message')
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json([
        'code' => 100,
        'message' => $e->getMessage()
      ]);
    }
  }

  public function getMasterVisitPurpose(int $branch_id, Request $request)
  {
    $username = $request->input('username', '');
    $password = $request->input('password', '');

    try {
      $response = $this->setupservices->getMasterVisitPurpose($username, $password, $branch_id);
      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message')
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json([
        'code' => 100,
        'message' => $e->getMessage()
      ]);
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



  // public function get
}
