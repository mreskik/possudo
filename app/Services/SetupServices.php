<?php

namespace App\Services;

use App\Models\BranchModel;
use App\Models\CategoryModel;
use App\Models\MasterBranchVisitPurposeModel;
use App\Models\MasterItemConvModel;
use App\Models\MasterItemModel;
use App\Models\MasterItemPackageDetailModel;
use App\Models\MasterItemPackageGroupModel;
use App\Models\MasterItemPackageModel;
use App\Models\MasterMemberModel;
use App\Models\MasterMemberTypeModel;
use App\Models\MasterMenuAppModel;
use App\Models\MasterPaymentMethodGroupModel;
use App\Models\MasterPaymentMethodModel;
use App\Models\MasterPaymentMethodTypeModel;
use App\Models\MasterPaymentMethodVisitPurposeModel;
use App\Models\MasterPricelistDetailModel;
use App\Models\MasterPricelistModel;
use App\Models\MasterPromoBranchesModel;
use App\Models\MasterPromoCategoriesModel;
use App\Models\MasterPromoDaysModel;
use App\Models\MasterPromoItemsModel;
use App\Models\MasterPromoModel;
use App\Models\MasterPromoSubCategoriesModel;
use App\Models\MasterPromoTimesModel;
use App\Models\MasterPromoTypeMembersModel;
use App\Models\MasterPromoVisitPurposesModel;
use App\Models\MasterTableSectionPrintCategorySettingModel;
use App\Models\MasterTaxModel;
use App\Models\MasterUserModel;
use App\Models\MasterVisitPurposeModel;
use App\Models\RoleAccessModel;
use Illuminate\Support\Facades\Http;
use App\Models\StationModel;
use App\Models\SubCategoryModel;
use App\Models\TableModel;
use App\Models\TableSectionModel;
use App\Models\TerminalModel;
use Illuminate\Support\Facades\Log;
use Throwable;

class SetupServices
{
  protected string $endpoint;

  public function __construct()
  {
    $this->endpoint = env('SERVER_ENDPOINT');
  }

  private function syncRequest(string $username, string $password, ?string $token, string $url): \Illuminate\Http\Client\Response
  {
    if ($token) {
      return Http::withToken($token)->get($url);
    }
    return Http::post($url, ['username' => $username, 'password' => $password]);
  }


