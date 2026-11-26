# Menu List Per Visit Purpose

```
GET /api/master/menu-list/{visitPurposeId}
```

Versi **per-visit-purpose** dari [`MENU LIST.md`](./MENU%20LIST.md) (`GET /api/master/menu-list`) — balikin 1 baris visit purpose aja (bukan array semua visit purpose sekaligus), isinya sama persis (config pajak + pohon menu lengkap category → subcategory → item → package). Dibuat 2026-10-15 buat kasus FE yang cuma butuh 1 visit purpose spesifik (udah tau `visit_purpose_id`-nya dari Table Section yang dipilih kasir) tanpa perlu narik + filter manual dari array penuh di client.

`{visitPurposeId}` — `mr_branch_visit_purpose.id` / `nameVisitPurpose` id (sama `id` yang ada di tiap elemen array `data[]` endpoint `MENU LIST.md`).

**Reuse 100% logic yang sama** dengan `MENU LIST.md` — controller ini cuma manggil `MenuServices::GetMasterMenuList('pos')` (fungsi besar yang sama, channel yang sama), terus filter array hasilnya ke 1 baris yang `id`-nya cocok. **Tidak** ada reshape ke snake_case (beda dari versi Kiosk, `KIOSK BRANCH VISIT PURPOSE DETAIL.md`) — kontrak field-nya tetap camelCase, identik 1:1 dengan 1 elemen array `data[]` di `MENU LIST.md`. Konsekuensinya: field baru apa pun yang nanti ditambahkan ke `GetMasterMenuList()` (termasuk `sequence`, lihat di bawah) otomatis ikut muncul juga di sini tanpa perlu sentuh kode endpoint ini — satu sumber logic, dua pintu masuk.

## Response (struktur, bukan dump lengkap semua kolom)

```json
{
  "code": 0,
  "data": {
    "id": 7,
    "nameVisitPurpose": "Dine In",
    "serviceCharge": 9,
    "vat": 100,
    "pb1": 100,
    "orderFee": "0.00",
    "menuPriceListId": 12,
    "menuPriceList": [
      {
        "categoryId": 39,
        "categoryName": "MENU FOOD",
        "subCategoryData": [
          {
            "subCategoryId": 12,
            "SubCategoryName": "FOOD",
            "subCategoryIconSrc": null,
            "subCategoryBannerSrc": null,
            "sequence": 1,
            "menuList": [
              {
                "menuPricelistId": 93,
                "itemId": 120,
                "itemid_real": 118,
                "menuCode": "PM001",
                "menuName": "PAKET MERCON",
                "menuDescription": "Paket nasi + ayam + sambal mercon",
                "menuColor": "#FF5733",
                "bomId": 14,
                "categoryId": 39,
                "subCategoryId": 12,
                "menuPrice": "20000.00",
                "flagInclusiveTax": true,
                "stokQty": 0,
                "flagSoldOut": false,
                "imageSrc": "menu/paket-mercon.jpg",
                "iconSrc": null,
                "notesMenu": ["Extra Pedas", "Less Ice"],
                "packageid_real": 9,
                "separatePrintPackage": false,
                "taxType": "vat",
                "taxId": 100,
                "taxRate": "11.00",
                "serviceChargeId": 9,
                "serviceChargeRate": "5.00",
                "packageList": [
                  {
                    "packageId": 9,
                    "packageName": "PAKET 1",
                    "minQty": 1,
                    "maxQty": 1,
                    "menuPackageList": [
                      {
                        "menuPackageId": 210,
                        "itemId": 55,
                        "menuName": "NASI PUTIH",
                        "menuDescription": "Nasi putih porsi standar",
                        "menuPrice": "0.00",
                        "bomId": null,
                        "iconSrc": null,
                        "categoryId": 39,
                        "subCategoryId": 12,
                        "defaultItem": true,
                        "taxType": "vat",
                        "taxId": 100,
                        "taxRate": "11.00",
                        "serviceChargeId": 9,
                        "serviceChargeRate": "5.00",
                        "notesMenu": []
                      }
                    ]
                  }
                ]
              }
            ]
          }
        ]
      }
    ]
  }
}
```

**Urutan field `packageList` (dikoreksi 2026-11-18)** — di level item (`menuList[]`), `packageList` **dipindah** jadi PALING BAWAH object item (setelah `serviceChargeRate`) — sebelumnya nyangkut di tengah (langsung sesudah `iconSrc`), sekarang semua field "data" item ditaruh duluan, baru `packageList` (nested array sub-item) di paling akhir, biar lebih enak dibaca. Nilai **TIDAK berubah**, cuma urutan tampilnya di JSON (`unset()` + assign ulang di `MenuServices::GetMasterMenuList()`).

