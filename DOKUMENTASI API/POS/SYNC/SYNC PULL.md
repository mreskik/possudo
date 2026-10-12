# Sync Pull

Base path: `/api/sync_pull`

39 endpoint — **background, tanpa UI langsung**. Masing-masing narik 1 jenis master data dari ERP (sudocore2, lewat `SERVER_ENDPOINT` di `.env`) dan menyimpannya ke tabel lokal POS. Dipanggil berkali-kali sepanjang umur install (bukan cuma sekali waktu Setup) — lewat 2 jalur: tombol **Sync** manual di `SettingPage.vue` (dikelompokkan per kategori, lihat [`SYNC GROUP.md`](SYNC%20GROUP.md)) dan proses awal `SetupPage.vue` (lihat [`../SETUP/SETUP WIZARD.md`](../SETUP/SETUP%20WIZARD.md)).

## Pola seragam (semua 39 endpoint identik strukturnya)

```
GET /api/sync_pull/get_xxx
```

- **Tanpa parameter** — `branch_id` tidak dikirim dari client. `SyncController::currentBranch()` ambil langsung dari `mr_branch` lokal (cuma pernah 1 baris) dan `token`-nya dipakai buat autentikasi request ke ERP (`Http::withToken($token)`).
- **Response seragam**: `{ "code": 0, "message": "..." }` — **TIDAK membalikkan data sync-nya ke client**, cuma status sukses/gagal. Data hasil sync langsung tersimpan di DB lokal, dibaca belakangan lewat endpoint `POS/MASTER/*` yang terpisah (mis. `GET /master/station-list` buat baca station yang baru di-sync).
- Belum pernah install (`mr_branch` kosong) → semua endpoint ini balikin `{ "code": 100, "message": "branch has not been selected/saved, please complete setup first" }`.
- Request ke ERP pakai `connectTimeout(5)` + `timeout(15)` — gagal cepat kalau internet mati, gak nge-hang lama.

## Daftar lengkap — endpoint, tabel lokal, strategi simpan

| Endpoint | Model/Tabel lokal | Strategi |
|---|---|---|
| `get_data_branch` | `BranchModel` (`mr_branch`) + `MasterBranchSettingModel` | Truncate+create (1 baris) |
| `get_station_list` | `StationModel` | Upsert by `id` |
| `get_category_list` | `CategoryModel` | Upsert by `id` |
| `get_subcategory_list` | `SubCategoryModel` | Upsert by `id` |
| `get_subcategory_images` | (gambar subkategori, download file) | &mdash; |
| `get_tablesection_list` | `TableSectionModel` | Upsert by `id` |
| `get_table` | `TableModel` | Upsert by `id` |
| `get_tax` | `MasterTaxModel` | Upsert by `id` |
| `get_terminal` | `TerminalModel` | Upsert by `id` |
| `get_item` | `MasterItemModel` | Upsert by `id` |
| `get_item_images` | (gambar item, download file) | &mdash; |
| `get_item_conv` | `MasterItemConvModel` | Upsert by `id` |
| `get_item_package` | `MasterItemPackageModel` | Upsert by `id` |
| `get_item_package_group` | `MasterItemPackageGroupModel` | **Truncate+insert** (ERP replace-all) |
| `get_item_package_detail` | `MasterItemPackageDetailModel` | **Truncate+insert** |
| `get_item_package_detail_pricelist` | `MasterItemPackageDetailPricelistModel` | **Truncate+insert** |
| `get_pricelist` | `MasterPricelistModel` | **Truncate+insert** (disepakati, bukan upsert) |
| `get_pricelist_detail` | `MasterPricelistDetailModel` | **Truncate+insert** |
| `get_payment_method` | `MasterPaymentMethodModel` | Upsert by `id` |
| `get_payment_method_group` | `MasterPaymentMethodGroupModel` | **Truncate+insert** (2026-09-21, disepakati) |
| `get_payment_method_type` | `MasterPaymentMethodTypeModel` | Upsert by `id` |
| `get_payment_method_visit_purpose` | `MasterPaymentMethodVisitPurposeModel` | **Truncate+insert** (baris yang dihapus di ERP harus ikut hilang lokal) |
| `get_branch_visit_purpose` | `MasterBranchVisitPurposeModel` | **Truncate+insert** (2026-08-26, sama alasan) |
| `get_branch_ops_setting` | `MasterBranchOpsSettingModel` | **Truncate+insert** (2026-09-21) |
| `get_master_image_customer_display` | `MasterImageCustomerDisplayModel` | **Truncate+insert** (2026-09-04) |
| `get_master_image_customer_display_images` | (gambar banner, download file) | &mdash; |
| `get_master_image_kiosk` | `MasterImageKioskModel` | **Truncate+insert** |
| `get_master_image_kiosk_images` | (gambar banner kiosk, download file) | &mdash; |
| `get_visit_purpose` | `MasterVisitPurposeModel` | Upsert by `id` |
| `get_master_user` | `MasterUserModel` | **Truncate+insert** (data akses login, HARUS selalu cerminan pasti ERP) |
| `get_master_role_access` | `RoleAccessModel` | Upsert by `id` |
| `get_menu_app` | `MasterMenuAppModel` | Upsert by `id` |
| `get_promo_list` | `MasterPromoModel` | Upsert by `id` |
| `get_promo_branch` | `MasterPromoBranchesModel` | Upsert by `id` |
| `get_promo_visit_purpose` | `MasterPromoVisitPurposesModel` | Upsert by `id` |
| `get_promo_type_member` | `MasterPromoTypeMembersModel` | Upsert by `id` |
| `get_promo_tier` | `MasterPromoTierModel` | Upsert by `id` |
| `get_promo_category` | `MasterPromoCategoriesModel` | Upsert by `id` |
| `get_promo_sub_category` | `MasterPromoSubCategoriesModel` | Upsert by `id` |
| `get_promo_item` | `MasterPromoItemsModel` | Upsert by `id` |
| `get_promo_day` | `MasterPromoDaysModel` | Upsert by `id` |
| `get_promo_time` | `MasterPromoTimesModel` | Upsert by `id` |
| `get_promo_apply_to` | `MasterPromoApplyToModel` | Upsert by `id` |
| `get_member_type_list` | `MasterMemberTypeModel` | Upsert by `id` |
| `get_member_list` | `MasterMemberModel` | Upsert by `id` |
| `get_notes_menu_list` | `MasterNotesMenuModel` | **Truncate+insert** |
| `get_notes_menu_category` | `MasterNotesMenuCategoriesModel` | **Truncate+insert** |
| `get_notes_menu_sub_category` | `MasterNotesMenuSubCategoriesModel` | **Truncate+insert** |
| `get_notes_menu_detail` | `MasterNotesMenuDetailModel` | **Truncate+insert** |

