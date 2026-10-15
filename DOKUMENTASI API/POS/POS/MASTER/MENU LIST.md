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
      "categories": [
        {
          "categoryId": 39,
          "categoryName": "MENU FOOD",
          "subcategories": [
            {
              "subCategoryId": 12,
              "subCategoryName": "FOOD",
              "sequence": 1,
              "items": [
                {
                  "menuPricelistId": 93,
                  "itemId": 120,
                  "menuName": "PAKET MERCON",
                  "menuPrice": "20000.00",
                  "flagInclusiveTax": true,
                  "taxType": "vat",
                  "taxRate": "11.00",
                  "notesMenu": ["Extra Pedas", "Less Ice"],
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
                          "menuPrice": "0.00",
                          "defaultItem": true,
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

- **Array terluar** — 1 baris per `visit_purpose` yang aktif (`mr_branch_visit_purpose` JOIN `mr_visit_purpose`) — bukan 1 visit purpose, SEMUA sekaligus. Frontend filter sendiri sesuai visit purpose yang dipilih kasir saat itu.
- `serviceCharge`/`vat`/`pb1` — ID pajak (`mr_tax.id`), bukan rate-nya langsung — rate-nya di-resolve terpisah dari tabel `mr_tax` (di-cache di memory saat fungsi ini jalan, `MasterTaxModel::get()->groupBy('id')`).
- `categories[].subcategories[].items[]` — harga (`menuPrice`) dan pajak SUDAH di-resolve per visit purpose di titik ini (bisa beda nilai untuk visit purpose yang beda, kalau ada pricelist override).
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

Didokumentasikan 2026-10-12, berdasar pembacaan kode langsung (belum ada verifikasi ulang lewat HTTP request live di sesi ini). Response contoh di atas disusun dari struktur query, bukan hasil hit endpoint beneran — field tambahan mungkin ada yang belum tercakup (bom_id, icon_src, image_src, stok_qty, flag_sold_out, dkk, mengikuti pola yang sama seperti versi Kiosk).

**Diupdate 2026-10-15**: ditambahkan field `sequence` di blok subcategory (`MenuServices::GetMasterMenuList()`, query `$subcategory` — `LEFT JOIN mr_pricelist_sub_category_sequence` + `ORDER BY (sequence IS NULL) ASC, sequence ASC, name ASC`) dan fungsi `sortedSubCategories()` di frontend (`orderPage.vue`). Diverifikasi: `php -l` lolos, query ditest manual lewat `php artisan tinker` (6 baris unik tanpa duplikasi, `sequence` semuanya `null` karena data sync belum pernah dijalankan betulan), dicek tidak ada `unset()` yang menghapus field `sequence` di proses penggabungan subcategory→category, dan `npm run build` frontend sukses tanpa error. Field ini SENGAJA tidak dipisah per channel — ikut muncul di response Kiosk juga karena `GetMasterMenuList()` shared.
