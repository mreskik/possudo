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
use App\Models\MasterPaymentMethodGroupModel;
use App\Models\MasterPaymentMethodModel;
use App\Models\MasterPaymentMethodTypeModel;
use App\Models\MasterPaymentMethodVisitPurposeModel;
use App\Models\MasterPricelistDetailModel;
use App\Models\MasterPricelistModel;
use App\Models\MasterTableSectionPrintCategorySettingModel;
use App\Models\MasterTaxModel;
use App\Models\MasterVisitPurposeModel;
use App\Models\SetupConfigModel;
use Illuminate\Support\Facades\Http;
use App\Models\StationModel;
use App\Models\SubCategoryModel;
use App\Models\TableModel;
use App\Models\TableSectionModel;
use App\Models\TerminalModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SetupServices
{
  protected string $endpoint;

  public function __construct()
  {
    $this->endpoint = env('SERVER_ENDPOINT');
  }


  public function getDatabranch(string $username, string $password, string $branch_id)
  {
    try {
      $response = Http::post($this->endpoint . '/pos/setup/get_data_branch/' . $branch_id, [
        "username" => $username,
        "password" => $password
      ]);
      // RESPONSE FROM SERVER

      if ($response->json('code') == 0) {

        // Log::info("GET FROM SERVER :".$response);
        // DELETE DATA LAMA 
        BranchModel::truncate();

        //INSERT DATA BARU 
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
          'company_id' => $response->json("data.CompanyId")
        ]);
      }

      return $response;
    } catch (\Throwable $e) {
      return $e;
    }
  }


  public function getStationList(string $username, string $password, int $branch_id)
  {
    try {
      //GET FROM DATA SERVER
      $response = Http::post($this->endpoint . '/pos/setup/get_station_list/' . $branch_id, [
        'username' => $username,
        'password' => $password
      ]);
      // Log::info("GET FROM SERVER : ".$response);


      if ($response->json('code') == 0) {
        // REMOVE DATA LAMA
        StationModel::truncate();

        // INSERT DATA BARU
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
      return $e;
    }
  }


  public function getCategoryList(string $username, string $password, int $branch_id)
  {
    try {
      $response = Http::post($this->endpoint . '/pos/setup/get_category_list/' . $branch_id, [
        'username' => $username,
        'password' => $password,
      ]);
      if ($response->json('code') == 0) {
        CategoryModel::truncate();
        CategoryModel::insert($response->json('data'));
      } else {
      }

      return $response;
    } catch (\Throwable $e) {
      return $e;
    }
  }

  public function getSubCategoryList(string $username, string $password, int $branch_id)
  {
    try {
      $response = Http::post($this->endpoint . '/pos/setup/get_subcategory_list/' . $branch_id, [
        'username' => $username,
        'password' => $password,
      ]);
      if ($response->json('code') == 0) {
        SubCategoryModel::truncate();
        SubCategoryModel::insert($response->json('data'));
      } else {
      }

      return $response;
    } catch (\Throwable $e) {
      return $e;
    }
  }

  public function getTableSectionList(string $username, string $password, int $branch_id)
  {
    try {
      $response = Http::post($this->endpoint . '/pos/setup/get_tablesection_list/' . $branch_id, [
        'username' => $username,
        'password' => $password,
      ]);
      if ($response->json('code') == 0) {
        TableSectionModel::truncate();
        TableSectionModel::insert($response->json('data'));
      } else {
      }

      return $response;
    } catch (\Throwable $e) {
      return $e;
    }
  }

  public function getTable(string $username, string $password, int $branch_id)
  {
    try {
      $response = Http::post($this->endpoint . '/pos/setup/get_table/' . $branch_id, [
        'username' => $username,
        'password' => $password,
      ]);
      if ($response->json('code') == 0) {
        TableModel::truncate();
        TableModel::insert($response->json('data'));
      } else {
      }
      return $response;
    } catch (\Throwable $e) {
      return $e;
    }
  }

  public function getTax(string $username, string $password, int $branch_id)
  {
    try {
      $response = Http::post($this->endpoint . '/pos/setup/get_tax/' . $branch_id, [
        'username' => $username,
        'password' => $password,
      ]);
      if ($response->json('code') == 0) {
        MasterTaxModel::truncate();
        MasterTaxModel::insert($response->json('data'));
      } else {
      }
      return $response;
    } catch (\Throwable $e) {
      return $e;
    }
  }

  public function getTerminal(string $username, string $password, int $branch_id)
  {
    try {
      $response = Http::post($this->endpoint . '/pos/setup/get_terminal/' . $branch_id, [
        'username' => $username,
        'password' => $password,
      ]);
      if ($response->json('code') == 0) {
        TerminalModel::truncate();
        TerminalModel::insert($response->json('data'));
      } else {
      }
      return $response;
    } catch (\Throwable $e) {
      return $e;
    }
  }

  ////

  public function getMasterItem(string $username, string $password, int $branch_id)
  {
    try {
      $response = Http::post($this->endpoint . '/pos/setup/get_item/' . $branch_id, [
        'username' => $username,
        'password' => $password,
      ]);



      if ($response->json('code') == 0) {
        MasterItemModel::truncate();
        MasterItemModel::insert($response->json("data"));
      } else {
      }
      return $response;
    } catch (Throwable $e) {

      return $e;
    }
  }

  public function getMasterItemConv(string $username, string $password, int $branch_id)
  {
    try {
      $response = Http::post($this->endpoint . '/pos/setup/get_item_conv/' . $branch_id, [
        'username' => $username,
        'password' => $password,
      ]);
      if ($response->json('code') == 0) {
        MasterItemConvModel::truncate();
        MasterItemConvModel::insert($response->json('data'));
      } else {
      }
      return $response;
    } catch (\Throwable $e) {
      return $e;
    }
  }

  public function getMasterItemPackage(string $username, string $password, int $branch_id)
  {
    try {
      $response = Http::post($this->endpoint . '/pos/setup/get_item_package/' . $branch_id, [
        'username' => $username,
        'password' => $password,
      ]);

      if ($response->json('code') == 0) {
        MasterItemPackageModel::truncate();
        MasterItemPackageModel::insert($response->json('data'));
      } else {
      }
      return $response;
    } catch (\Throwable $e) {
      return $e;
    }
  }

  public function getMasterItemPackageGroup(string $username, string $password, int $branch_id)
  {
    try {
      $response = Http::post($this->endpoint . '/pos/setup/get_item_package_group/' . $branch_id, [
        'username' => $username,
        'password' => $password,
      ]);
      Log::info($response);
      if ($response->json('code') == 0) {
        MasterItemPackageGroupModel::truncate();
        MasterItemPackageGroupModel::insert($response->json('data'));
      } else {
      }
      return $response;
    } catch (\Throwable $e) {
      return $e;
    }
  }

  public function getMasterItemPackageDetail(string $username, string $password, int $branch_id)
  {
    try {
      $response = Http::post($this->endpoint . '/pos/setup/get_item_package_detail/' . $branch_id, [
        'username' => $username,
        'password' => $password,
      ]);
      if ($response->json('code') == 0) {
        MasterItemPackageDetailModel::truncate();
        MasterItemPackageDetailModel::insert($response->json('data'));
      } else {
      }
      return $response;
    } catch (\Throwable $e) {
      return $e;
    }
  }

  public function getMasterPricelist(string $username, string $password, int $branch_id)
  {
    try {
      $response = Http::post($this->endpoint . '/pos/setup/get_pricelist/' . $branch_id, [
        'username' => $username,
        'password' => $password,
      ]);


      if ($response->json('code') == 0) {

        MasterPricelistModel::truncate();
        MasterPricelistModel::insert($response->json('data'));
      } else {
      }
      return $response;
    } catch (\Throwable $e) {
      return $e;
    }
  }


  public function getMasterPricelistDetail(string $username, string $password, int $branch_id)
  {
    try {
      $response = Http::post($this->endpoint . '/pos/setup/get_pricelist_detail/' . $branch_id, [
        'username' => $username,
        'password' => $password,
      ]);
      if ($response->json('code') == 0) {
        MasterPricelistDetailModel::truncate();
        MasterPricelistDetailModel::insert($response->json('data'));
      } else {
      }
      return $response;
    } catch (\Throwable $e) {
      return $e;
    }
  }

  /////


  public function getMasterPaymentMethod(string $username, string $password, int $branch_id)
  {
    try {
      $response = Http::post($this->endpoint . '/pos/setup/get_payment_method/' . $branch_id, [
        'username' => $username,
        'password' => $password,
      ]);

      Log:
      info($response->json("data"));

      if ($response->json('code') == 0) {
        MasterPaymentMethodModel::truncate();
        MasterPaymentMethodModel::insert($response->json('data'));
      } else {
      }
      return $response;
    } catch (\Throwable $e) {
      return $e;
    }
  }


  public function getMasterPaymentMethodGroup(string $username, string $password, int $branch_id)
  {
    try {
      $response = Http::post($this->endpoint . '/pos/setup/get_payment_method_group/' . $branch_id, [
        'username' => $username,
        'password' => $password,
      ]);
      if ($response->json('code') == 0) {
        MasterPaymentMethodGroupModel::truncate();
        MasterPaymentMethodGroupModel::insert($response->json('data'));
      } else {
      }
      return $response;
    } catch (\Throwable $e) {
      return $e;
    }
  }

  public function getMasterPaymentMethodType(string $username, string $password, int $branch_id)
  {
    try {
      $response = Http::post($this->endpoint . '/pos/setup/get_payment_method_type/' . $branch_id, [
        'username' => $username,
        'password' => $password,
      ]);
      if ($response->json('code') == 0) {
        MasterPaymentMethodTypeModel::truncate();
        MasterPaymentMethodTypeModel::insert($response->json('data'));
      } else {
      }
      return $response;
    } catch (\Throwable $e) {
      return $e;
    }
  }

  public function getMasterPaymentMethodVisitPurpose(string $username, string $password, int $branch_id)
  {
    try {
      $response = Http::post($this->endpoint . '/pos/setup/get_payment_method_visit_purpose/' . $branch_id, [
        'username' => $username,
        'password' => $password,
      ]);
      if ($response->json('code') == 0) {
        MasterPaymentMethodVisitPurposeModel::truncate();
        MasterPaymentMethodVisitPurposeModel::insert($response->json('data'));
      } else {
      }
      return $response;
    } catch (\Throwable $e) {
      return $e;
    }
  }

  public function getMasterBranchVisitPurpose(string $username, string $password, int $branch_id)
  {
    try {
      $response = Http::post($this->endpoint . '/pos/setup/get_branch_visit_purpose/' . $branch_id, [
        'username' => $username,
        'password' => $password,
      ]);
      if ($response->json('code') == 0) {
        MasterBranchVisitPurposeModel::truncate();
        MasterBranchVisitPurposeModel::insert($response->json('data'));
      } else {
      }
      return $response;
    } catch (\Throwable $e) {
      return $e;
    }
  }

  public function getMasterVisitPurpose(string $username, string $password, int $branch_id)
  {
    try {
      $response = Http::post($this->endpoint . '/pos/setup/get_visit_purpose/' . $branch_id, [
        'username' => $username,
        'password' => $password,
      ]);
      if ($response->json('code') == 0) {
        MasterVisitPurposeModel::truncate();
        MasterVisitPurposeModel::insert($response->json('data'));
      } else {
      }
      return $response;
    } catch (\Throwable $e) {
      return $e;
    }
  }

  public function getTableSectionPrintCategorySetting(string $username, string $password, int $branch_id)
  {
    try {
      $response = Http::post($this->endpoint . '/pos/setup/get_table_section_print_category_setting/' . $branch_id, [
        'username' => $username,
        'password' => $password,
      ]);

      Log::info("==========================");
      Log::info($response);
      if ($response->json('code') == 0) {

        MasterTableSectionPrintCategorySettingModel::truncate();
        MasterTableSectionPrintCategorySettingModel::insert($response->json('data'));
      } else {
      }
      return $response;
    } catch (\Throwable $e) {
      return $e;
    }
  }
}
