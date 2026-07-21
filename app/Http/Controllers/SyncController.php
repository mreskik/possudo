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

  private function branchToken(int $branch_id): ?string
  {
    return DB::table('mr_branch')->where('id', $branch_id)->value('token');
  }

  public function getDataBranch(int $branch_id)
  {
    try {
      $response = $this->setupservices->getDatabranch('', '', $branch_id, $this->branchToken($branch_id));

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getStationList(int $branch_id)
  {
    try {
      $response = $this->setupservices->getStationList('', '', $branch_id, $this->branchToken($branch_id));

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getCategoryList(int $branch_id)
  {
    try {
      $response = $this->setupservices->getCategoryList('', '', $branch_id, $this->branchToken($branch_id));

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getSubCategoryList(int $branch_id)
  {
    try {
      $response = $this->setupservices->getSubCategoryList('', '', $branch_id, $this->branchToken($branch_id));

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getTableSectionList(int $branch_id)
  {
    try {
      $token = $this->branchToken($branch_id);
      $this->setupservices->getTableSectionPrintCategorySetting('', '', $branch_id, $token);

      $response = $this->setupservices->getTableSectionList('', '', $branch_id, $token);

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getTable(int $branch_id)
  {
    try {
      $response = $this->setupservices->getTable('', '', $branch_id, $this->branchToken($branch_id));

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getTax(int $branch_id)
  {
    try {
      $response = $this->setupservices->getTax('', '', $branch_id, $this->branchToken($branch_id));

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getTerminal(int $branch_id)
  {
    try {
      $response = $this->setupservices->getTerminal('', '', $branch_id, $this->branchToken($branch_id));

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getMasterItem(int $branch_id)
  {
    try {
      $response = $this->setupservices->getMasterItem('', '', $branch_id, $this->branchToken($branch_id));

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getMasterItemConv(int $branch_id)
  {
    try {
      $response = $this->setupservices->getMasterItemConv('', '', $branch_id, $this->branchToken($branch_id));

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getMasterItemPackage(int $branch_id)
  {
    try {
      $response = $this->setupservices->getMasterItemPackage('', '', $branch_id, $this->branchToken($branch_id));

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getMasterItemPackageGroup(int $branch_id)
  {
    try {
      $response = $this->setupservices->getMasterItemPackageGroup('', '', $branch_id, $this->branchToken($branch_id));

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getMasterItemPackageDetail(int $branch_id)
  {
    try {
      $response = $this->setupservices->getMasterItemPackageDetail('', '', $branch_id, $this->branchToken($branch_id));

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getMasterPricelist(int $branch_id)
  {
    try {
      $response = $this->setupservices->getMasterPricelist('', '', $branch_id, $this->branchToken($branch_id));

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getMasterPricelistDetail(int $branch_id)
  {
    try {
      $response = $this->setupservices->getMasterPricelistDetail('', '', $branch_id, $this->branchToken($branch_id));

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getMasterPaymentMethod(int $branch_id)
  {
    try {
      $response = $this->setupservices->getMasterPaymentMethod('', '', $branch_id, $this->branchToken($branch_id));

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getMasterPaymentMethodGroup(int $branch_id)
  {
    try {
      $response = $this->setupservices->getMasterPaymentMethodGroup('', '', $branch_id, $this->branchToken($branch_id));

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getMasterPaymentMethodType(int $branch_id)
  {
    try {
      $response = $this->setupservices->getMasterPaymentMethodType('', '', $branch_id, $this->branchToken($branch_id));

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getMasterPaymentMethodVisitPurpose(int $branch_id)
  {
    try {
      $response = $this->setupservices->getMasterPaymentMethodVisitPurpose('', '', $branch_id, $this->branchToken($branch_id));

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getMasterBranchVisitPurpose(int $branch_id)
  {
    try {
      $response = $this->setupservices->getMasterBranchVisitPurpose('', '', $branch_id, $this->branchToken($branch_id));

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getMasterVisitPurpose(int $branch_id)
  {
    try {
      $response = $this->setupservices->getMasterVisitPurpose('', '', $branch_id, $this->branchToken($branch_id));

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getMasterUser(int $branch_id)
  {
    try {
      $response = $this->setupservices->getMasterUser('', '', $branch_id, $this->branchToken($branch_id));

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getMasterRoleAccess(int $branch_id)
  {
    try {
      $response = $this->setupservices->getMasterRoleAccess('', '', $branch_id, $this->branchToken($branch_id));

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getMenuApp(int $branch_id)
  {
    try {
      $response = $this->setupservices->getMenuApp('', '', $branch_id, $this->branchToken($branch_id));

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getPromoList(int $branch_id)
  {
    try {
      $response = $this->setupservices->getPromoList('', '', $branch_id, $this->branchToken($branch_id));

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getPromoBranch(int $branch_id)
  {
    try {
      $response = $this->setupservices->getPromoBranch('', '', $branch_id, $this->branchToken($branch_id));

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getPromoVisitPurpose(int $branch_id)
  {
    try {
      $response = $this->setupservices->getPromoVisitPurpose('', '', $branch_id, $this->branchToken($branch_id));

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getPromoTypeMember(int $branch_id)
  {
    try {
      $response = $this->setupservices->getPromoTypeMember('', '', $branch_id, $this->branchToken($branch_id));

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getPromoCategory(int $branch_id)
  {
    try {
      $response = $this->setupservices->getPromoCategory('', '', $branch_id, $this->branchToken($branch_id));

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getPromoSubCategory(int $branch_id)
  {
    try {
      $response = $this->setupservices->getPromoSubCategory('', '', $branch_id, $this->branchToken($branch_id));

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getPromoItem(int $branch_id)
  {
    try {
      $response = $this->setupservices->getPromoItem('', '', $branch_id, $this->branchToken($branch_id));

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getPromoDay(int $branch_id)
  {
    try {
      $response = $this->setupservices->getPromoDay('', '', $branch_id, $this->branchToken($branch_id));

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getPromoTime(int $branch_id)
  {
    try {
      $response = $this->setupservices->getPromoTime('', '', $branch_id, $this->branchToken($branch_id));

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getMemberTypeList(int $branch_id)
  {
    try {
      $response = $this->setupservices->getMemberTypeList('', '', $branch_id, $this->branchToken($branch_id));

      return response()->json([
        'code' => $response->json('code'),
        'message' => $response->json('message'),
      ]);
    } catch (\Throwable $e) {
      Log::info($e->getMessage());
      return response()->json(['code' => 100, 'message' => $e->getMessage()]);
    }
  }

  public function getMemberList(int $branch_id)
  {
    try {
      $response = $this->setupservices->getMemberList('', '', $branch_id, $this->branchToken($branch_id));

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