**Urutan field `taxType`/`taxId`/`taxRate` (dikoreksi 2026-11-18)** — di level item (`menuList[]`) dan sub-item package (`menuPackageList[]`), `taxType` **dipindah** jadi TEPAT SEBELUM `taxId`/`taxRate` (grup pajak berurutan: `..., taxType, taxId, taxRate, serviceChargeId, serviceChargeRate`) — sebelumnya `taxType` nyangkut di posisi awal object (asalnya dari SQL `SELECT`), sementara `taxId`/`taxRate` muncul jauh di posisi akhir (hasil assignment PHP belakangan), bikin 2 field yang berhubungan keliatan gak nyambung posisinya. Nilai field-nya **TIDAK berubah sama sekali**, cuma urutan tampilnya di JSON. Teknis: `MenuServices::GetMasterMenuList()` sekarang `unset($itemConvRow->taxType)` + assign ulang tepat sebelum resolusi `taxId`, murni geser posisi key di PHP object (stdClass mempertahankan urutan assignment, bukan urutan alfabet/kode fisik).

**`serviceChargeId`/`serviceChargeRate` (2026-11-18, BARU)** — `serviceCharge` (field lama di root, dipertahankan apa adanya) itu `tax_id` (FK ke `mr_tax`), BUKAN rate langsung — sama pola kayak `vat`/`pb1` yang di-resolve jadi `taxId`/`taxRate` per item. `serviceChargeId`/`serviceChargeRate` **CUMA ADA di level item (`menuList[]`) dan sub-item package (`packageList[].menuPackageList[]`)** — **TIDAK muncul di root objek visit purpose** (`serviceCharge` ID mentah tetap satu-satunya field di root, sengaja tidak ditambah `serviceChargeId`/`serviceChargeRate` duplikat di sana). `serviceChargeRate` di-resolve dari `mr_tax` (tabel yang sama, di-cache di memory sekali per request — `MasterTaxModel::get()->groupBy('id')`), lalu di-echo ke tiap baris item/sub-item. Beda dari `taxId`/`taxRate` (yang per-item, karena `taxType` tiap item bisa `vat` atau `pb1`), **service charge cuma 1 nilai per visit purpose** — nilainya **identik** di semua baris `menuList[]`/`menuPackageList[]` dalam 1 visit purpose yang sama. `null` kalau `serviceCharge` branch ini `0`/kosong (gak ada service charge), konsisten sama null-handling `taxId`/`taxRate`. **Tervalidasi live (2026-11-18)** via `php artisan tinker`: visit purpose id=1, `property_exists($vp, 'serviceChargeId')` → `false` (konfirmasi TIDAK ada di root), item `serviceChargeId=16`/`serviceChargeRate='0.00'`.

**Field level item (`menuList[]`)** — semua field di atas BENERAN ada di response (sebelumnya contoh di sini cuma dump sebagian, 7 dari 23 field), hasil cross-check langsung ke `MenuServices::GetMasterMenuList()`:
- `itemid_real` — `mr_item.id` sungguhan (beda dari `itemId` yang isinya `mr_item_conv.id`) — dipertahankan (tidak di-`unset()`) khusus supaya FE bisa match item ke target promo (`mr_promo_categories`/`sub_categories`/`items`).
- `categoryId`/`subCategoryId` — juga dipertahankan di level item (bukan cuma level `subCategoryData`) untuk alasan matching promo yang sama.
- `bomId` — `null` kalau `mr_item.bom_id = 0` (item tanpa BOM), diisi apa adanya kalau ada.
- `packageid_real`/`separatePrintPackage` — cuma ADA kalau item ini punya package (`mr_item_package` match `item_id`) — item tanpa package tidak punya 2 field ini sama sekali di objeknya (bukan `null`, benar-benar absen).
- `taxId` — ID pajak hasil resolve (`mr_branch_visit_purpose.vat`/`pb1` tergantung `taxType`), `null` kalau visit purpose ini `vat`/`pb1`-nya `0`/kosong.

**Field level sub-item package (`packageList[].menuPackageList[]`)** — sama persis, sebelumnya cuma 5 dari 13 field:
- `categoryId`/`subCategoryId` di sini **milik sub-item itu sendiri** (bisa beda dari item induk, karena 1 package bisa lintas kategori) — dipakai juga buat resolve `notesMenu` sub-item.
- `flagAllMenuTemplate` (field mentah query) **TIDAK muncul** di response — selalu di-`unset()` setelah dipakai internal buat override `menuPrice` per pricelist.

Kalau `{visitPurposeId}` gak ketemu (bukan visit purpose aktif branch ini, atau ID salah):

```json
{ "code": 100, "message": "visit purpose not found" }
```

