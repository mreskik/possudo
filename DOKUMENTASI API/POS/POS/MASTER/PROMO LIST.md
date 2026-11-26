# Promo List

```
GET /api/master/promo-list/{visit_purpose_id}
```

Promo yang **eligible** buat 1 visit purpose saat ini (barrier struktural: aktif, periode, visit purpose, member type, tier, hari, jam, channel `pos`) — **bukan endpoint apply promo ke order**, itu bagian terpisah di alur Save Order (lihat [`../ORDER/SAVE ORDER.md`](../ORDER/SAVE%20ORDER.md)).

## Request

`{visit_purpose_id}` dari path. Query opsional:
- `member_type_id` — **nullable**, dikirim `null`/tidak dikirim dari halaman Order (member belum diketahui — baru dipilih saat pembayaran). Passing `null` **disengaja**, bukan bug: `type_member_id = NULL` tidak pernah match di SQL, jadi otomatis fallback ke promo yang `flag_all_type_members = true` saja. Halaman Payment (kalau sudah di-wire) bisa panggil ulang dengan `member_type_id` asli untuk memunculkan promo yang dibatasi tipe member tertentu.
- `tier_level` — sama semantiknya seperti `member_type_id` di atas (nullable, fallback ke `flag_all_tiers = true`).

## Response

```json
{
  "code": 0,
  "data": [
    {
      "id": 23,
      "name": "Diskon 20%",
      "code": "DISC20",
      "type": "percent",
      "type_rupiah_amount": "0.00",
      "type_bill_amount": "0.00",
      "type_percent_rate": "20.00",
      "type_percent_limit_amount": "50000.00",
      "type_freeitem_item_id": null,
      "type_freeitem_item_detail": null,
      "promo_for": "category",
      "min_buy_amount": "0.00",
      "flag_include_package": true,
      "apply_limit_per_day": 10,
      "apply_limit_per_item": null,
      "period_start": null,
      "period_end": null,
      "targetIds": [39, 40],
      "used_today": 2
    },
    {
      "id": 24,
      "name": "Beli 2 Gratis 1",
      "code": "FREEITEM1",
      "type": "freeitem",
      "type_rupiah_amount": "0.00",
      "type_bill_amount": "0.00",
      "type_percent_rate": "0.00",
      "type_percent_limit_amount": "0.00",
      "type_freeitem_item_id": 120,
      "type_freeitem_item_detail": {
        "itemId": 129,
        "itemid_real": 120,
        "menuCode": "111",
        "menuName": "AMERICANO / ON THE ROCK",
        "menuDescription": "A rich and concentrated shot of premium espresso...",
        "menuColor": "#ffffff",
        "menuPrice": "0.00",
        "flagInclusiveTax": true,
        "bomId": null,
        "categoryId": 39,
        "subCategoryId": 19,
        "taxType": "pb1",
        "stokQty": 0,
        "flagSoldOut": 0,
        "imageSrc": null,
        "iconSrc": null,
        "notesMenu": ["Extra Pedas", "Less Pedas"],
        "packageList": [
          {
            "packageId": 59,
            "packageName": "VARIAN",
            "minQty": 1,
            "maxQty": 1,
            "menuPackageList": [
              {
                "menuPackageId": 98,
                "itemId": 121,
                "menuName": "HOT AMERICANO / ON THE ROCK",
                "menuDescription": "",
                "menuPrice": "5000.00",
                "taxType": "pb1",
                "bomId": null,
                "iconSrc": null,
                "categoryId": 39,
                "subCategoryId": 19,
                "defaultItem": 0,
                "taxId": 10,
                "taxRate": "10.00",
                "notesMenu": ["Extra Pedas", "Less Pedas"]
              }
            ]
          }
        ]
      },
      "promo_for": "item",
      "min_buy_amount": "0.00",
      "flag_include_package": false,
      "apply_limit_per_day": null,
      "apply_limit_per_item": null,
      "period_start": null,
      "period_end": null,
      "targetIds": [55],
      "used_today": 0
    },
    {
      "id": 25,
      "name": "Beli 2 Gratis 1 (+Package)",
      "code": "FREEITEM2",
      "type": "freeitem",
      "type_rupiah_amount": "0.00",
      "type_bill_amount": "0.00",
      "type_percent_rate": "0.00",
      "type_percent_limit_amount": "0.00",
      "type_freeitem_item_id": 120,
      "type_freeitem_item_detail": {
        "itemId": 129,
        "itemid_real": 120,
        "menuCode": "111",
        "menuName": "AMERICANO / ON THE ROCK",
        "menuDescription": "A rich and concentrated shot of premium espresso...",
        "menuColor": "#ffffff",
        "menuPrice": "0.00",
        "flagInclusiveTax": true,
        "bomId": null,
        "categoryId": 39,
        "subCategoryId": 19,
        "taxType": "pb1",
        "stokQty": 0,
        "flagSoldOut": 0,
        "imageSrc": null,
        "iconSrc": null,
        "notesMenu": ["Extra Pedas", "Less Pedas"],
        "packageList": [
          {
            "packageId": 59,
            "packageName": "VARIAN",
            "minQty": 1,
            "maxQty": 1,
            "menuPackageList": [
              {
                "menuPackageId": 98,
                "itemId": 121,
                "menuName": "HOT AMERICANO / ON THE ROCK",
                "menuDescription": "",
                "menuPrice": "0.00",
                "taxType": "pb1",
                "bomId": null,
                "iconSrc": null,
                "categoryId": 39,
                "subCategoryId": 19,
                "defaultItem": 0,
                "taxId": 10,
                "taxRate": "10.00",
                "notesMenu": ["Extra Pedas", "Less Pedas"]
              }
            ]
          }
        ]
      },
      "promo_for": "item",
      "min_buy_amount": "0.00",
      "flag_include_package": true,
      "apply_limit_per_day": null,
      "apply_limit_per_item": null,
      "period_start": null,
      "period_end": null,
      "targetIds": [55],
      "used_today": 0
    }
  ]
}
```

