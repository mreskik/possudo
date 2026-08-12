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
                        "items": [
                            {
                                "detail_pricelist_id": 93,
                                "item_id": 120,
                                "item_id_real": 131,
                                "menu_code": "PM1",
                                "menu_name": "PAKET MERCON",
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
                                                "menu_price": "0.00",
                                                "tax_type": "vat",
                                                "bom_id": null,
                                                "icon_src": null,
                                                "tax_id": 100,
                                                "tax_rate": "11.00"
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
                                                "tax_rate": "11.00"
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
- `icon_src` (di tiap `items[]` dan di tiap `menu_package_list[]`) — path relatif ke icon item (dari `mr_item.icon_src`, di-download & disimpen lokal pas sync, lihat `SetupServices::getMasterItem()`), `null` kalau item itu belum ada icon-nya. Sama aturan path relatif, terpisah dari `image_src` (dua file berbeda per item).

## Sumber & pemetaan

Reuse `MenuServices::GetMasterMenuList()` apa adanya (tax resolution & package handling-nya sama persis kayak versi POS existing, `/api/master/menu-list`) — cuma difilter ke 1 `visit_purpose_id` dan field-nya di-reshape ke `snake_case`. Kalau nanti ada bug/perubahan logic tax atau package di versi POS, ikut kepakai di sini juga (satu sumber logic).

Pemetaan nama field per level (versi POS camelCase → kiosk snake_case):

| POS (`menuList[]`)     | Kiosk (`items[]`)        |
| ---------------------- | ------------------------ |
| `menuPricelistId`      | `detail_pricelist_id`    |
| `itemId`               | `item_id`                |
| `itemid_real`          | `item_id_real`           |
| `menuCode`             | `menu_code`              |
| `menuName`             | `menu_name`              |
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

`package_list[]` (kalau item punya package) juga di-snake_case-in: `packageId`→`package_id`, `packageName`→`package_name`, `minQty`→`min_qty`, `maxQty`→`max_qty`, `menuPackageList`→`menu_package_list` (isinya: `menuPackageId`→`menu_package_id`, `itemId`→`item_id`, `menuName`→`menu_name`, `menuPrice`→`menu_price`, `taxType`→`tax_type`, `bomId`→`bom_id`, `iconSrc`→`icon_src`, `taxId`→`tax_id`, `taxRate`→`tax_rate`).

`subcategories[]` juga ada pemetaan sendiri (versi POS `$subcategory` query → kiosk): `subCategoryId`→`subcategory_id`, `SubCategoryName`→`subcategory_name`, `subCategoryIconSrc`→`icon_src`.

## Update (2026-08-11)

`image_src` ditambahin — sebelumnya `MenuServices::GetMasterMenuList()` gak nge-select `mr_item.image` sama sekali, jadi Kiosk gak bisa nampilin gambar item. Sekarang di-tambahin ke query `$listmenu` (`mi.image as imageSrc`), otomatis kepakai juga di endpoint POS lain yang reuse fungsi yang sama (satu sumber logic). Tervalidasi live: set gambar test di 1 item, `image_src` muncul bener di response, direvert lagi abis test.

## Update (2026-08-12)

`icon_src` ditambahin di tiap `subcategories[]` — nyusul kolom `mr_subcategory.icon_src` yang baru ditambah bareng sync pull-nya (lihat `SYNC PULL.md`). Query `$subcategory` di `MenuServices::GetMasterMenuList()` ditambah `msc.icon_src as subCategoryIconSrc`, dipetakan ke `icon_src` di `KioskController::GetBranchVisitPurposeDetail()`. Sama kayak `image_src` item, ini otomatis kepakai juga di endpoint POS lain yang reuse fungsi yang sama. Tervalidasi live: set icon test di 1 sub category yang beneran ada di pohon menu visit purpose, `icon_src` muncul bener di response-nya, sub category lain tetap `null`, direvert lagi abis test.

## Update (2026-08-12, lanjutan)

`icon_src` ditambahin juga di **level item** — di tiap `items[]` (menu utama) **dan** di tiap `menu_package_list[]` (item di dalam package/varian) — nyusul kolom `mr_item.icon_src` yang baru ditambah (lihat `SYNC PULL.md`, sekarang `getMasterItem()` download 2 file per item: `image` dan `icon_src`, subfolder beda). Query `$listmenu` (item utama) dan `$menuPackageDetail` (item di dalam package) di `MenuServices::GetMasterMenuList()` sama-sama ditambah `mi.icon_src as iconSrc`, dipetakan ke `icon_src` di `mapKioskMenuItem()`.

Tervalidasi live: set icon test di 2 item real (1 item utama, 1 item yang ada di dalam `menu_package_list` beneran, bukan contoh ilustrasi lagi — nemu kombinasi visit purpose yang emang punya package terisi) — `icon_src` muncul bener di kedua level, direvert lagi abis test.

## Catatan performa

`GetMasterMenuList()` menghitung pohon menu buat **semua** visit purpose branch ini, baru difilter ke satu `id` di controller. Belum dioptimasi buat query cuma 1 visit purpose dari awal (butuh ubah signature fungsi shared yang juga dipakai POS) — untuk sekarang masih aman karena volume data per branch relatif kecil.
