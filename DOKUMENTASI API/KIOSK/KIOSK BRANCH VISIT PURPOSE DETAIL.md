# Kiosk Branch Visit Purpose Detail

```
GET /api/kiosk/branch-visit-purpose/{id}
```

Gak butuh token. Config visit purpose (`service_charge`/`vat`/`pb1`/`order_fee`, plus rate persennya masing-masing) + pohon menu lengkap (category > subcategory > item) buat visit purpose itu, sekali panggil.

`{id}` — `visit_purpose_id` (dari [KIOSK BRANCH VISIT PURPOSE.md](./KIOSK%20BRANCH%20VISIT%20PURPOSE.md)).

Response:

```json
{
    "code": 0,
    "data": {
        "visit_purpose_id": 2,
        "service_charge": 9,
        "service_charge_rate": "5.00",
        "vat": 100,
        "vat_rate": "11.00",
        "pb1": 100,
        "pb1_rate": "11.00",
        "order_fee": "0.00",
        "menu_pricelist_id": 12,
        "categories": [
            {
                "category_id": 39,
                "category_name": "MENU FOOD",
                "subcategories": [
                    {
                        "subcategory_id": 12,
                        "subcategory_name": "FOOD",
                        "icon_src": "/img/subcategory/019f648a-f12e-7252-a76f-aa8fc55679bf.jpg",
                        "banner_src": "/img/subcategory-banner/019f648a-f12e-7252-a76f-aa8fc55679bf.jpg",
                        "items": [
                            {
                                "detail_pricelist_id": 93,
                                "item_id": 120,
                                "item_id_real": 131,
                                "menu_code": "PM1",
                                "menu_name": "PAKET MERCON",
                                "description": null,
                                "menu_color": "#2563eb",
                                "image_src": "/img/item/019f6f6b-ac43-7f11-a421-53efae5e0402.jpg",
                                "icon_src": "/img/item-icon/019f648a-f12e-7252-a76f-aa8fc55679bf.jpg",
                                "bom_id": null,
                                "category_id": 39,
                                "subcategory_id": 12,
                                "menu_price": "20000.00",
                                "flag_inclusive_tax": 1,
                                "tax_type": "vat",
                                "stok_qty": 0,
                                "flag_sold_out": 0,
                                "tax_id": 100,
                                "tax_rate": "11.00",
                                "package_id_real": 53,
                                "separate_print_package": 0,
                                "notes_menu": ["Tambah level pedas 1 tingkat", "Sugar free"],
                                "package_list": [
                                    {
                                        "package_id": 9,
                                        "package_name": "PAKET 1",
                                        "min_qty": 1,
                                        "max_qty": 1,
                                        "menu_package_list": [
                                            {
                                                "menu_package_id": 210,
                                                "item_id": 55,
                                                "menu_name": "NASI PUTIH",
                                                "description": null,
                                                "menu_price": "0.00",
                                                "tax_type": "vat",
                                                "bom_id": null,
                                                "icon_src": null,
                                                "tax_id": 100,
                                                "tax_rate": "11.00",
                                                "default_item": true,
                                                "notes_menu": []
                                            },
                                            {
                                                "menu_package_id": 211,
                                                "item_id": 56,
                                                "menu_name": "NASI GORENG",
                                                "menu_price": "5000.00",
                                                "tax_type": "vat",
                                                "bom_id": null,
                                                "icon_src": null,
                                                "tax_id": 100,
                                                "tax_rate": "11.00",
                                                "default_item": false,
                                                "notes_menu": ["Extra pedas level 2"]
                                            }
                                        ]
                                    },
                                    {
                                        "package_id": 10,
                                        "package_name": "PAKET 2",
                                        "min_qty": 1,
                                        "max_qty": 10,
                                        "menu_package_list": [
                                            {
                                                "menu_package_id": 220,
                                                "item_id": 61,
                                                "menu_name": "ES TEH",
                                                "menu_price": "0.00",
                                                "tax_type": "vat",
                                                "bom_id": null,
                                                "icon_src": null,
                                                "tax_id": 100,
                                                "tax_rate": "11.00"
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

`package_list` di atas contoh ilustrasi struktur (item bebas pilih 1 dari "PAKET 1" + boleh nambah dari "PAKET 2", `min_qty`/`max_qty` per group ngatur wajib/batasnya) — bukan hasil curl asli. **Update**: sejak testing `icon_src` (2026-08-12) ketemu kombinasi visit purpose yang beneran punya package terisi (`visit_purpose_id: 3`, item "AMERICANO / ON THE ROCK" dengan package "VARIAN") — struktur di atas udah tervalidasi sesuai data real, cuma nilai contohnya masih ilustrasi.

Kalau `id` gak ketemu:

```json
{ "code": 100, "message": "visit purpose tidak ditemukan" }
```

- `service_charge`/`vat`/`pb1` — nilainya itu **tax_id** (FK ke `mr_tax.id`), bukan rate langsung.
- `service_charge_rate`/`vat_rate`/`pb1_rate` — persentase asli dari `mr_tax.rate` (lookup dari tax_id di atas), `null` kalau tax_id-nya gak ketemu di `mr_tax`.
- `image_src` — path relatif ke gambar item (dari `mr_item.image`, di-download & disimpen lokal pas sync, lihat `SetupServices::downloadImage()`), `null` kalau item belum ada gambarnya. **Path relatif**, bukan URL penuh — frontend perlu prefix sendiri pakai base URL Laravel (sama pola kayak `logo_header_src`/`image_footer_src` branch di `paymentPage.vue`: `${API_BASE}${item.image_src}`).
- `icon_src` (di tiap `subcategories[]`) — path relatif ke icon sub category (dari `mr_subcategory.icon_src`, di-download & disimpen lokal pas sync, lihat `SetupServices::getSubCategoryList()`), `null` kalau sub category itu belum ada icon-nya. Sama aturan path relatif kayak `image_src` item.
- `banner_src` (di tiap `subcategories[]`) — path relatif ke banner sub category (dari `mr_subcategory.banner_src`, di-download & disimpen lokal pas sync di subfolder `subcategory-banner`, terpisah dari `icon_src` yang di `subcategory` — 2 file beda per sub category), `null` kalau sub category itu belum ada banner-nya. Sama aturan path relatif.
- `icon_src` (di tiap `items[]` dan di tiap `menu_package_list[]`) — path relatif ke icon item (dari `mr_item.icon_src`, di-download & disimpen lokal pas sync, lihat `SetupServices::getMasterItem()`), `null` kalau item itu belum ada icon-nya. Sama aturan path relatif, terpisah dari `image_src` (dua file berbeda per item).
- `notes_menu` (di tiap `items[]` dan di tiap `menu_package_list[]`) — array string **full_notes** dari `master_notes_menu_detail` yang applies_to-nya match ke `category_id`/`subcategory_id` item itu (lihat "Update (2026-09-24)" di bawah). **Bukan** object `{short_notes, full_notes}` kayak endpoint POS `/api/master/notes-menu/{item_conv_id}` — Kiosk cuma butuh `full_notes`, langsung array string. Array kosong `[]` kalau gak ada notes menu yang match (bukan `null`).

## Sumber & pemetaan

Reuse `MenuServices::GetMasterMenuList()` apa adanya (tax resolution & package handling-nya sama persis kayak versi POS existing, `/api/master/menu-list`) — cuma difilter ke 1 `visit_purpose_id` dan field-nya di-reshape ke `snake_case`. Kalau nanti ada bug/perubahan logic tax atau package di versi POS, ikut kepakai di sini juga (satu sumber logic).

**Filter item mana yang boleh muncul** (`$listmenu` query di `GetMasterMenuList()`): `mr_pricelist_detail.pricelist_id = <menu_pricelist_id visit purpose ini>` **DAN** `mr_pricelist_detail.qr_order = true` (2026-09-18, sebelumnya `pos = true`, lihat "Update 2026-09-18" di bawah). Category & subcategory query **TIDAK** ikut difilter channel apa pun (cuma `pricelist_id`) — kalau semua item di 1 subcategory kebetulan `qr_order = false` semua, subcategory-nya tetap muncul dengan `items: []` kosong, bukan ikut hilang. `flag_soldout`/`stok_qty` juga cuma info tampilan, item sold-out tetap muncul di list, gak disembunyiin.

Pemetaan nama field per level (versi POS camelCase → kiosk snake_case):

| POS (`menuList[]`)     | Kiosk (`items[]`)        |
| ---------------------- | ------------------------ |
| `menuPricelistId`      | `detail_pricelist_id`    |
| `itemId`               | `item_id`                |
| `itemid_real`          | `item_id_real`           |
| `menuCode`             | `menu_code`              |
| `menuName`             | `menu_name`              |
| `menuDescription`      | `description`            |
| `menuColor`            | `menu_color`             |
| `imageSrc`             | `image_src`              |
| `iconSrc`               | `icon_src`               |
| `bomId`                | `bom_id`                 |
| `categoryId`           | `category_id`            |
| `subCategoryId`        | `subcategory_id`         |
| `menuPrice`            | `menu_price`             |
| `flagInclusiveTax`     | `flag_inclusive_tax`     |
| `taxType`              | `tax_type`               |
| `stokQty`              | `stok_qty`               |
| `flagSoldOut`          | `flag_sold_out`          |
| `taxId`                | `tax_id`                 |
| `taxRate`              | `tax_rate`               |
| `packageid_real`       | `package_id_real`        |
| `separatePrintPackage` | `separate_print_package` |
| `packageList`          | `package_list`           |

`package_list[]` (kalau item punya package) juga di-snake_case-in: `packageId`→`package_id`, `packageName`→`package_name`, `minQty`→`min_qty`, `maxQty`→`max_qty`, `menuPackageList`→`menu_package_list` (isinya: `menuPackageId`→`menu_package_id`, `itemId`→`item_id`, `menuName`→`menu_name`, `menuDescription`→`description`, `menuPrice`→`menu_price`, `taxType`→`tax_type`, `bomId`→`bom_id`, `iconSrc`→`icon_src`, `taxId`→`tax_id`, `taxRate`→`tax_rate`, `defaultItem`→`default_item`).

`subcategories[]` juga ada pemetaan sendiri (versi POS `$subcategory` query → kiosk): `subCategoryId`→`subcategory_id`, `SubCategoryName`→`subcategory_name`, `subCategoryIconSrc`→`icon_src`, `subCategoryBannerSrc`→`banner_src`.

## Update (2026-08-11)

`image_src` ditambahin — sebelumnya `MenuServices::GetMasterMenuList()` gak nge-select `mr_item.image` sama sekali, jadi Kiosk gak bisa nampilin gambar item. Sekarang di-tambahin ke query `$listmenu` (`mi.image as imageSrc`), otomatis kepakai juga di endpoint POS lain yang reuse fungsi yang sama (satu sumber logic). Tervalidasi live: set gambar test di 1 item, `image_src` muncul bener di response, direvert lagi abis test.

## Update (2026-08-12)

`icon_src` ditambahin di tiap `subcategories[]` — nyusul kolom `mr_subcategory.icon_src` yang baru ditambah bareng sync pull-nya (lihat `SYNC PULL.md`). Query `$subcategory` di `MenuServices::GetMasterMenuList()` ditambah `msc.icon_src as subCategoryIconSrc`, dipetakan ke `icon_src` di `KioskController::GetBranchVisitPurposeDetail()`. Sama kayak `image_src` item, ini otomatis kepakai juga di endpoint POS lain yang reuse fungsi yang sama. Tervalidasi live: set icon test di 1 sub category yang beneran ada di pohon menu visit purpose, `icon_src` muncul bener di response-nya, sub category lain tetap `null`, direvert lagi abis test.

## Update (2026-08-12, lanjutan)

`icon_src` ditambahin juga di **level item** — di tiap `items[]` (menu utama) **dan** di tiap `menu_package_list[]` (item di dalam package/varian) — nyusul kolom `mr_item.icon_src` yang baru ditambah (lihat `SYNC PULL.md`, sekarang `getMasterItem()` download 2 file per item: `image` dan `icon_src`, subfolder beda). Query `$listmenu` (item utama) dan `$menuPackageDetail` (item di dalam package) di `MenuServices::GetMasterMenuList()` sama-sama ditambah `mi.icon_src as iconSrc`, dipetakan ke `icon_src` di `mapKioskMenuItem()`.

Tervalidasi live: set icon test di 2 item real (1 item utama, 1 item yang ada di dalam `menu_package_list` beneran, bukan contoh ilustrasi lagi — nemu kombinasi visit purpose yang emang punya package terisi) — `icon_src` muncul bener di kedua level, direvert lagi abis test.

## Update (2026-08-19)

`banner_src` ditambahin di tiap `subcategories[]` — nyusul kolom `master_item_sub_category.banner_src` di ERP (hasil rename `image_src`→`banner_src`, lihat `MASTER ITEM SUB CATEGORY.md`) yang sebelumnya **belum ketarik sama sekali** ke POS/Kiosk (mentok di ERP, bridge `APIANDORDER` cuma nge-`SELECT icon_src`). Ditarik lewat 3 lapisan:

1. **Bridge (`APIANDORDER`)** — `MasterService.GetMasterSubCategory()` ditambah `COALESCE(msc.banner_src, '') as banner_src`, `SubCategoryDTO` ditambah field `BannerSrc`.
2. **POS** — kolom `banner_src` ditambah ke `mr_subcategory` (migration `2026_08_19_100000_add_banner_src_to_mr_subcategory.php`), `SetupServices::getSubCategoryList()` di-update buat download banner-nya juga (subfolder `subcategory-banner`, terpisah dari `icon_src`).
3. **Kiosk** — query `$subcategory` di `MenuServices::GetMasterMenuList()` ditambah `msc.banner_src as subCategoryBannerSrc`, dipetakan ke `banner_src` di `KioskController::GetBranchVisitPurposeDetail()`.

Tervalidasi: bridge query dites langsung ke DB ERP (set `banner_src` test di 1 sub category real yang match ke branch, query balikin nilainya bener, direvert lagi abis test). Kode POS (`SetupServices`/`MenuServices`/`KioskController`) lolos `php -l`, migration udah di-apply & kolom `banner_src` dikonfirmasi ada di `mr_subcategory`. **Belum** dites end-to-end lewat request HTTP asli (butuh server bridge `APIANDORDER` nyala buat proses sync-nya, gak lagi jalan pas sesi ini) — kalau nanti provisioning banner beneran dipakai, disarankan sync manual 1 branch dulu buat mastiin file ke-download bener.

## Update (2026-08-26)

Harga sub-item package (`menu_package_list[].menu_price`) sekarang bisa BEDA per menu template (pricelist), nutup gap yang sebelumnya didokumentasikan sebagai keterbatasan (`mipd.price` selalu dipakai flat, gak peduli visit purpose). Ditambah `default_item` — nandain sub-item mana yang "pre-selected" pas customer/kasir buka package group ini.

Sumbernya dari ERP: kolom baru `master_item_package_detail.flag_all_menu_template`/`default_item` + tabel baru `master_item_package_detail_menu_template` (override harga per menu_template), lihat `MASTER ITEM.md` (`sudocore2`) buat detail skemanya. Ditarik ke lokal POS lewat sync pull baru (`mr_item_package_detail.flag_all_menu_template`/`default_item`, tabel baru `mr_item_package_detail_pricelist` — lihat `SYNC PULL.md`).

**Logic resolusi harga** (di `MenuServices::GetMasterMenuList()`, dalam loop per-visit-purpose, bareng resolusi tax yang udah ada):

- `flag_all_menu_template = true` → `menu_price` dari `mr_item_package_detail.price` apa adanya (behavior lama, gak berubah).
- `flag_all_menu_template = false` → dicari baris `mr_item_package_detail_pricelist` yang cocok (`item_package_detail_id` + `pricelist_id` = `menu_pricelist_id` visit purpose ini):
  - ketemu → `menu_price` = harga override.
  - **gak ketemu → FALLBACK ke `mr_item_package_detail.price`** (keputusan bisnis: mending kepake harga header daripada sub-item gak ada harga sama sekali).

`default_item` murni passthrough (gak ada logic tambahan) — kolom internal `flag_all_menu_template` **TIDAK ikut** di response (di-`unset` sebelum balik, cuma dipakai internal buat nentuin resolusi harga).

Karena satu sumber logic (`GetMasterMenuList()`), perbaikan harga ini otomatis kepakai juga di `/api/master/menu-list` (POS). **`default_item` beda** — endpoint POS otomatis kebawa (gak ada reshape), tapi Kiosk butuh 1 baris tambahan manual di `mapKioskMenuItem()` (whitelist field eksplisit, field yang gak disebut di situ gak ikut kekirim).

Tervalidasi lewat skrip manual (insert override sementara ke DB, panggil `GetMasterMenuList()` langsung, cek `menu_price` ke-resolve bener + `default_item` passthrough bener + fallback ke harga header pas override dihapus) — bukan cuma baca kode. Data test dibersihkan total setelah verifikasi.

## Update (2026-08-27)

`description` ditambahin di **level item** — di tiap `items[]` (menu utama) **dan** di tiap `menu_package_list[]` (item di dalam package/varian), sama pola kayak `icon_src` (Update 2026-08-12). Sumbernya dari ERP: `master_item.item_description` (`sudocore2`), ditarik lewat 3 lapisan:

1. **Bridge (`APIANDORDER`)** — `MasterService.GetItem()` ditambah `COALESCE(mi.item_description, '') as description`, `MasterItem` DTO ditambah field `Description`.
2. **POS** — kolom `description` ditambah ke `mr_item` (migration `2026_08_27_100000_add_description_to_mr_item.php`, nullable, gak perlu kode tambahan di `SetupServices::getMasterItem()` -- `upsertRows()` generic, otomatis kepetakan selama nama kolom cocok). Query `$listmenu` dan `$menuPackageDetail` di `MenuServices::GetMasterMenuList()` ditambah `mi.description as menuDescription`.
3. **Kiosk** — `menuDescription`→`description` ditambah manual di `mapKioskMenuItem()` (whitelist field eksplisit, sama kayak `default_item` di Update 2026-08-26) -- **POS** (`/api/master/menu-list`) otomatis kebawa (gak ada reshape), gak butuh perubahan tambahan.

Tervalidasi lewat `tinker`: isi `description` test di 1 item real (`mr_item.id=83`, "CARAME MACHIATO") yang beneran ada di pohon menu, panggil `GetMasterMenuList()` langsung → `menuDescription` ke-resolve bener, lalu panggil `mapKioskMenuItem()` (lewat reflection, method-nya `private`) → `description` ke-passthrough bener ke output final. Data test direvert (`NULL`) abis verifikasi. Field `description` di ERP (`master_item.item_description`) & bridge (`APIANDORDER`) query juga udah dites terpisah (lihat `GET VISIT PURPOSE DETAIL.md` di `sudomobile`, sumber datanya sama).

## Update (2026-09-18)

**Filter channel item Kiosk PINDAH dari `pos` ke `qr_order`** (keputusan bisnis: Kiosk = terminal self-service di outlet, semantiknya lebih deket ke customer self-service — sama kayak QR Order (HP customer sendiri) — daripada ke POS staff/kasir). Gak ada flag "kiosk" sendiri di skema manapun (dicek langsung ke ERP `master_pricelist_detail`: cuma ada `pos`/`qr_order`/`is_deleted`), jadi Kiosk numpang salah satu dari 2 yang udah ada.

`MenuServices::GetMasterMenuList()` sekarang nerima parameter `string $channel = 'pos'` — `MasterController::GetMasterMenuList()` (`/api/master/menu-list`, POS staff) manggil eksplisit `GetMasterMenuList('pos')` (gak berubah perilakunya), `KioskController::GetBranchVisitPurposeDetail()` manggil `GetMasterMenuList('kiosk')` → filter item-nya jadi `mpd.qr_order = true`. Cuma item-level query (`$listmenu`) yang kena; category/subcategory query TETAP gak difilter channel (lihat catatan di "Sumber & pemetaan" di atas, gak diubah bareng ini).

**Tervalidasi live** (bukan cuma `php -l`, request HTTP asli + query langsung ke `mr_pricelist_detail`):
- Item `id=51` (pricelist `5`, `pos=0`/`qr_order=1`, "NASI LEMAK NUSANTARA") → **absen** di `/api/master/menu-list`, **muncul** di Kiosk (`visit_purpose_id=1`).
- Item `id=101/102/103` (pricelist `7`, `pos=1`/`qr_order=0`, AMERICANO/CAPPUCCINO/ESPRESSO) → **muncul** di `/api/master/menu-list`, **absen** di Kiosk (`visit_purpose_id=8`).

Dua arah kebukti bener — bukan kebetulan satu channel kebetulan superset yang lain di data test ini.

## Update (2026-09-24)

`notes_menu` ditambahin di tiap `items[]` (menu utama) **dan** di tiap `menu_package_list[]` (sub-item package) — keputusan bisnis: Kiosk sudah narik seluruh pohon menu sekali di awal (bukan pola on-demand per-klik kayak POS), jadi notes menu-nya langsung diembed sekalian di sini biar gak perlu roundtrip tambahan pas customer buka form notes. Endpoint terpisah `GET /api/kiosk/notes-menu/{item_conv_id}` yang sempat dibuat buat ini **sudah dihapus** — digantikan penuh oleh field ini.

Sumber & rule matching-nya SAMA PERSIS kayak `MenuServices::GetNotesMenuByItemConv()` (dipakai endpoint POS, lihat `MASTER/NOTES MENU BY ITEM CONV.md`) — `all_category` selalu ikut, `category`/`sub_category` match `category_id`/`subcategory_id`. Bedanya cuma di 2 hal:

1. **Ditarik sekali** lewat `MenuServices::GetAllNotesMenuForMatching()` (semua baris notes menu + detail, JOIN tanpa filter), bukan query per `item_conv_id` — biar gak N+1 query pas loop semua item pohon menu. Matching-nya di-lakuin in-memory lewat `MenuServices::ResolveNotesMenuFullNotes($allNotesMenu, $categoryId, $subCategoryId)`.
2. **Cuma `full_notes`** yang dikirim (bukan `short_notes` juga) — array string langsung, bukan object `{short_notes, full_notes}`. String duplikat (notes menu yang sama match ke banyak item) di-dedup per baris (`array_unique` via key array di `ResolveNotesMenuFullNotes()`), tapi TETAP terduplikasi ANTAR item (kalau 50 item sama-sama match notes menu `all_category`, string itu ke-copy 50x di response — trade-off yang disengaja demi kesederhanaan struktur, dibanding taruh notes menu terpisah di root dan biar FE yang gabungin).

**Sub-item package** (`menu_package_list[].notes_menu`) matching-nya pakai `category_id`/`subcategory_id` **milik sub-item itu sendiri** (dari `mr_item` sub-item via `mr_item_package_detail.item_conv_detail_id` → `mr_item_conv` → `mr_item`), BUKAN ikut `category_id`/`subcategory_id` item paket induknya — karena 1 package bisa berisi sub-item lintas kategori (mis. minuman di dalam package makanan).

Tervalidasi lewat `php -l` (`MenuServices.php`, `KioskController.php`) dan request HTTP live ke `/api/kiosk/branch-visit-purpose/{id}` — `notes_menu` muncul di kedua level (item utama & sub-item package), array kosong buat item yang gak match, gak ada regresi ke field lain.

## Catatan performa

`GetMasterMenuList()` menghitung pohon menu buat **semua** visit purpose branch ini, baru difilter ke satu `id` di controller. Belum dioptimasi buat query cuma 1 visit purpose dari awal (butuh ubah signature fungsi shared yang juga dipakai POS) — untuk sekarang masih aman karena volume data per branch relatif kecil.
