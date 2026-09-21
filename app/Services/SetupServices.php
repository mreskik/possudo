<?php

namespace App\Services;

use App\Models\BranchModel;
use App\Models\CategoryModel;
use App\Models\MasterBranchOpsSettingModel;
use App\Models\MasterImageCustomerDisplayModel;
use App\Models\MasterImageKioskModel;
use App\Models\MasterBranchVisitPurposeModel;
use App\Models\MasterItemConvModel;
use App\Models\MasterItemModel;
use App\Models\MasterItemPackageDetailModel;
use App\Models\MasterItemPackageDetailPricelistModel;
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
use App\Models\MasterPromoApplyToModel;
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

  // connectTimeout(5): kalau internet mati, koneksi TCP ke server ERP gak akan pernah kebentuk --
  // gagal cepat (5 detik) daripada gantung lama nungguin OS-level TCP timeout (bisa >1 menit).
  // timeout(15): batas keseluruhan request (connect + tunggu response) kalau koneksi kebentuk
  // tapi server lambat/gak respon. Sebelum ini gak ada timeout sama sekali di request sync ke
  // ERP, jadi tombol Sync di frontend bisa "ngegantung" lama kalau internet mati.
  private function syncRequest(string $username, string $password, ?string $token, string $url): \Illuminate\Http\Client\Response
  {
    if ($token) {
      return Http::withToken($token)->connectTimeout(5)->timeout(15)->withOptions(['verify' => config('services.http_verify_ssl')])->get($url);
    }
    return Http::connectTimeout(5)->timeout(15)->withOptions(['verify' => config('services.http_verify_ssl')])->post($url, ['username' => $username, 'password' => $password]);
  }

  // upsertRows: dipakai buat sebagian besar sync master data -- baris yang id-nya udah ada
  // di lokal di-update, yang baru di-insert. Baris lokal yang gak ada lagi di response TIDAK
  // dihapus (beda dari pola truncate+insert lama) -- data yang dihapus/dinonaktifkan di server
  // bakal tetap nyangkut di lokal sampai ditangani terpisah (belum diminta/digarap).
  // Pengecualian yang TETAP truncate+insert (replace): getMasterUser (data akses login/user,
  // harus selalu cerminan pasti dari server), getTableSectionPrintCategorySetting (id dari
  // server gak reliable buat dedup, lihat catatan di fungsi itu), getMasterBranchVisitPurpose,
  // dan getMasterPaymentMethodVisitPurpose (2026-08-31, sama alasan: baris yang link/config-nya
  // DIHAPUS di ERP gak akan pernah ikut kehapus di lokal kalau upsert).
  private function upsertRows(string $modelClass, array $rows, string $uniqueBy = 'id'): void
  {
    if (empty($rows)) {
      return;
    }

    $modelClass::upsert($this->normalizeInsertRows($rows), [$uniqueBy]);
  }

  // insertRows: truncate()+insert() punya masalah yang SAMA persis kayak upsertRows() (dipakai
  // buat semua fungsi truncate+insert di bawah) -- Model::insert() juga 1 statement SQL dari
  // kolom baris pertama, jadi rawan "column count doesn't match value count" kalau ada baris
  // yang key-nya beda (lihat normalizeInsertRows()). Helper ini biar semua call site truncate+
  // insert otomatis ke-normalize juga, gak ketinggalan kayak upsertRows() sebelumnya.
  private function insertRows(string $modelClass, array $rows): void
  {
    if (empty($rows)) {
      return;
    }

    $modelClass::insert($this->normalizeInsertRows($rows));
  }

  public function getDatabranch(string $username, string $password, string $branch_id, ?string $token = null)
  {
    try {
      $url = $token
        ? $this->endpoint . '/pos/sync/get_data_branch/' . $branch_id
        : $this->endpoint . '/pos/setup/get_data_branch/' . $branch_id;
      $response = $this->syncRequest($username, $password, $token, $url);

      if ($response->json('code') == 0) {
        // company_id/token itu identitas inti branch (dipakai buat semua request sync
        // berikutnya) DAN kolomnya NOT NULL di lokal -- kalau ERP somehow gak ngirim salah
        // satunya, mending gagal jelas di sini (pesan yang nunjuk akar masalahnya) daripada
        // BranchModel::create() di bawah gagal samar dengan Integrity constraint violation.
        if ($response->json('data.CompanyId') === null || $response->json('data.Token') === null) {
          throw new \Exception('Respons get_data_branch dari ERP gak lengkap (CompanyId/Token kosong) -- branch: ' . $branch_id);
        }

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
      $imageResponse = Http::withOptions(['verify' => config('services.http_verify_ssl')])->get($imageUrl);

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

  // getSubCategoryList: SENGAJA gak download gambar di sini lagi (2026-09-21, dipisah) -- lihat
  // getSubCategoryImages() di bawah. Alasan: downloadImage() itu HTTP request sinkron per baris
  // (2 gambar x N subcategory), jadi sync data teks jadi lambat nungguin semua gambar kedownload
  // duluan sebelum satu baris pun tersimpan. Sekarang icon_src/banner_src dipertahankan APA
  // ADANYA dari row lokal yang udah ada (gak ketimpa null, gak nyoba download) -- baris BARU
  // (belum pernah ada) otomatis dapet null sampai getSubCategoryImages() dipanggil nyusul.
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
        $list = $response->json("data");

        $existingIcons = SubCategoryModel::whereIn('id', array_column($list, 'id'))
          ->pluck('icon_src', 'id');
        $existingBanners = SubCategoryModel::whereIn('id', array_column($list, 'id'))
          ->pluck('banner_src', 'id');

        foreach ($list as &$item) {
          $item['icon_src'] = $existingIcons[$item['id']] ?? null;
          $item['banner_src'] = $existingBanners[$item['id']] ?? null;
        }
        unset($item);

        $this->upsertRows(SubCategoryModel::class, $list);
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  // getSubCategoryImages (2026-09-21): endpoint TERPISAH khusus download gambar subcategory --
  // manggil endpoint ERP yang SAMA kayak getSubCategoryList() (server gak perlu diubah, response
  // ERP-nya udah punya icon_src/banner_src dari awal), tapi CUMA proses kolom gambar. Baris yang
  // id-nya belum ada di lokal (belum pernah di-sync getSubCategoryList()) DI-SKIP -- endpoint ini
  // gak pernah insert baris baru, itu tugas getSubCategoryList(). UPDATE per-baris (bukan
  // upsertRows()) biar kolom lain (name, dst) gak ikut kesentuh sama sekali.
  public function getSubCategoryImages(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest(
        $username,
        $password,
        $token,
        $this->endpoint . '/pos/sync/get_subcategory_list/' . $branch_id
      );

      if ($response->json('code') == 0) {
        $list = $response->json("data");

        $existingRows = SubCategoryModel::whereIn('id', array_column($list, 'id'))
          ->get(['id', 'icon_src', 'banner_src'])
          ->keyBy('id');

        foreach ($list as $item) {
          $existing = $existingRows->get($item['id']);
          if (!$existing) {
            continue;
          }

          SubCategoryModel::where('id', $item['id'])->update([
            'icon_src' => $this->downloadImage($item['icon_src'] ?? null, 'subcategory', $existing->icon_src),
            'banner_src' => $this->downloadImage($item['banner_src'] ?? null, 'subcategory-banner', $existing->banner_src),
          ]);
        }
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

  // getMasterItem: SENGAJA gak download gambar di sini lagi (2026-09-21, dipisah) -- lihat
  // getMasterItemImages() di bawah. Alasan sama kayak getSubCategoryList()/getSubCategoryImages():
  // downloadImage() sinkron per baris bikin sync data teks lambat. image/icon_src dipertahankan
  // apa adanya dari row lokal yang udah ada.
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

        $existingImages = MasterItemModel::whereIn('id', array_column($items, 'id'))
          ->pluck('image', 'id');
        $existingIcons = MasterItemModel::whereIn('id', array_column($items, 'id'))
          ->pluck('icon_src', 'id');

        foreach ($items as &$item) {
          $item['image'] = $existingImages[$item['id']] ?? null;
          $item['icon_src'] = $existingIcons[$item['id']] ?? null;
        }
        unset($item);

        $this->upsertRows(MasterItemModel::class, $items);
      }

      return $response;
    } catch (Throwable $e) {
      throw $e;
    }
  }

  // getMasterItemImages (2026-09-21): endpoint TERPISAH khusus download gambar item -- sama pola
  // persis getSubCategoryImages(), lihat catatan lengkap di situ. Manggil endpoint ERP yang SAMA
  // kayak getMasterItem(), tapi cuma proses image/icon_src, UPDATE per-baris, skip id yang belum
  // ada di lokal.
  public function getMasterItemImages(string $username, string $password, int $branch_id, ?string $token = null)
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

        $existingRows = MasterItemModel::whereIn('id', array_column($items, 'id'))
          ->get(['id', 'image', 'icon_src'])
          ->keyBy('id');

        foreach ($items as $item) {
          $existing = $existingRows->get($item['id']);
          if (!$existing) {
            continue;
          }

          MasterItemModel::where('id', $item['id'])->update([
            'image' => $this->downloadImage($item['image'] ?? null, 'item', $existing->image),
            'icon_src' => $this->downloadImage($item['icon_src'] ?? null, 'item-icon', $existing->icon_src),
          ]);
        }
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

  // getMasterItemPackageGroup/getMasterItemPackageDetail: TETAP truncate()+insert() (bukan
  // upsertRows() kayak sync pull lain) -- ERP (sudocore2) replace-all baris
  // master_item_package_group/detail tiap admin edit package config 1 item (lihat
  // ReplacePackageGroups() di item_service.go), id-nya SELALU baru tiap edit, gak pernah
  // dipertahanin. Kalau di sini pakai upsert-by-id, id lama yang udah dihapus di ERP gak
  // pernah ikut kehapus di lokal (upsert emang gak nge-delete) -- baris nyampah menumpuk
  // TIAP KALI package item itu diedit, bukan cuma occasional stale data biasa.
  public function getMasterItemPackageGroup(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest(
        $username,
        $password,
        $token,
        $this->endpoint . '/pos/sync/get_item_package_group/' . $branch_id
      );

      if ($response->json('code') == 0) {
        MasterItemPackageGroupModel::truncate();
        $this->insertRows(MasterItemPackageGroupModel::class, $response->json('data'));
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
        MasterItemPackageDetailModel::truncate();
        $this->insertRows(MasterItemPackageDetailModel::class, $response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  // getMasterItemPackageDetailPricelist: override harga sub-item package per pricelist
  // (2026-08-26) -- dikonsumsi kalau mr_item_package_detail.flag_all_menu_template = false.
  // truncate+insert, sama pola & alasan kayak getMasterItemPackageDetail() di atas.
  public function getMasterItemPackageDetailPricelist(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest(
        $username,
        $password,
        $token,
        $this->endpoint . '/pos/sync/get_item_package_detail_pricelist/' . $branch_id
      );

      if ($response->json('code') == 0) {
        MasterItemPackageDetailPricelistModel::truncate();
        $this->insertRows(MasterItemPackageDetailPricelistModel::class, $response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  // getMasterPricelist/getMasterPricelistDetail: SENGAJA truncate+insert (bukan upsertRows),
  // beda dari kebanyakan fungsi lain di file ini (2026-09-21, disepakati) -- item yang di-takeout
  // dari menu template di ERP harus BENERAN ilang dari lokal, bukan nyangkut terus (upsert gak
  // pernah hapus baris yang gak ada lagi di response). Aman dilakukan: pricelist_detail_id yang
  // disimpen di tr_order_detail (OrderServices.php, MobileOrderPullServices.php,
  // KioskController.php) TIDAK PERNAH di-JOIN balik ke mr_pricelist_detail buat histori/reprint
  // struk -- semua data yang ditampilkan ulang (nama, harga, pajak) udah snapshot sendiri di
  // kolom tr_order_detail, jadi id mr_pricelist_detail yang berubah/hilang gak ngerusak order
  // lama. Juga gak ada FK constraint keras di DB (pricelist_detail_id cuma unsignedBigInteger
  // polos). Urutan panggil header (getMasterPricelist) SEBELUM detail (getMasterPricelistDetail)
  // tetap dipertahankan (lihat SetupPage.vue array sync), walau truncate+insert di sini gak
  // saling depend secara FK.
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
        MasterPricelistModel::truncate();
        $this->insertRows(MasterPricelistModel::class, $response->json('data'));
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
        MasterPricelistDetailModel::truncate();
        $this->insertRows(MasterPricelistDetailModel::class, $response->json('data'));
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

  // getMasterPaymentMethodGroup: truncate+insert (bukan upsertRows, 2026-09-21, disepakati
  // setelah audit) -- aman karena TIDAK ADA tabel transaksi (tr_order_payment dkk) yang refer ke
  // mr_payment_method_group.id secara langsung (cuma mr_payment_method.group_payment_id yang
  // refer, itu pun cuma di-LEFT JOIN dari master ke master di MasterController.php, gak pernah
  // di-INNER-JOIN dari histori). Group yang di-takeout di ERP sekarang beneran ilang dari lokal.
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
        MasterPaymentMethodGroupModel::truncate();
        $this->insertRows(MasterPaymentMethodGroupModel::class, $response->json('data'));
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

  // getMasterPaymentMethodVisitPurpose: truncate+insert (BUKAN upsertRows lagi, disepakati
  // 2026-08-31) -- pola SAMA kayak getMasterBranchVisitPurpose() (lihat catatan di situ, 2026-08-26)
  // dan alasannya SAMA PERSIS: link yang udah DIHAPUS di ERP gak akan pernah ikut kehapus di
  // lokal kalau pakai upsert (upsert emang gak nge-delete) -- ketemu langsung kasusnya: baris
  // CASH->TAKEAWAY yang udah lama dihapus di ERP tetep nyangkut di mr_payment_method_visit_purposes
  // lokal, bikin dobel muncul di GET /api/master/payment-method/{visit_purpose_id} pas ERP-nya
  // diisi ulang. Gak ada tabel lokal POS yang refer ke mr_payment_method_visit_purposes.id
  // (dicek information_schema, 0 hasil), jadi aman ganti pola.
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
        MasterPaymentMethodVisitPurposeModel::truncate();
        $this->insertRows(MasterPaymentMethodVisitPurposeModel::class, $response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  // getMasterBranchVisitPurpose: truncate+insert (BUKAN upsertRows lagi, 2026-08-26) -- tabel
  // ini murni config per branch (service_charge/vat/pb1/order_fee/pricelist_id), gak ada kolom
  // lokal yang perlu dipertahankan antar sync. Kalau pake upsert, visit purpose yang udah
  // DIHAPUS di ERP gak akan pernah ikut kehapus di lokal (upsert emang gak nge-delete) --
  // numpuk jadi setting basi selamanya. Gak ada tabel lokal POS yang refer ke
  // mr_branch_visit_purpose.id (dicek: 0 hasil informasi_schema), jadi aman ganti pola.
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
        MasterBranchVisitPurposeModel::truncate();
        $this->insertRows(MasterBranchVisitPurposeModel::class, $response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  // getMasterBranchOpsSetting: truncate+insert (bukan upsertRows, 2026-09-21, disepakati setelah
  // audit) -- aman karena NOL kolom di tabel manapun yang refer ke mr_branch_ops_setting.id
  // (dikonsumsi selalu lewat kolom `day`, bukan `id` -- lihat DayShiftServices.php/
  // KioskController.php). Cuma 7 baris (1 per hari), jadwal yang diubah/dihapus di ERP sekarang
  // beneran ke-reflect di lokal -- upsert di sini justru bug (baris lama nyangkut selamanya).
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
        MasterBranchOpsSettingModel::truncate();
        $this->insertRows(MasterBranchOpsSettingModel::class, $response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  // getMasterImageCustomerDisplay/getMasterImageKiosk: 2 endpoint flat per-channel buat data
  // master_image di ERP (2026-08-24, ganti dari header -> image_list -> apply_for jadi
  // header -> customer_display / kiosk, 2 tabel eksplisit sejajar).
  //
  // truncate+insert (BUKAN upsertRows lagi, 2026-09-04) -- awalnya ngikutin pola
  // getMasterItemPackage/_Group/_Detail (upsert-by-id), tapi beda kasus: banner campaign yang
  // dihapus/dinonaktifkan di ERP gak akan pernah ikut kehapus di lokal kalau upsert (upsert
  // emang gak nge-delete) -- sama alasan persis kayak getMasterBranchVisitPurpose()/
  // getMasterPaymentMethodVisitPurpose() (lihat catatan di situ). Gak ada tabel lokal POS yang
  // refer ke mr_image_kiosk.id/mr_image_customer_display.id, jadi aman ganti pola. Gambar fisik
  // yang udah kedownload di public/img/master-image/ buat banner yang kehapus TETAP nyangkut
  // di disk (orphan file, bukan masalah correctness, cuma sisa disk -- belum ada cleanup-nya).
  //
  // getMasterImage() (endpoint generic pra-restrukturisasi, tabel mr_master_image) DIHAPUS
  // 2026-08-31 -- gak download gambar-nya (beda dari 2 fungsi di bawah, yang manggil
  // downloadImage()) DAN gak ada satupun komponen frontend yang render dari tabel itu lagi,
  // udah kegantiin total sama 2 channel di bawah. Sync-nya sia-sia (nyimpen path mentah ERP
  // yang gak pernah dipakai), dicabut dari install sequence (Navbar.vue/SetupPage.vue) juga.
  // getMasterImageCustomerDisplay: SENGAJA gak download gambar di sini lagi (2026-09-21, dipisah)
  // -- lihat getMasterImageCustomerDisplayImages() di bawah. Fallback (banner_src lama, di-query
  // SEBELUM truncate) tetap dipertahankan apa adanya -- truncate+insert di sini gak diubah,
  // cuma downloadImage()-nya yang dicabut biar step ini gak nungguin HTTP request per baris.
  public function getMasterImageCustomerDisplay(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest(
        $username,
        $password,
        $token,
        $this->endpoint . '/pos/sync/get_master_image_customer_display/' . $branch_id
      );

      if ($response->json('code') == 0) {
        $list = $response->json("data");

        $existingImages = MasterImageCustomerDisplayModel::whereIn('id', array_column($list, 'id'))
          ->pluck('banner_src', 'id');

        foreach ($list as &$item) {
          $item['banner_src'] = $existingImages[$item['id']] ?? null;
        }
        unset($item);

        MasterImageCustomerDisplayModel::truncate();
        $this->insertRows(MasterImageCustomerDisplayModel::class, $list);
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  // getMasterImageCustomerDisplayImages (2026-09-21): endpoint TERPISAH khusus download gambar
  // customer display banner -- sama pola getSubCategoryImages()/getMasterItemImages(), tapi
  // TANPA insert/truncate sama sekali (murni UPDATE banner_src per baris yang id-nya udah ada di
  // lokal hasil getMasterImageCustomerDisplay()). WAJIB dipanggil SETELAH getMasterImageCustomer
  // Display() di urutan sync (kalau dipanggil duluan/sendirian pas tabel masih kosong pasca
  // truncate, semua baris di-skip karena belum ada id yang cocok).
  public function getMasterImageCustomerDisplayImages(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest(
        $username,
        $password,
        $token,
        $this->endpoint . '/pos/sync/get_master_image_customer_display/' . $branch_id
      );

      if ($response->json('code') == 0) {
        $list = $response->json("data");

        $existingRows = MasterImageCustomerDisplayModel::whereIn('id', array_column($list, 'id'))
          ->get(['id', 'banner_src'])
          ->keyBy('id');

        foreach ($list as $item) {
          $existing = $existingRows->get($item['id']);
          if (!$existing) {
            continue;
          }

          MasterImageCustomerDisplayModel::where('id', $item['id'])->update([
            'banner_src' => $this->downloadImage($item['banner_src'] ?? null, 'master-image', $existing->banner_src),
          ]);
        }
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  // getMasterImageKiosk: sama alasan/pola kayak getMasterImageCustomerDisplay() di atas.
  public function getMasterImageKiosk(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest(
        $username,
        $password,
        $token,
        $this->endpoint . '/pos/sync/get_master_image_kiosk/' . $branch_id
      );

      if ($response->json('code') == 0) {
        $list = $response->json("data");

        $existingImages = MasterImageKioskModel::whereIn('id', array_column($list, 'id'))
          ->pluck('banner_src', 'id');

        foreach ($list as &$item) {
          $item['banner_src'] = $existingImages[$item['id']] ?? null;
        }
        unset($item);

        MasterImageKioskModel::truncate();
        $this->insertRows(MasterImageKioskModel::class, $list);
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }

  // getMasterImageKioskImages (2026-09-21): sama pola getMasterImageCustomerDisplayImages() di
  // atas, WAJIB dipanggil SETELAH getMasterImageKiosk() di urutan sync.
  public function getMasterImageKioskImages(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest(
        $username,
        $password,
        $token,
        $this->endpoint . '/pos/sync/get_master_image_kiosk/' . $branch_id
      );

      if ($response->json('code') == 0) {
        $list = $response->json("data");

        $existingRows = MasterImageKioskModel::whereIn('id', array_column($list, 'id'))
          ->get(['id', 'banner_src'])
          ->keyBy('id');

        foreach ($list as $item) {
          $existing = $existingRows->get($item['id']);
          if (!$existing) {
            continue;
          }

          MasterImageKioskModel::where('id', $item['id'])->update([
            'banner_src' => $this->downloadImage($item['banner_src'] ?? null, 'master-image', $existing->banner_src),
          ]);
        }
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
        $this->insertRows(MasterUserModel::class, $response->json('data'));
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
        $this->insertRows(MasterTableSectionPrintCategorySettingModel::class, $data);
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
        $this->upsertRows(MasterPromoModel::class, $response->json('data'));
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

  public function getPromoApplyTo(string $username, string $password, int $branch_id, ?string $token = null)
  {
    try {
      $response = $this->syncRequest(
        $username,
        $password,
        $token,
        $this->endpoint . '/pos/sync/get_promo_apply_to/' . $branch_id
      );

      if ($response->json('code') == 0) {
        $this->upsertRows(MasterPromoApplyToModel::class, $response->json('data'));
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
        $this->upsertRows(MasterMemberTypeModel::class, $response->json('data'));
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
        $this->upsertRows(MasterMemberModel::class, $response->json('data'));
      }

      return $response;
    } catch (\Throwable $e) {
      throw $e;
    }
  }
}