Perhatikan **2 promo terakhir di atas pakai `type_freeitem_item_id` yang SAMA (`120`)** tapi `flag_include_package` berbeda (`false` vs `true`) — hasilnya `menuPackageList[0].menuPrice` beda (`"5000.00"` harga asli vs `"0.00"` di-hardcode), membuktikan resolve-nya per PROMO, bukan di-cache dan ketimpa antar promo yang share item sama.

- `targetIds` — isinya `category_id`/`sub_category_id`/`item_id` tergantung `promo_for` (resolve dari `mr_promo_categories`/`mr_promo_sub_categories`/`mr_promo_items`) — menentukan item/kategori mana di cart yang eligible untuk promo ini.
- `used_today` — jumlah order **distinct** hari ini yang sudah pakai promo ini (status `pending`/`hold`/`paid`, item belum dibatalkan) — dipakai FE untuk enforce `apply_limit_per_day` di sisi tampilan (bukan validasi final — validasi final tetap di server saat Save Order).
- **`type_freeitem_item_detail` (baru, 2026-11-17)** — detail lengkap item free milik promo type `freeitem`, resolve dari `type_freeitem_item_id` (yang isinya `mr_item.id`, **BUKAN** `mr_item_conv.id` — lihat komentar migration `type_freeitem_item_id, // ITEM ID, bukan CONV ID`). `null` kalau `type_freeitem_item_id` kosong (promo bukan type `freeitem`, atau `freeitem` tapi item-nya belum diisi admin). Struktur serupa 1 baris item dari [Menu List](MENU%20LIST.md) — field-nya `itemId`/`itemid_real`/`menuCode`/`menuName`/`menuDescription`/`menuColor`/`menuPrice`/`flagInclusiveTax`/`bomId`/`categoryId`/`subCategoryId`/`taxType`/`stokQty`/`flagSoldOut`/`imageSrc`/`iconSrc`/`notesMenu`/`packageList`. **1 field Menu List TIDAK ADA** di sini: `menuPricelistId` — `type_freeitem_item_id` di `mr_promo` cuma nyimpen `item_id` polos, gak ada hubungan langsung ke 1 baris `mr_pricelist_detail` spesifik.

