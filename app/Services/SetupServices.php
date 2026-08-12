<?php

namespace App\Services;

use App\Models\BranchModel;
use App\Models\CategoryModel;
use App\Models\MasterBranchOpsSettingModel;
use App\Models\MasterImageListApplyForModel;
use App\Models\MasterImageListModel;
use App\Models\MasterImageModel;
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
    $this->endpoint = config('services.server_endpoint', '');
  }

  private function syncRequest(string $username, string $password, ?string $token, string $url): \Illuminate\Http\Client\Response
  {
    if ($token) {
      return Http::withToken($token)->get($url);
    }
    return Http::post($url, ['username' => $username, 'password' => $password]);
  }

  // upsertRows: dipakai buat sebagian besar sync master data -- baris yang id-nya udah ada
  // di lokal di-update, yang baru di-insert. Baris lokal yang gak ada lagi di response TIDAK
  // dihapus (beda dari pola truncate+insert lama) -- data yang dihapus/dinonaktifkan di server
  // bakal tetap nyangkut di lokal sampai ditangani terpisah (belum diminta/digarap).
  // Pengecualian yang TETAP truncate+insert (replace): getMasterUser (data akses login/user,
  // harus selalu cerminan pasti dari server) dan getTableSectionPrintCategorySetting (id dari
  // server gak reliable buat dedup, lihat catatan di fungsi itu).
  private function upsertRows(string $modelClass, array $rows, string $uniqueBy = 'id'): void
  {
    if (empty($rows)) {
      return;
    }

    $modelClass::upsert($rows, [$uniqueBy]);
  }

  public function getDatabranch(string $username, string $password, string $branch_id, ?string $token = null)
  {
    try {
      $url = $token
        ? $this->endpoint . '/pos/sync/get_data_branch/' . $branch_id
        : $this->endpoint . '/pos/setup/get_data_branch/' . $branch_id;
      $response = $this->syncRequest($username, $password, $token, $url);

      if ($response->json('code') == 0) {
        // ambil dulu sebelum truncate -- dipakai fallback kalau download gambar gagal (bukan
        // di-null-in, lihat catatan di downloadImage()).
        $existing = BranchModel::first();

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
          'logo_header_src' => $this->downloadImage($response->json('data.LogoHeaderSrc'), 'branch', $existing->logo_header_src ?? null),
          'image_footer_src' => $this->downloadImage($response->json('data.ImageFooterSrc'), 'branch', $existing->image_footer_src ?? null),
        ]);
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  // downloadImage: tarik gambar dari server ERP (path relatif, mis. /storage/uploads/images/xxx.png)
  // terus simpen lokal di public/img/{subdir}/, dipakai buat branch (logo/footer) & item (menu).
  // $fallback: nilai lokal yang lama (kalau ada) -- dibalikin kalau download gagal (network
  // hiccup, timeout, dll), BUKAN null. Soalnya sync sekarang jalan berkali-kali (upsert, bukan
  // truncate) -- kalau gagal download langsung dibalikin null, gambar yang sebelumnya udah
  // bener-bener kepakai bisa "ilang" cuma gara-gara 1 kegagalan network sesaat. $remotePath
  // kosong tetep dianggap "emang gak ada gambar" (sengaja dihapus di server), bukan kegagalan --
  // itu masih balikin null, bukan fallback.
  private function downloadImage(?string $remotePath, string $subdir, ?string $fallback = null): ?string
  {
    if (empty($remotePath)) {
      return null;
    }

    try {
      $imageUrl = $this->endpoint . $remotePath;
      $imageResponse = Http::get($imageUrl);

      if (!$imageResponse->successful()) {
        Log::warning('Gagal download image, remote status ' . $imageResponse->status() . ': ' . $imageUrl);
        return $fallback;
      }

      $contents = $imageResponse->body();
      $filename = basename($remotePath);
      $dir = public_path('img/' . $subdir);

      if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
      }

      file_put_contents($dir . '/' . $filename, $contents);

      return '/img/' . $subdir . '/' . $filename;
    } catch (\Throwable $e) {
      Log::warning('Gagal download image: ' . $e->getMessage());
      return $fallback;
    }
  }


  public function getStationList(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest(
        $username,
        $password,
        $token,
        $this->endpoint . '/pos/sync/get_station_list/' . $branch_id
      );

      if ($response->json('code') == 0) {
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
        $this->upsertRows(StationModel::class, $datastation);
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }


  public function getCategoryList(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest(
        $username,
        $password,
        $token,
        $this->endpoint . '/pos/sync/get_category_list/' . $branch_id
      );

      if ($response->json('code') == 0) {
        $this->upsertRows(CategoryModel::class, $response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public function getSubCategoryList(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest(
        $username,
        $password,
        $token,
        $this->endpoint . '/pos/sync/get_subcategory_list/' . $branch_id
      );

      if ($response->json('code') == 0) {
        $this->upsertRows(SubCategoryModel::class, $response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public function getTableSectionList(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest(
        $username,
        $password,
        $token,
        $this->endpoint . '/pos/sync/get_tablesection_list/' . $branch_id
      );

      if ($response->json('code') == 0) {
        $this->upsertRows(TableSectionModel::class, $response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public function getTable(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest(
        $username,
        $password,
        $token,
        $this->endpoint . '/pos/sync/get_table/' . $branch_id
      );

      if ($response->json('code') == 0) {
        $this->upsertRows(TableModel::class, $response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public function getTax(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest(
        $username,
        $password,
        $token,
        $this->endpoint . '/pos/sync/get_tax/' . $branch_id
      );

      if ($response->json('code') == 0) {
        $this->upsertRows(MasterTaxModel::class, $response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public function getTerminal(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest(
        $username,
        $password,
        $token,
        $this->endpoint . '/pos/sync/get_terminal/' . $branch_id
      );

      if ($response->json('code') == 0) {
        $this->upsertRows(TerminalModel::class, $response->json('data'));
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
      $response = $this->syncRequest(
        $username,
        $password,
        $token,
        $this->endpoint . '/pos/sync/get_item/' . $branch_id
      );

      if ($response->json('code') == 0) {
        $items = $response->json("data");

        // fallback per item -- gambar lokal yang lama, dipakai kalau download gagal (bukan
        // di-null-in, lihat catatan di downloadImage()).
        $existingImages = MasterItemModel::whereIn('id', array_column($items, 'id'))
          ->pluck('image', 'id');

        foreach ($items as &$item) {
          $item['image'] = $this->downloadImage(
            $item['image'] ?? null,
            'item',
            $existingImages[$item['id']] ?? null
          );
        }
        unset($item);

        $this->upsertRows(MasterItemModel::class, $items);
      }

      return $response;
    } catch (Throwable $e) {
      throw $e;
    }
  }

  public function getMasterItemConv(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest(
        $username,
        $password,
        $token,
        $this->endpoint . '/pos/sync/get_item_conv/' . $branch_id
      );

      if ($response->json('code') == 0) {
        $this->upsertRows(MasterItemConvModel::class, $response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public function getMasterItemPackage(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest(
        $username,
        $password,
        $token,
        $this->endpoint . '/pos/sync/get_item_package/' . $branch_id
      );

      if ($response->json('code') == 0) {
        $this->upsertRows(MasterItemPackageModel::class, $response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public function getMasterItemPackageGroup(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest(
        $username,
        $password,
        $token,
        $this->endpoint . '/pos/sync/get_item_package_group/' . $branch_id
      );

      Log::info($response);

      if ($response->json('code') == 0) {
        $this->upsertRows(MasterItemPackageGroupModel::class, $response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public function getMasterItemPackageDetail(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest(
        $username,
        $password,
        $token,
        $this->endpoint . '/pos/sync/get_item_package_detail/' . $branch_id
      );

      if ($response->json('code') == 0) {
        $this->upsertRows(MasterItemPackageDetailModel::class, $response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public function getMasterPricelist(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest(
        $username,
        $password,
        $token,
        $this->endpoint . '/pos/sync/get_pricelist/' . $branch_id
      );

      if ($response->json('code') == 0) {
        $this->upsertRows(MasterPricelistModel::class, $response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public function getMasterPricelistDetail(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest(
        $username,
        $password,
        $token,
        $this->endpoint . '/pos/sync/get_pricelist_detail/' . $branch_id
      );

      if ($response->json('code') == 0) {
        $this->upsertRows(MasterPricelistDetailModel::class, $response->json('data'));
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
      $response = $this->syncRequest(
        $username,
        $password,
        $token,
        $this->endpoint . '/pos/sync/get_payment_method/' . $branch_id
      );

      Log::info($response->json("data"));

      if ($response->json('code') == 0) {
        $this->upsertRows(MasterPaymentMethodModel::class, $response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public function getMasterPaymentMethodGroup(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest(
        $username,
        $password,
        $token,
        $this->endpoint . '/pos/sync/get_payment_method_group/' . $branch_id
      );

      if ($response->json('code') == 0) {
        $this->upsertRows(MasterPaymentMethodGroupModel::class, $response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public function getMasterPaymentMethodType(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest(
        $username,
        $password,
        $token,
        $this->endpoint . '/pos/sync/get_payment_method_type/' . $branch_id
      );

      if ($response->json('code') == 0) {
        $this->upsertRows(MasterPaymentMethodTypeModel::class, $response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public function getMasterPaymentMethodVisitPurpose(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest(
        $username,
        $password,
        $token,
        $this->endpoint . '/pos/sync/get_payment_method_visit_purpose/' . $branch_id
      );

      if ($response->json('code') == 0) {
        $this->upsertRows(MasterPaymentMethodVisitPurposeModel::class, $response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public function getMasterBranchVisitPurpose(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest(
        $username,
        $password,
        $token,
        $this->endpoint . '/pos/sync/get_branch_visit_purpose/' . $branch_id
      );

      if ($response->json('code') == 0) {
        $this->upsertRows(MasterBranchVisitPurposeModel::class, $response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public function getMasterBranchOpsSetting(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest(
        $username,
        $password,
        $token,
        $this->endpoint . '/pos/sync/get_branch_ops_setting/' . $branch_id
      );

      if ($response->json('code') == 0) {
        $this->upsertRows(MasterBranchOpsSettingModel::class, $response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  // getMasterImage/getMasterImageList/getMasterImageListApplyFor: 3 endpoint flat terpisah
  // buat data nested master_image (header -> image_list -> apply_for) di ERP, ngikutin pola
  // getMasterItemPackage/_Group/_Detail -- upsert-by-id, gak ada penghapusan baris lokal yang
  // udah gak ada lagi di server (limitasi yang sama & diterima kayak pull lain, lihat SYNC PULL.md).
  public function getMasterImage(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest(
        $username,
        $password,
        $token,
        $this->endpoint . '/pos/sync/get_master_image/' . $branch_id
      );

      if ($response->json('code') == 0) {
        $this->upsertRows(MasterImageModel::class, $response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public function getMasterImageList(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest(
        $username,
        $password,
        $token,
        $this->endpoint . '/pos/sync/get_master_image_list/' . $branch_id
      );

      if ($response->json('code') == 0) {
        $list = $response->json("data");

        // image_src dari ERP itu path relatif ke server ERP (file fisiknya ada di sana, bukan
        // di POS) -- didownload dulu ke lokal (sama pola kayak MasterItemModel::image), biar
        // Kiosk/POS bisa nampilin tanpa gantung koneksi ke ERP tiap kali gambar di-render.
        $existingImages = MasterImageListModel::whereIn('id', array_column($list, 'id'))
          ->pluck('image_src', 'id');

        foreach ($list as &$item) {
          $item['image_src'] = $this->downloadImage(
            $item['image_src'] ?? null,
            'master-image',
            $existingImages[$item['id']] ?? null
          );
        }
        unset($item);

        $this->upsertRows(MasterImageListModel::class, $list);
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public function getMasterImageListApplyFor(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest(
        $username,
        $password,
        $token,
        $this->endpoint . '/pos/sync/get_master_image_list_apply_for/' . $branch_id
      );

      if ($response->json('code') == 0) {
        $this->upsertRows(MasterImageListApplyForModel::class, $response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public function getMasterVisitPurpose(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest(
        $username,
        $password,
        $token,
        $this->endpoint . '/pos/sync/get_visit_purpose/' . $branch_id
      );

      if ($response->json('code') == 0) {
        $this->upsertRows(MasterVisitPurposeModel::class, $response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  // getMasterUser: SENGAJA tetap truncate+insert (replace), bukan upsert -- ini data
  // login/akses user, harus selalu cerminan pasti dari server (user yang dihapus/dinonaktifkan
  // di server wajib ikut hilang di lokal, gak boleh nyangkut).
  public function getMasterUser(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest(
        $username,
        $password,
        $token,
        $this->endpoint . '/pos/sync/get_master_user/' . $branch_id
      );

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
      $response = $this->syncRequest(
        $username,
        $password,
        $token,
        $this->endpoint . '/pos/sync/get_master_role_access/' . $branch_id
      );

      if ($response->json('code') == 0) {
        $this->upsertRows(RoleAccessModel::class, $response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public function getMenuApp(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest(
        $username,
        $password,
        $token,
        $this->endpoint . '/pos/sync/get_menu_app/' . $branch_id
      );

      if ($response->json('code') == 0) {
        $this->upsertRows(MasterMenuAppModel::class, $response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  // getTableSectionPrintCategorySetting: SENGAJA tetap truncate+insert (replace), bukan
  // upsert -- id dari APIANDORDER dibuang (lihat komentar di bawah) karena bisa kembar antar
  // baris, jadi gak ada kolom unik yang aman dipakai buat dedup upsert.
  public function getTableSectionPrintCategorySetting(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest(
        $username,
        $password,
        $token,
        $this->endpoint . '/pos/sync/get_table_section_print_category_setting/' . $branch_id
      );

      Log::info("==========================");
      Log::info($response);

      if ($response->json('code') == 0) {
        // 'id' dari APIANDORDER gak dipakai — table section yang di-link ke table section
        // lain (print_category_setting_link) bisa balikin id sumber yang sama untuk lebih
        // dari 1 table_section_id, bentrok kalau ikut di-insert (PK mr_table_section_print_category_setting
        // cuma kolom id tunggal). Biarkan MySQL auto-increment yang generate id lokal.
        $data = collect($response->json('data'))->map(function ($row) {
          unset($row['id']);
          return $row;
        })->all();

        MasterTableSectionPrintCategorySettingModel::truncate();
        MasterTableSectionPrintCategorySettingModel::insert($data);
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
   * entirely instead of being `null`. Model::insert()/upsert() builds one SQL statement from the
   * column list of the first row, so rows with different key sets break with "column count
   * doesn't match value count". Pad every row to the same set of keys before inserting/upserting.
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
      $response = $this->syncRequest(
        $username,
        $password,
        $token,
        $this->endpoint . '/pos/sync/get_promo_list/' . $branch_id
      );

      if ($response->json('code') == 0) {
        $this->upsertRows(MasterPromoModel::class, $this->normalizeInsertRows($response->json('data')));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public function getPromoBranch(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest(
        $username,
        $password,
        $token,
        $this->endpoint . '/pos/sync/get_promo_branch/' . $branch_id
      );

      if ($response->json('code') == 0) {
        $this->upsertRows(MasterPromoBranchesModel::class, $response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public function getPromoVisitPurpose(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest(
        $username,
        $password,
        $token,
        $this->endpoint . '/pos/sync/get_promo_visit_purpose/' . $branch_id
      );

      if ($response->json('code') == 0) {
        $this->upsertRows(MasterPromoVisitPurposesModel::class, $response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public function getPromoTypeMember(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest(
        $username,
        $password,
        $token,
        $this->endpoint . '/pos/sync/get_promo_type_member/' . $branch_id
      );

      if ($response->json('code') == 0) {
        $this->upsertRows(MasterPromoTypeMembersModel::class, $response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public function getPromoCategory(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest(
        $username,
        $password,
        $token,
        $this->endpoint . '/pos/sync/get_promo_category/' . $branch_id
      );

      if ($response->json('code') == 0) {
        $this->upsertRows(MasterPromoCategoriesModel::class, $response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public function getPromoSubCategory(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest(
        $username,
        $password,
        $token,
        $this->endpoint . '/pos/sync/get_promo_sub_category/' . $branch_id
      );

      if ($response->json('code') == 0) {
        $this->upsertRows(MasterPromoSubCategoriesModel::class, $response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public function getPromoItem(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest(
        $username,
        $password,
        $token,
        $this->endpoint . '/pos/sync/get_promo_item/' . $branch_id
      );

      if ($response->json('code') == 0) {
        $this->upsertRows(MasterPromoItemsModel::class, $response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public function getPromoDay(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest(
        $username,
        $password,
        $token,
        $this->endpoint . '/pos/sync/get_promo_day/' . $branch_id
      );

      if ($response->json('code') == 0) {
        $this->upsertRows(MasterPromoDaysModel::class, $response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public function getPromoTime(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest(
        $username,
        $password,
        $token,
        $this->endpoint . '/pos/sync/get_promo_time/' . $branch_id
      );

      if ($response->json('code') == 0) {
        $this->upsertRows(MasterPromoTimesModel::class, $response->json('data'));
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
      $response = $this->syncRequest(
        $username,
        $password,
        $token,
        $this->endpoint . '/pos/sync/get_member_type_list/' . $branch_id
      );

      if ($response->json('code') == 0) {
        $this->upsertRows(MasterMemberTypeModel::class, $this->normalizeInsertRows($response->json('data')));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  public function getMemberList(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest(
        $username,
        $password,
        $token,
        $this->endpoint . '/pos/sync/get_member_list/' . $branch_id
      );

      if ($response->json('code') == 0) {
        $this->upsertRows(MasterMemberModel::class, $this->normalizeInsertRows($response->json('data')));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }
}
