<?php

namespace App\Http\Controllers;

use App\Services\SetupServices;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncController extends Controller
{
  protected SetupServices $setupservices;

  public function __construct()
  {
    $this->setupservices = new SetupServices();
  }

  // currentBranch: mr_branch lokal cuma pernah punya 1 baris (truncate+create tiap
  // getDatabranch()), jadi branch_id gak perlu dikirim dari client lagi -- ambil langsung
  // dari sini. Null kalau belum pernah pilih branch (belum install).
  private function currentBranch(): ?object
  {
    return DB::table('mr_branch')->first();
  }

  private function noBranchResponse()
  {
    return response()->json(['code' => 100, 'message' => 'branch belum dipilih/disimpan, lakukan setup dulu']);
  }

  public function getDataBranch()
  {
    $branch = $this->currentBranch();
    if (!$branch) {
      return $this->noBranchResponse();
    }

    try {
      $response = $this->setupservices->getDatabranch('', '', $branch->id, $branch->token);

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getStationList()
  {
    $branch = $this->currentBranch();
    if (!$branch) {
      return $this->noBranchResponse();
    }

    try {
      $response = $this->setupservices->getStationList('', '', $branch->id, $branch->token);

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getCategoryList()
  {
    $branch = $this->currentBranch();
    if (!$branch) {
      return $this->noBranchResponse();
    }

    try {
      $response = $this->setupservices->getCategoryList('', '', $branch->id, $branch->token);

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getSubCategoryList()
  {
    $branch = $this->currentBranch();
    if (!$branch) {
      return $this->noBranchResponse();
    }

    try {
      $response = $this->setupservices->getSubCategoryList('', '', $branch->id, $branch->token);

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getTableSectionList()
  {
    $branch = $this->currentBranch();
    if (!$branch) {
      return $this->noBranchResponse();
    }

    try {
      $this->setupservices->getTableSectionPrintCategorySetting('', '', $branch->id, $branch->token);

      $response = $this->setupservices->getTableSectionList('', '', $branch->id, $branch->token);

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getTable()
  {
    $branch = $this->currentBranch();
    if (!$branch) {
      return $this->noBranchResponse();
    }

    try {
      $response = $this->setupservices->getTable('', '', $branch->id, $branch->token);

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getTax()
  {
    $branch = $this->currentBranch();
    if (!$branch) {
      return $this->noBranchResponse();
    }

    try {
      $response = $this->setupservices->getTax('', '', $branch->id, $branch->token);

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getTerminal()
  {
    $branch = $this->currentBranch();
    if (!$branch) {
      return $this->noBranchResponse();
    }

    try {
      $response = $this->setupservices->getTerminal('', '', $branch->id, $branch->token);

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getMasterItem()
  {
    $branch = $this->currentBranch();
    if (!$branch) {
      return $this->noBranchResponse();
    }

    try {
      $response = $this->setupservices->getMasterItem('', '', $branch->id, $branch->token);

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getMasterItemConv()
  {
    $branch = $this->currentBranch();
    if (!$branch) {
      return $this->noBranchResponse();
    }

    try {
      $response = $this->setupservices->getMasterItemConv('', '', $branch->id, $branch->token);

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getMasterItemPackage()
  {
    $branch = $this->currentBranch();
    if (!$branch) {
      return $this->noBranchResponse();
    }

    try {
      $response = $this->setupservices->getMasterItemPackage('', '', $branch->id, $branch->token);

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getMasterItemPackageGroup()
  {
    $branch = $this->currentBranch();
    if (!$branch) {
      return $this->noBranchResponse();
    }

    try {
      $response = $this->setupservices->getMasterItemPackageGroup('', '', $branch->id, $branch->token);

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getMasterItemPackageDetail()
  {
    $branch = $this->currentBranch();
    if (!$branch) {
      return $this->noBranchResponse();
    }

    try {
      $response = $this->setupservices->getMasterItemPackageDetail('', '', $branch->id, $branch->token);

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getMasterPricelist()
  {
    $branch = $this->currentBranch();
    if (!$branch) {
      return $this->noBranchResponse();
    }

    try {
      $response = $this->setupservices->getMasterPricelist('', '', $branch->id, $branch->token);

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getMasterPricelistDetail()
  {
    $branch = $this->currentBranch();
    if (!$branch) {
      return $this->noBranchResponse();
    }

    try {
      $response = $this->setupservices->getMasterPricelistDetail('', '', $branch->id, $branch->token);

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getMasterPaymentMethod()
  {
    $branch = $this->currentBranch();
    if (!$branch) {
      return $this->noBranchResponse();
    }

    try {
      $response = $this->setupservices->getMasterPaymentMethod('', '', $branch->id, $branch->token);

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getMasterPaymentMethodGroup()
  {
    $branch = $this->currentBranch();
    if (!$branch) {
      return $this->noBranchResponse();
    }

    try {
      $response = $this->setupservices->getMasterPaymentMethodGroup('', '', $branch->id, $branch->token);

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getMasterPaymentMethodType()
  {
    $branch = $this->currentBranch();
    if (!$branch) {
      return $this->noBranchResponse();
    }

    try {
      $response = $this->setupservices->getMasterPaymentMethodType('', '', $branch->id, $branch->token);

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getMasterPaymentMethodVisitPurpose()
  {
    $branch = $this->currentBranch();
    if (!$branch) {
      return $this->noBranchResponse();
    }

    try {
      $response = $this->setupservices->getMasterPaymentMethodVisitPurpose('', '', $branch->id, $branch->token);

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getMasterBranchVisitPurpose()
  {
    $branch = $this->currentBranch();
    if (!$branch) {
      return $this->noBranchResponse();
    }

    try {
      $response = $this->setupservices->getMasterBranchVisitPurpose('', '', $branch->id, $branch->token);

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getMasterBranchOpsSetting()
  {
    $branch = $this->currentBranch();
    if (!$branch) {
      return $this->noBranchResponse();
    }

    try {
      $response = $this->setupservices->getMasterBranchOpsSetting('', '', $branch->id, $branch->token);

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getMasterImage()
  {
    $branch = $this->currentBranch();
    if (!$branch) {
      return $this->noBranchResponse();
    }

    try {
      $response = $this->setupservices->getMasterImage('', '', $branch->id, $branch->token);

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getMasterImageCustomerDisplay()
  {
    $branch = $this->currentBranch();
    if (!$branch) {
      return $this->noBranchResponse();
    }

    try {
      $response = $this->setupservices->getMasterImageCustomerDisplay('', '', $branch->id, $branch->token);

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getMasterImageKiosk()
  {
    $branch = $this->currentBranch();
    if (!$branch) {
      return $this->noBranchResponse();
    }

    try {
      $response = $this->setupservices->getMasterImageKiosk('', '', $branch->id, $branch->token);

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getMasterVisitPurpose()
  {
    $branch = $this->currentBranch();
    if (!$branch) {
      return $this->noBranchResponse();
    }

    try {
      $response = $this->setupservices->getMasterVisitPurpose('', '', $branch->id, $branch->token);

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getMasterUser()
  {
    $branch = $this->currentBranch();
    if (!$branch) {
      return $this->noBranchResponse();
    }

    try {
      $response = $this->setupservices->getMasterUser('', '', $branch->id, $branch->token);

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getMasterRoleAccess()
  {
    $branch = $this->currentBranch();
    if (!$branch) {
      return $this->noBranchResponse();
    }

    try {
      $response = $this->setupservices->getMasterRoleAccess('', '', $branch->id, $branch->token);

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getMenuApp()
  {
    $branch = $this->currentBranch();
    if (!$branch) {
      return $this->noBranchResponse();
    }

    try {
      $response = $this->setupservices->getMenuApp('', '', $branch->id, $branch->token);

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getPromoList()
  {
    $branch = $this->currentBranch();
    if (!$branch) {
      return $this->noBranchResponse();
    }

    try {
      $response = $this->setupservices->getPromoList('', '', $branch->id, $branch->token);

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getPromoBranch()
  {
    $branch = $this->currentBranch();
    if (!$branch) {
      return $this->noBranchResponse();
    }

    try {
      $response = $this->setupservices->getPromoBranch('', '', $branch->id, $branch->token);

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getPromoVisitPurpose()
  {
    $branch = $this->currentBranch();
    if (!$branch) {
      return $this->noBranchResponse();
    }

    try {
      $response = $this->setupservices->getPromoVisitPurpose('', '', $branch->id, $branch->token);

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getPromoTypeMember()
  {
    $branch = $this->currentBranch();
    if (!$branch) {
      return $this->noBranchResponse();
    }

    try {
      $response = $this->setupservices->getPromoTypeMember('', '', $branch->id, $branch->token);

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getPromoCategory()
  {
    $branch = $this->currentBranch();
    if (!$branch) {
      return $this->noBranchResponse();
    }

    try {
      $response = $this->setupservices->getPromoCategory('', '', $branch->id, $branch->token);

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getPromoSubCategory()
  {
    $branch = $this->currentBranch();
    if (!$branch) {
      return $this->noBranchResponse();
    }

    try {
      $response = $this->setupservices->getPromoSubCategory('', '', $branch->id, $branch->token);

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getPromoItem()
  {
    $branch = $this->currentBranch();
    if (!$branch) {
      return $this->noBranchResponse();
    }

    try {
      $response = $this->setupservices->getPromoItem('', '', $branch->id, $branch->token);

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getPromoDay()
  {
    $branch = $this->currentBranch();
    if (!$branch) {
      return $this->noBranchResponse();
    }

    try {
      $response = $this->setupservices->getPromoDay('', '', $branch->id, $branch->token);

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getPromoTime()
  {
    $branch = $this->currentBranch();
    if (!$branch) {
      return $this->noBranchResponse();
    }

    try {
      $response = $this->setupservices->getPromoTime('', '', $branch->id, $branch->token);

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getPromoApplyTo()
  {
    $branch = $this->currentBranch();
    if (!$branch) {
      return $this->noBranchResponse();
    }

    try {
      $response = $this->setupservices->getPromoApplyTo('', '', $branch->id, $branch->token);

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getMemberTypeList()
  {
    $branch = $this->currentBranch();
    if (!$branch) {
      return $this->noBranchResponse();
    }

    try {
      $response = $this->setupservices->getMemberTypeList('', '', $branch->id, $branch->token);

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getMemberList()
  {
    $branch = $this->currentBranch();
    if (!$branch) {
      return $this->noBranchResponse();
    }

    try {
      $response = $this->setupservices->getMemberList('', '', $branch->id, $branch->token);

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }
}