  public function getDatabranch(string $username, string $password, string $branch_id, ?string $token = null)
  {
    try {
      $url = $token
        ? $this->endpoint . '/pos/sync/get_data_branch/' . $branch_id
        : $this->endpoint . '/pos/setup/get_data_branch/' . $branch_id;
      $response = $this->syncRequest($username, $password, $token, $url);

      if ($response->json('code') == 0) {
        BranchModel::truncate();

        BranchModel::create([
          'id' => $response->json('data.BranchID'),
          'branch_code' => $response->json("data.BranchCode"),
          'branch_name' => $response->json("data.BranchName"),
          'brand_code' => $response->json("data.BrandCode"),
          'brand_name' => $response->json("data.BrandName"),
          'address' => $response->json("data.Address"),
          'phone' => $response->json('data.Phone'),
          'printing_header' => $response->json('data.PrintingHeader'),
          'printing_footer' => $response->json('data.PrintingFooter'),
          'company_id' => $response->json("data.CompanyId"),
          'token' => $response->json("data.Token"),
          'logo_header_src' => $this->downloadBranchImage($response->json('data.LogoHeaderSrc')),
          'image_footer_src' => $this->downloadBranchImage($response->json('data.ImageFooterSrc')),
        ]);
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  private function downloadBranchImage(?string $remotePath): ?string
  {
    if (empty($remotePath)) {
      return null;
    }

    try {
      $imageUrl = $this->endpoint . $remotePath;
      $contents = Http::get($imageUrl)->body();

      $filename = basename($remotePath);
      $dir = public_path('img/branch');

      if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
      }

      file_put_contents($dir . '/' . $filename, $contents);

      return '/img/branch/' . $filename;
    } catch (\Throwable $e) {
      Log::warning('Gagal download branch image: ' . $e->getMessage());
      return null;
    }
  }


  public function getStationList(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest($username, $password, $token,
        $this->endpoint . '/pos/sync/get_station_list/' . $branch_id);

      if ($response->json('code') == 0) {
        StationModel::truncate();

        $datastation = [];
        foreach ($response->json('data') as $item) {
          $datastation[] = [
            'id' => $item['StationID'],
            'branch_id' => $item['BranchID'],
            'name' => $item['StationName'],
            'printer_name' => $item['PrinterName'],
            'printer_type' => $item['PrinterType'],
            'printer_connection' => $item['PrinterConnection'],
            'printing_mode' => $item['PrintingMode'],
            'port' => $item['Port'],
            'auto_cut' => $item['AutoCut'],
            'cash_drawer' => $item['CashDrawer'],
            'line_character' => $item['LineCharacter'],
          ];
        }
        StationModel::insert($datastation);
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }


  public function getCategoryList(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest($username, $password, $token,
        $this->endpoint . '/pos/sync/get_category_list/' . $branch_id);

      if ($response->json('code') == 0) {
        CategoryModel::truncate();
        CategoryModel::insert($response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public function getSubCategoryList(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest($username, $password, $token,
        $this->endpoint . '/pos/sync/get_subcategory_list/' . $branch_id);

      if ($response->json('code') == 0) {
        SubCategoryModel::truncate();
        SubCategoryModel::insert($response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public function getTableSectionList(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest($username, $password, $token,
        $this->endpoint . '/pos/sync/get_tablesection_list/' . $branch_id);

      if ($response->json('code') == 0) {
        TableSectionModel::truncate();
        TableSectionModel::insert($response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public function getTable(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest($username, $password, $token,
        $this->endpoint . '/pos/sync/get_table/' . $branch_id);

      if ($response->json('code') == 0) {
        TableModel::truncate();
        TableModel::insert($response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public function getTax(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest($username, $password, $token,
        $this->endpoint . '/pos/sync/get_tax/' . $branch_id);

      if ($response->json('code') == 0) {
        MasterTaxModel::truncate();
        MasterTaxModel::insert($response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public function getTerminal(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest($username, $password, $token,
        $this->endpoint . '/pos/sync/get_terminal/' . $branch_id);

      if ($response->json('code') == 0) {
        TerminalModel::truncate();
        TerminalModel::insert($response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  ////

  public function getMasterItem(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest($username, $password, $token,
        $this->endpoint . '/pos/sync/get_item/' . $branch_id);

      if ($response->json('code') == 0) {
        MasterItemModel::truncate();
        MasterItemModel::insert($response->json("data"));
      }

      return $response;
    } catch (Throwable $e) {
      throw $e;
    }
  }

  public function getMasterItemConv(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest($username, $password, $token,
        $this->endpoint . '/pos/sync/get_item_conv/' . $branch_id);

      if ($response->json('code') == 0) {
        MasterItemConvModel::truncate();
        MasterItemConvModel::insert($response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public function getMasterItemPackage(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest($username, $password, $token,
        $this->endpoint . '/pos/sync/get_item_package/' . $branch_id);

      if ($response->json('code') == 0) {
        MasterItemPackageModel::truncate();
        MasterItemPackageModel::insert($response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public function getMasterItemPackageGroup(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest($username, $password, $token,
        $this->endpoint . '/pos/sync/get_item_package_group/' . $branch_id);

      Log::info($response);

      if ($response->json('code') == 0) {
        MasterItemPackageGroupModel::truncate();
        MasterItemPackageGroupModel::insert($response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public function getMasterItemPackageDetail(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest($username, $password, $token,
        $this->endpoint . '/pos/sync/get_item_package_detail/' . $branch_id);

      if ($response->json('code') == 0) {
        MasterItemPackageDetailModel::truncate();
        MasterItemPackageDetailModel::insert($response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public function getMasterPricelist(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest($username, $password, $token,
        $this->endpoint . '/pos/sync/get_pricelist/' . $branch_id);

      if ($response->json('code') == 0) {
        MasterPricelistModel::truncate();
        MasterPricelistModel::insert($response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public function getMasterPricelistDetail(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest($username, $password, $token,
        $this->endpoint . '/pos/sync/get_pricelist_detail/' . $branch_id);

      if ($response->json('code') == 0) {
        MasterPricelistDetailModel::truncate();
        MasterPricelistDetailModel::insert($response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  /////

  public function getMasterPaymentMethod(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest($username, $password, $token,
        $this->endpoint . '/pos/sync/get_payment_method/' . $branch_id);

      Log::info($response->json("data"));

      if ($response->json('code') == 0) {
        MasterPaymentMethodModel::truncate();
        MasterPaymentMethodModel::insert($response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public function getMasterPaymentMethodGroup(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest($username, $password, $token,
        $this->endpoint . '/pos/sync/get_payment_method_group/' . $branch_id);

      if ($response->json('code') == 0) {
        MasterPaymentMethodGroupModel::truncate();
        MasterPaymentMethodGroupModel::insert($response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public function getMasterPaymentMethodType(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest($username, $password, $token,
        $this->endpoint . '/pos/sync/get_payment_method_type/' . $branch_id);

      if ($response->json('code') == 0) {
        MasterPaymentMethodTypeModel::truncate();
        MasterPaymentMethodTypeModel::insert($response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public function getMasterPaymentMethodVisitPurpose(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest($username, $password, $token,
        $this->endpoint . '/pos/sync/get_payment_method_visit_purpose/' . $branch_id);

      if ($response->json('code') == 0) {
        MasterPaymentMethodVisitPurposeModel::truncate();
        MasterPaymentMethodVisitPurposeModel::insert($response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public function getMasterBranchVisitPurpose(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest($username, $password, $token,
        $this->endpoint . '/pos/sync/get_branch_visit_purpose/' . $branch_id);

      if ($response->json('code') == 0) {
        MasterBranchVisitPurposeModel::truncate();
        MasterBranchVisitPurposeModel::insert($response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public function getMasterVisitPurpose(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest($username, $password, $token,
        $this->endpoint . '/pos/sync/get_visit_purpose/' . $branch_id);

      if ($response->json('code') == 0) {
        MasterVisitPurposeModel::truncate();
        MasterVisitPurposeModel::insert($response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public function getMasterUser(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest($username, $password, $token,
        $this->endpoint . '/pos/sync/get_master_user/' . $branch_id);

      if ($response->json('code') == 0) {
        MasterUserModel::truncate();
        MasterUserModel::insert($response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public function getMasterRoleAccess(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest($username, $password, $token,
        $this->endpoint . '/pos/sync/get_master_role_access/' . $branch_id);

      if ($response->json('code') == 0) {
        RoleAccessModel::truncate();
        RoleAccessModel::insert($response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public function getMenuApp(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest($username, $password, $token,
        $this->endpoint . '/pos/sync/get_menu_app/' . $branch_id);

      if ($response->json('code') == 0) {
        MasterMenuAppModel::truncate();
        MasterMenuAppModel::insert($response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public function getTableSectionPrintCategorySetting(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest($username, $password, $token,
        $this->endpoint . '/pos/sync/get_table_section_print_category_setting/' . $branch_id);

      Log::info("==========================");
      Log::info($response);

      if ($response->json('code') == 0) {
        MasterTableSectionPrintCategorySettingModel::truncate();
        MasterTableSectionPrintCategorySettingModel::insert($response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  /////

  /**
   * Promo rows from APIANDORDER use Go `omitempty` on nullable fields (type_percent_rate,
   * type_freeitem_item_id, created_by, updated_at, updated_by) — when null, the key is missing
   * entirely instead of being `null`. Model::insert() builds one SQL statement from the column
   * list of the first row, so rows with different key sets break with "column count doesn't
   * match value count". Pad every row to the same set of keys before inserting.
   */
  private function normalizeInsertRows(array $rows): array
  {
    $allKeys = [];
    foreach ($rows as $row) {
      $allKeys += array_fill_keys(array_keys($row), null);
    }
    foreach ($rows as &$row) {
      $row = array_merge($allKeys, $row);
    }
    return $rows;
  }

  public function getPromoList(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest($username, $password, $token,
        $this->endpoint . '/pos/sync/get_promo_list/' . $branch_id);

      if ($response->json('code') == 0) {
        MasterPromoModel::truncate();
        MasterPromoModel::insert($this->normalizeInsertRows($response->json('data')));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public function getPromoBranch(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest($username, $password, $token,
        $this->endpoint . '/pos/sync/get_promo_branch/' . $branch_id);

      if ($response->json('code') == 0) {
        MasterPromoBranchesModel::truncate();
        MasterPromoBranchesModel::insert($response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public function getPromoVisitPurpose(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest($username, $password, $token,
        $this->endpoint . '/pos/sync/get_promo_visit_purpose/' . $branch_id);

      if ($response->json('code') == 0) {
        MasterPromoVisitPurposesModel::truncate();
        MasterPromoVisitPurposesModel::insert($response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public function getPromoTypeMember(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest($username, $password, $token,
        $this->endpoint . '/pos/sync/get_promo_type_member/' . $branch_id);

      if ($response->json('code') == 0) {
        MasterPromoTypeMembersModel::truncate();
        MasterPromoTypeMembersModel::insert($response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public function getPromoCategory(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest($username, $password, $token,
        $this->endpoint . '/pos/sync/get_promo_category/' . $branch_id);

      if ($response->json('code') == 0) {
        MasterPromoCategoriesModel::truncate();
        MasterPromoCategoriesModel::insert($response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public function getPromoSubCategory(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest($username, $password, $token,
        $this->endpoint . '/pos/sync/get_promo_sub_category/' . $branch_id);

      if ($response->json('code') == 0) {
        MasterPromoSubCategoriesModel::truncate();
        MasterPromoSubCategoriesModel::insert($response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public function getPromoItem(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest($username, $password, $token,
        $this->endpoint . '/pos/sync/get_promo_item/' . $branch_id);

      if ($response->json('code') == 0) {
        MasterPromoItemsModel::truncate();
        MasterPromoItemsModel::insert($response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public function getPromoDay(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest($username, $password, $token,
        $this->endpoint . '/pos/sync/get_promo_day/' . $branch_id);

      if ($response->json('code') == 0) {
        MasterPromoDaysModel::truncate();
        MasterPromoDaysModel::insert($response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public function getPromoTime(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest($username, $password, $token,
        $this->endpoint . '/pos/sync/get_promo_time/' . $branch_id);

      if ($response->json('code') == 0) {
        MasterPromoTimesModel::truncate();
        MasterPromoTimesModel::insert($response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  /////

  public function getMemberTypeList(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest($username, $password, $token,
        $this->endpoint . '/pos/sync/get_member_type_list/' . $branch_id);

      if ($response->json('code') == 0) {
        MasterMemberTypeModel::truncate();
        MasterMemberTypeModel::insert($this->normalizeInsertRows($response->json('data')));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public function getMemberList(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest($username, $password, $token,
        $this->endpoint . '/pos/sync/get_member_list/' . $branch_id);

      if ($response->json('code') == 0) {
        MasterMemberModel::truncate();
        MasterMemberModel::insert($this->normalizeInsertRows($response->json('data')));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }
}