- **Array terluar DIHAPUS** — beda dari `MENU LIST.md` yang array-nya 1 baris per visit purpose, di sini `data` langsung objek 1 visit purpose (sudah difilter server-side berdasar `{visitPurposeId}` di URL).
- Field-level, semuanya identik dengan `MENU LIST.md` — lihat bagian "Catatan" di situ buat penjelasan tiap field (`serviceCharge`/`vat`/`pb1` sebagai tax_id, `sequence`, `notesMenu`, `packageList[].menuPackageList[].defaultItem`, dkk). **Tidak diduplikasi di sini** supaya gak ada 2 sumber kebenaran yang bisa drift — kalau field baru ditambahkan di `GetMasterMenuList()`, cukup update catatan di `MENU LIST.md`.
- **Urutan `packageList[]` (2026-11-26)** — sama persis `MENU LIST.md`: diurutkan `sequence` ASC lalu `id` ASC (endpoint ini memfilter hasil `MenuServices::GetMasterMenuList('pos')`, jadi otomatis ikut).
- Nama key level objek tetap **`menuPriceList`** (bukan `categories`) dan **`subCategoryData`** (bukan `subcategories`) — sama persis penamaan asli di kode (`MenuServices::GetMasterMenuList()`), konsisten dengan `MENU LIST.md`.

## Alur Implementasi

1. `MasterController::GetMasterMenuListByVisitPurpose(Request $request, int $visitPurposeId)` — panggil `MenuServices::GetMasterMenuList('pos')` (channel POS, bukan Kiosk), dapat array SEMUA visit purpose, `foreach` cari yang `id`-nya cocok, balikin `404`-equivalent (`code: 100`) kalau gak ketemu.
2. **Belum dioptimasi** query-nya cuma buat 1 visit purpose — `GetMasterMenuList()` tetap hitung pohon menu + resolusi harga/pajak buat **semua** visit purpose branch ini, baru difilter ke satu di controller (sama persis trade-off yang sudah didokumentasikan di Kiosk, lihat "Catatan performa" di `KIOSK BRANCH VISIT PURPOSE DETAIL.md`). Aman untuk sekarang karena volume data per branch relatif kecil; kalau nanti jadi bottleneck, perlu ubah signature `GetMasterMenuList()` buat terima filter `visitPurposeId` dari awal (dampaknya ke 3 pemanggil: `MENU LIST.md` POS, `MENU LIST PER VISIT PURPOSE.md` ini, dan Kiosk).
3. Dirancang buat dipanggil **per kebutuhan** (misal pas kasir pindah ke Table Section dengan visit purpose beda, atau refresh 1 visit purpose spesifik) — beda dari `MENU LIST.md` yang dipanggil 1x di awal Dashboard load dan di-cache di state lokal FE. Belum ada integrasi FE (`orderPage.vue`) yang manggil endpoint ini per 2026-10-15 — murni backend endpoint baru, konsumsinya menyusul kalau dibutuhkan.

## Sumber

- `routes/api.php` — `Route::get('menu-list/{visitPurposeId}', [MasterController::class, 'GetMasterMenuListByVisitPurpose'])`.
- `app/Http/Controllers/MasterController.php` — `GetMasterMenuListByVisitPurpose()`.
- `app/Services/MenuServices.php` — `GetMasterMenuList()` (fungsi yang sama dipakai `MENU LIST.md` dan Kiosk, lihat dokumen itu buat detail lengkap).

## Status

Dibuat 2026-10-15. Backend (`routes/api.php`, `MasterController.php`) diverifikasi lewat `php -l` (lolos) dan `php artisan tinker` manual (panggil `MenuServices::GetMasterMenuList('pos')` langsung, konfirmasi struktur objek 1 visit purpose — `menuPriceList[].subCategoryData[].sequence` dkk — sesuai yang didokumentasikan di atas). **Belum** dites lewat HTTP request live (butuh server `php artisan serve` jalan + token Branch-Id valid), dan belum ada integrasi frontend yang memanggilnya.

**Dikoreksi & ditambahkan 2026-11-18**: contoh JSON dilengkapi (field level item 7→23, level sub-item package 5→13, hasil cross-check langsung ke `MenuServices::GetMasterMenuList()`) + field baru `serviceChargeId`/`serviceChargeRate` (cuma di level item & sub-item package, TIDAK di root, lihat catatan di atas). `php -l` lolos, tidak ada `unset()` yang menghapus field baru, `php artisan tinker` manual mengonfirmasi `property_exists` `false` di root dan nilai ter-isi di item/sub-item. Sumber satu-satunya buat perubahan ini sama dengan [`MENU LIST.md`](./MENU%20LIST.md) — perubahan di `MenuServices::GetMasterMenuList()` otomatis ikut muncul di sini juga (reuse 100%).
