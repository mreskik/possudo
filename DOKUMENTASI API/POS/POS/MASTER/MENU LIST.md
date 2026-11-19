# Menu List

```
GET /api/master/menu-list
```

Pohon menu **lengkap SEMUA visit purpose sekaligus** (bukan per-visit-purpose seperti Kiosk) — category → subcategory → item → package, plus resolusi harga/pajak per visit purpose. Endpoint paling berat/kompleks di modul MASTER, dipanggil sekali saat Dashboard dimuat, hasilnya di-cache di memory (bukan re-fetch tiap buka halaman Order).

**Kontrak field (camelCase)** — beda konvensi dari kebanyakan endpoint lain di modul ini (yang snake_case) — historis dari desain awal, bukan sengaja beda.

Ada juga versi per-visit-purpose dari endpoint ini — lihat [`MENU LIST PER VISIT PURPOSE.md`](./MENU%20LIST%20PER%20VISIT%20PURPOSE.md) (`GET /api/master/menu-list/{visitPurposeId}`), reuse logic yang sama, cuma balikin 1 baris visit purpose aja (bukan array semua).

## Response (struktur, bukan dump lengkap semua kolom)

```json
{
  "code": 0,
  "data": [
    {
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
  ]
}
```

- **Urutan field `packageList` (dikoreksi 2026-11-18)** — di level item (`menuList[]`), `packageList` **dipindah** jadi PALING BAWAH object item (setelah `serviceChargeRate`) — sebelumnya nyangkut di tengah (langsung sesudah `iconSrc`). Nilai **TIDAK berubah**, cuma urutan tampilnya di JSON.
- **Urutan field `taxType`/`taxId`/`taxRate` (dikoreksi 2026-11-18)** — di level item (`menuList[]`) dan sub-item package (`menuPackageList[]`), `taxType` **dipindah** jadi TEPAT SEBELUM `taxId`/`taxRate` (grup pajak berurutan: `..., taxType, taxId, taxRate, serviceChargeId, serviceChargeRate`) — sebelumnya `taxType` nyangkut di posisi awal object (asalnya dari SQL `SELECT`), sementara `taxId`/`taxRate` muncul jauh di posisi akhir (hasil assignment PHP belakangan). Nilai field **TIDAK berubah**, cuma urutan tampilnya di JSON (`unset()` + assign ulang di `MenuServices::GetMasterMenuList()`, murni geser posisi key PHP object).
- **⚠️ Key-naming (dikoreksi 2026-11-18)** — object key level category/subcategory/item **BUKAN** `categories`/`subcategories`/`items` (salah sebut di versi dokumen sebelumnya) — nama key aslinya di kode (`MenuServices::GetMasterMenuList()`, tidak ada reshape apa pun di controller) adalah **`menuPriceList`** (bukan `categories`), **`subCategoryData`** (bukan `subcategories`, dan nama sub-key-nya `SubCategoryName` huruf besar di depan, bukan `subCategoryName`), **`menuList`** (bukan `items`) — konsisten dengan [`MENU LIST PER VISIT PURPOSE.md`](./MENU%20LIST%20PER%20VISIT%20PURPOSE.md) yang sejak awal sudah benar.
- **Array terluar** — 1 baris per `visit_purpose` yang aktif (`mr_branch_visit_purpose` JOIN `mr_visit_purpose`) — bukan 1 visit purpose, SEMUA sekaligus. Frontend filter sendiri sesuai visit purpose yang dipilih kasir saat itu.
- `serviceCharge`/`vat`/`pb1` — ID pajak (`mr_tax.id`), bukan rate-nya langsung — rate-nya di-resolve terpisah dari tabel `mr_tax` (di-cache di memory saat fungsi ini jalan, `MasterTaxModel::get()->groupBy('id')`).
- **`serviceChargeId`/`serviceChargeRate` (2026-11-18, BARU)** — **CUMA ADA di level item (`menuList[]`) dan sub-item package (`packageList[].menuPackageList[]`)** — **TIDAK muncul di root objek visit purpose** (`serviceCharge` ID mentah tetap satu-satunya field service charge di root). `serviceChargeId` alias dari `serviceCharge`, `serviceChargeRate` rate hasil resolve dari `mr_tax` (sumber sama dengan `taxRate`), lalu di-echo ke tiap baris item/sub-item. Beda dari `taxId`/`taxRate` yang per-item (karena `taxType` tiap item bisa `vat` atau `pb1`), **service charge cuma 1 nilai per visit purpose** — nilainya **identik** di semua baris `menuList[]`/`menuPackageList[]` dalam 1 visit purpose. `null` kalau `serviceCharge` branch ini `0`/kosong. **Tervalidasi live** via `php artisan tinker`: visit purpose id=1, `property_exists($vp, 'serviceChargeId')` → `false` (konfirmasi TIDAK ada di root), item `serviceChargeId=16`/`serviceChargeRate='0.00'`.
- `menuPriceList[].subCategoryData[].menuList[]` — harga (`menuPrice`) dan pajak SUDAH di-resolve per visit purpose di titik ini (bisa beda nilai untuk visit purpose yang beda, kalau ada pricelist override).
- **Field level item (`menuList[]`) yang sebelumnya belum tercantum** (dikoreksi 2026-11-18, cross-check langsung ke query) — `itemid_real` (`mr_item.id` sungguhan, beda dari `itemId` yang isinya `mr_item_conv.id`, dipertahankan khusus buat FE match target promo), `menuCode`, `menuDescription`, `menuColor`, `bomId` (`null` kalau item tanpa BOM), `categoryId`/`subCategoryId` (dipertahankan juga di level item buat matching promo, bukan cuma level subcategory), `stokQty`, `flagSoldOut`, `imageSrc`, `iconSrc`, `taxId` (ID pajak hasil resolve, `null` kalau `vat`/`pb1` visit purpose ini `0`/kosong), `serviceChargeId`/`serviceChargeRate` (echo dari root, lihat catatan di atas), `packageid_real`/`separatePrintPackage` (cuma ADA kalau item ini punya package — absen total, bukan `null`, kalau item tanpa package).
- **Field level sub-item package (`packageList[].menuPackageList[]`) yang sebelumnya belum tercantum** — `menuDescription`, `taxType`, `bomId`, `iconSrc`, `categoryId`/`subCategoryId` (milik sub-item itu sendiri, bisa beda dari item induk karena 1 package bisa lintas kategori), `taxId`, `taxRate`, `serviceChargeId`/`serviceChargeRate` (echo dari root, sama seperti level item). `flagAllMenuTemplate` (field mentah query) **tidak muncul** di response — selalu di-`unset()` setelah dipakai internal buat override `menuPrice` per pricelist.
- `sequence` (BARU 2026-10-15) — urutan tampil subcategory, dari `mr_pricelist_sub_category_sequence` (ditarik sync pull dari `master_pricelist_sub_category_sequence`, sudocore2 — lihat `MASTER MENU TEMPLATE.md` sudocore2 dan [`SYNC PULL.md`](../../SYNC/SYNC%20PULL.md) POS). **`null`** kalau subcategory itu belum pernah diatur urutannya (admin belum pernah pakai fitur `sub_category_sequence` buat menu template ini) — bukan error. Query backend sudah `ORDER BY` berdasarkan field ini (`null` ditaruh paling belakang, tie-break nama ASC), dan frontend (`orderPage.vue`, fungsi `sortedSubCategories()`) ikut sort ulang eksplisit sebelum render grid pilih subcategory — tidak cuma mengandalkan urutan dari backend diam-diam.
- `notesMenu` — chip quick-pick notes (`short_notes`), embed langsung di sini (bukan endpoint terpisah) — array kosong `[]` kalau tidak ada yang match, bukan `null`. Field yang sama juga ada di tiap `menuPackageList[]` (sub-item package, matching berdasar kategori sub-item itu sendiri, bukan ikut kategori item induk).
- `packageList[].menuPackageList[].defaultItem` — `true` kalau sub-item ini pre-selected secara default saat item ditambahkan ke cart.