`menuPrice` di level header **hardcode `"0.00"`** (BUKAN diambil dari pricelist manapun) — item free gak punya harga sendiri di luar konteks pricelist, dan nilainya memang sesuai kenyataan bisnis (harga jadi 0 saat diterapkan sebagai free item), field-nya tetap disertakan demi konsistensi format dengan response Menu List.

`flagInclusiveTax` (2026-11-17, revisi) **DIAMBIL dari pricelist milik `visit_purpose_id`** (path param endpoint ini sendiri) — resolve lewat `mr_branch_visit_purpose.pricelist_id` (filter `visit_purpose_id`) → `mr_pricelist.is_inclusive`, pola sama `MenuServices::GetMasterMenuList()`. Beda dari `menuPrice`/`menuPricelistId` (yang levelnya per-ITEM di 1 baris `mr_pricelist_detail` spesifik, gak bisa diresolve tanpa tau item mana yang mau dicocokkan ke pricelist), `is_inclusive` levelnya per-PRICELIST (1 pricelist cuma 1 nilai `is_inclusive`, berlaku buat semua item di dalamnya) — jadi bisa langsung diresolve dari `visit_purpose_id` tanpa perlu join ke item sama sekali. `null` kalau `visit_purpose_id` gak match baris `mr_branch_visit_purpose` manapun (seharusnya gak pernah terjadi — `visit_purpose_id` yang sama juga dipakai buat filter barrier struktural promo di query utama).

**Urutan `packageList[]` di `type_freeitem_item_detail` (2026-11-26)** — group diurutkan `sequence` ASC lalu `id` ASC (`ORDER BY sequence ASC, id ASC` di `MasterController::AttachPromoFreeItemDetail()`), sama dengan `MENU LIST.md`. Field `sequence` tidak dikirim, hanya urutan array-nya.

`packageList[].menuPackageList[].menuPrice` **tergantung `flag_include_package` milik promo itu sendiri** (bukan item):
- `flag_include_package = false` (default) — `menuPrice` varian pakai `mr_item_package_detail.price` **apa adanya** (harga asli), karena package TIDAK termasuk dalam promo free item ini, cuma item utamanya yang gratis.
- `flag_include_package = true` — `menuPrice` **SEMUA** varian di `packageList` ikut di-hardcode `"0.00"` juga, karena promo ini secara eksplisit menyatakan package-nya ikut tercakup sebagai free (lihat contoh ke-2 di bawah).

**`menuPackageList[]` field lain** (2026-11-17, sempat ketinggalan di implementasi awal — sekarang sudah lengkap sama persis [Menu List](MENU%20LIST.md)):
- `taxId`/`taxRate` — resolve dari `vat`/`pb1` milik `visit_purpose_id` ini (`mr_branch_visit_purpose`) dicocokkan ke `taxType` sub-item + lookup `mr_tax.rate`, pola sama `GetMasterMenuList()`. `null` kalau `taxType` sub-item gak match `vat`/`pb1` yang aktif di visit purpose ini.
- `notesMenu` — di-resolve pakai `categoryId`/`subCategoryId` **milik sub-item itu sendiri** (bukan ikut item free induknya), karena 1 package bisa lintas kategori.
- `defaultItem` — passthrough `mr_item_package_detail.default_item`, menandai sub-item mana yang pre-selected di UI.