**Kenapa ada 2 strategi (upsert vs truncate+insert)** — `upsertRows()` dipakai default: baris lokal yang `id`-nya sudah ada di-update, yang baru di-insert, **baris lokal yang sudah tidak ada di response TIDAK ikut dihapus** (data yang dihapus/dinonaktifkan di ERP tetap nyangkut lokal sampai ditangani terpisah). Tabel yang **wajib selalu jadi cerminan pasti ERP** (data akses login, konfigurasi link/relasi yang kalau dihapus di ERP harus ikut hilang lokal) pakai `truncate()+insertRows()` — replace total tiap sync, keputusan eksplisit per tabel (dicatat di komentar kode masing-masing fungsi).

## Gambar — endpoint terpisah dari data teks

4 endpoint di atas (`get_subcategory_images`, `get_item_images`, `get_master_image_customer_display_images`, `get_master_image_kiosk_images`) **cuma download file gambar**, dipisah dari endpoint data teks pasangannya (2026-09-21, `SetupServices::downloadImage()` dicabut dari proses sync data teks) — biar sync data (cepat, sering dipanggil) gak keikut lambat oleh proses download gambar (lambat, jaringan-dependent). Gambar disimpan lokal di `public/img/{subdir}/` — subdir beda per jenis (`branch`, `item`, dst).

## Sumber

- `app/Http/Controllers/SyncController.php` — 39 method, 1:1 sama urutan tabel di atas.
- `app/Services/SetupServices.php` — logic query ke ERP + simpan lokal (di-SHARE dengan `SetupController::getDataBranch()`, lihat [`../SETUP/SETUP WIZARD.md`](../SETUP/SETUP%20WIZARD.md)).
- `routes/api.php` — group `Route::prefix('sync_pull')`.
- Frontend: `src/services/sync/syncServices.ts` (1 fungsi per endpoint, dipanggil dari `SetupPage.vue` dan [`SYNC GROUP.md`](SYNC%20GROUP.md)).

## Status

Didokumentasikan 2026-10-12, berdasar pembacaan kode langsung (belum ada verifikasi ulang lewat HTTP request live di sesi ini).