## Alur Implementasi

1. Dipanggil sekali (`getMasterMenuListAndPrice()`, `MasterServices.ts`) saat Dashboard load awal — bukan tiap kali halaman Order dibuka.
2. Data disimpan di state lokal (bukan di `globalstore.js` seperti `branch`/`dayshift`), difilter manual di `orderPage.vue` sesuai `visit_purpose_id` yang sedang aktif (dari Table Section yang dipilih kasir).
3. Saat item ditambahkan ke cart, komponen cart menyimpan **salinan** nilai `menuPricelistId`/harga/pajak saat itu — perubahan menu di ERP setelah fetch awal **tidak otomatis ter-refresh** di cart yang sudah terbuka, perlu refresh/reload halaman.
4. `packageList` dipakai render dialog "Add Package" — `menuPackageList[].defaultItem` nentuin sub-item mana yang otomatis tercentang saat dialog dibuka pertama kali.
5. (BARU 2026-10-15) Grid pilih subcategory di `orderPage.vue` render lewat fungsi `sortedSubCategories(subCategoryData)` (bukan langsung `v-for` ke array asli) — sort berdasar `sequence` ASC, `null` ditaruh paling belakang, tie-break nama ASC. Fungsi ini jaga-jaga (defense-in-depth) di atas `ORDER BY` yang udah ada di backend — FE gak diam-diam gantung ke urutan backend doang.