Karena resolve dilakukan per PROMO (bukan di-cache per item), 2 promo `freeitem` yang kebetulan pakai `type_freeitem_item_id` yang sama tapi `flag_include_package` berbeda akan tetap menghasilkan `menuPrice` yang benar masing-masing (gak saling menimpa).
- **8 barrier struktural** yang dicek (semua harus lolos): `is_active`, `period_start`/`period_end` (periode berlaku), visit purpose (`flag_all_visit_purposes` atau eksplisit match), member type (`flag_all_type_members` atau match), tier (`flag_all_tiers` atau match), hari (`flag_all_days` atau match hari ini), jam (`flag_all_times` atau jam sekarang dalam rentang), channel (`flag_apply_to_all` atau eksplisit `apply_to = 'pos'`).
- Tidak ada promo yang eligible → `data: []`, bukan error.

## Alur Implementasi

1. Dipanggil saat halaman Order dibuka (`getPromoList(visit_purpose_id)`, **tanpa** `member_type_id` — member belum dipilih di titik ini) — hasil ditampilkan sebagai daftar promo yang bisa dipilih kasir untuk di-apply ke order.
2. Promo yang dipilih kasir dikirim sebagai bagian body `save-order`/`order/calculate` (field persis ada di [`../ORDER/SAVE ORDER.md`](../ORDER/SAVE%20ORDER.md)) — endpoint List ini **tidak** melakukan validasi final (cuma menyaring mana yang SECARA STRUKTURAL eligible untuk ditampilkan), validasi lengkap (termasuk cek `targetIds` match ke item di cart, `min_buy_amount`, limit harian) terjadi di server saat order benar-benar disimpan.
3. Kalau alur UI punya titik "pilih member dulu, baru promo" — panggil ulang endpoint ini dengan `member_type_id`/`tier_level` terisi untuk memunculkan promo tambahan yang tadinya tersembunyi.

## Sumber

- `app/Http/Controllers/MasterController.php` — `GetPromoList()`, `AttachPromoTargetIds()`, `AttachUsedToday()`, `AttachPromoFreeItemDetail()` (2026-11-17, semuanya private).
- `app/Services/MenuServices.php` — `GetAllNotesMenuForMatching()`/`ResolveNotesMenu()` (public static, direuse `AttachPromoFreeItemDetail()` buat resolve `notesMenu` pada `type_freeitem_item_detail`).
- Frontend: `src/services/MasterServices.ts` (`getPromoList`).

## Status

Didokumentasikan 2026-10-12, berdasar pembacaan kode langsung (belum ada verifikasi ulang lewat HTTP request live di sesi ini). Alur apply/validasi final promo ke order akan ditelusuri lebih dalam saat menulis [`../ORDER/SAVE ORDER.md`](../ORDER/SAVE%20ORDER.md).

**`type_freeitem_item_detail` (2026-11-17)**: syntax check (`php -l`) bersih, **belum divalidasi live lewat HTTP request** di sesi ini (DB access sempat diblokir classifier saat sesi berjalan) — logic-nya mengikuti pola `GetMasterMenuList()`/`ResolveNotesMenu()` yang sudah terbukti jalan di Menu List, tapi field baru ini sendiri belum dicoba end-to-end ke promo `type='freeitem'` yang `type_freeitem_item_id`-nya terisi beneran. Perlu ditest manual sebelum deploy.

**`type_bill_amount` dan tipe `bill` (2026-11-26)** — `mr_promo.type` kini bisa `bill` (Bill Discount: potongan nominal untuk seluruh tagihan), nominalnya di field baru `type_bill_amount` (`decimal(20,2)`, default `0`, string di JSON seperti `type_rupiah_amount`; `0.00` untuk tipe lain). Datanya dibawa sync promo dari ERP (sudocore2 migration 357, kolom POS lewat migration `2026_11_26_000001_add_type_bill_amount_to_mr_promo`). Endpoint ini mengembalikan semua tipe tanpa filter, jadi promo `bill` ikut muncul; **backend POS belum punya logika hitung diskon bill** — FE yang menentukan cara memakainya. Dicek lewat `php -l`/migration jalan; belum diuji lewat HTTP.