**Catatan buat migrasi FE baru**: fungsi backend-nya (`MenuServices::GetMasterMenuList('pos')`) **di-share** dengan Kiosk (`GetMasterMenuList('kiosk')`) — logic query & resolusi harga/pajak SAMA PERSIS, cuma beda nama field sebagian (POS camelCase, Kiosk ada campuran snake_case) dan field `notesMenu` isinya beda (`short_notes` vs `full_notes`). Kalau butuh referensi struktur yang sudah terdokumentasi lebih lengkap per-kolom, lihat `DOKUMENTASI API/KIOSK/KIOSK BRANCH VISIT PURPOSE DETAIL.md` (field-nya beda penamaan, tapi relasi data yang sama).

## Sumber

- `app/Http/Controllers/MasterController.php` — `GetMasterMenuList()`.
- `app/Services/MenuServices.php` — `GetMasterMenuList()` (fungsi besar, shared dengan Kiosk), `GetAllNotesMenuForMatching()`, `ResolveNotesMenu()`.
- Frontend: `src/services/MasterServices.ts` (`getMasterMenuListAndPrice`), `src/modules/dashboard/order/orderPage.vue`.

## Status

Didokumentasikan 2026-10-12, berdasar pembacaan kode langsung (belum ada verifikasi ulang lewat HTTP request live di sesi ini).

**Dikoreksi 2026-11-18**: contoh JSON sebelumnya pakai key salah (`categories`/`subcategories`/`items`) dan cuma dump sebagian field (7 dari 23 field level item, 5 dari 13 field level sub-item package) — dikoreksi penuh lewat cross-check langsung ke `MenuServices::GetMasterMenuList()` (query SQL + proses `unset()`/resolusi tax per baris). Key asli yang benar: `menuPriceList`/`subCategoryData`/`menuList` (controller tidak melakukan reshape apa pun, lihat komentar `GetMasterMenuListByVisitPurpose()` di `MasterController.php`). Masih belum ada verifikasi ulang lewat HTTP request live — koreksi ini dari pembacaan kode, bukan hit endpoint beneran.

**Diupdate 2026-10-15**: ditambahkan field `sequence` di blok subcategory (`MenuServices::GetMasterMenuList()`, query `$subcategory` — `LEFT JOIN mr_pricelist_sub_category_sequence` + `ORDER BY (sequence IS NULL) ASC, sequence ASC, name ASC`) dan fungsi `sortedSubCategories()` di frontend (`orderPage.vue`). Diverifikasi: `php -l` lolos, query ditest manual lewat `php artisan tinker` (6 baris unik tanpa duplikasi, `sequence` semuanya `null` karena data sync belum pernah dijalankan betulan), dicek tidak ada `unset()` yang menghapus field `sequence` di proses penggabungan subcategory→category, dan `npm run build` frontend sukses tanpa error. Field ini SENGAJA tidak dipisah per channel — ikut muncul di response Kiosk juga karena `GetMasterMenuList()` shared.

**Ditambahkan 2026-11-18**: field `serviceChargeId`/`serviceChargeRate` di `MenuServices::GetMasterMenuList()` — diresolve sekali per visit purpose ke 2 variabel LOKAL (`$serviceChargeId`/`$serviceChargeRate`, pola sama dengan `taxId`/`taxRate`, pakai tabel `$tax` yang sama), lalu **di-echo** ke tiap baris `menuList[]` (item) dan `packageList[].menuPackageList[]` (sub-item package) — **SENGAJA TIDAK** di-set ke `$visitPurposeRow` (root objek visit purpose), biar `serviceCharge` ID mentah tetap satu-satunya field service charge di level root, tidak ada duplikat `serviceChargeId`/`serviceChargeRate` di situ. Diverifikasi: `php -l` lolos, dicek tidak ada `unset()` yang menghapus field baru ini, `php artisan tinker` manual (`property_exists($vp, 'serviceChargeId')` → `false` di root, item `serviceChargeId=16`/`serviceChargeRate='0.00'`). Field ini SENGAJA tidak dipisah per channel — ikut muncul di response Kiosk juga karena `GetMasterMenuList()` shared (Kiosk sendiri sudah punya `service_charge_rate` versi root-only sejak lama di `KioskController::GetBranchVisitPurposeDetail()`, independen dari field baru ini).
