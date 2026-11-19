# View Order & Only View Order

## View Order (lengkap)

```
GET /api/order/view-order/{order_number}
```

Detail order lengkap + seluruh item/sub-item — dipakai untuk **edit cart** (reload state sebelum tambah item/apply promo lagi), bukan cuma tampilan baca saja.

Response (struktur, camelCase konsisten dengan [`SAVE ORDER.md`](SAVE%20ORDER.md)):
```json
{
  "code": 0,
  "data": {
    "orderNumber": "NOSBE2026101209001234",
    "orderName": "Budi",
    "customerPhoneNumber": "081234567890",
    "typeOrder": "dinein",
    "visitPurposeId": 7,
    "visitPurposeName": "Dine In",
    "orderPax": 2,
    "totalItem": 2,
    "subTotal": "111930.00",
    "totalTax": "11193.00",
    "totalServiceCharge": "5590.50",
    "orderFee": "2000.00",
    "deliveryCost": "1000.00",
    "totalBilling": "123123.00",
    "totalDiscount": "15000.00",
    "tableSectionId": 1,
    "tableSectionName": "Indoor",
    "tableId": 10,
    "tableName": "Meja 1",
    "orderDate": "2026-10-12",
    "orderIn": "2026-10-12T14:35:22+07:00",
    "status": "pending",
    "memberId": null,
    "memberName": null,
    "memberPhoneNumber": null,
    "listOrder": [
      {
        "ulid": "01hx...",
        "menuPricelistId": 93,
        "menuId": 120,
        "menuName": "PAKET MERCON",
        "menuShortName": "Mercon",
        "orderBatch": 1,
        "qty": 2,
        "notes": "less ice",
        "flagInclusiveTax": true,
        "price": "20000.00",
        "taxId": 100,
        "taxType": "vat",
        "taxRate": "11.00",
        "taxAmount": "1980.00",
        "serviceChargeId": 16,
        "serviceChargeRate": "5.00",
        "serviceChargeAmount": "900.90",
        "promoId": 23,
        "promoName": "Diskon 20%",
        "isFreeItemPromo": false,
        "discountPercent": "20.00",
        "discountAmount": "4000.00",
        "netPrice": "18018.00",
        "dpp": "14414.40",
        "total": "16000.00",
        "cancelAt": null,
        "cancelNotes": null,
        "itemIdReal": 131,
        "categoryId": 39,
        "subCategoryId": 12,
        "menuPackageList": [
          {
            "ulid": "01hx...",
            "menuPackageId": 210,
            "menuId": 55,
            "menuName": "NASI PUTIH",
            "menuShortName": "Nasi Putih",
            "qty": 1,
            "notes": null,
            "flagInclusiveTax": true,
            "price": "0.00",
            "taxId": 100,
            "taxType": "vat",
            "taxRate": "11.00",
            "taxAmount": "0.00",
            "serviceChargeId": 16,
            "serviceChargeRate": "5.00",
            "serviceChargeAmount": "0.00",
            "promoId": null,
            "discountPercent": "0.00",
            "discountAmount": "0.00",
            "netPrice": "0.00",
            "dpp": "0.00",
            "total": "0.00"
          }
        ]
      }
    ]
  }
}
```

- **⚠️ `netPrice`/`dpp` (2026-11-18, `netDpp` DIHAPUS, BUKAN sekadar rename)** — response sekarang pakai nama field APA ADANYA sesuai nama kolom DB: `netPrice` (dari kolom `net_price` — harga sebelum diskon, sudah dilepas pajak+service charge kalau inclusive) dan `dpp` (dari kolom `dpp` — basis pajak SETELAH diskon, DPP akuntansi yang benar). Field lama `netDpp` **DIHAPUS TOTAL** dari response — sebelumnya `dpp` di response itu alias kolom `net_price` (artinya SEBELUM diskon) dan `netDpp` alias kolom `dpp` (SETELAH diskon), sekarang `dpp` di response **artinya SUDAH BERUBAH** jadi SETELAH diskon (ikut makna kolom DB yang sama). **Ini breaking change buat consumer existing** yang masih baca field `dpp`/`netDpp` dari endpoint ini (`posv1-vue`: `orderPage.vue`/`paymentPage.vue` punya fungsi `recomputeDppTax()`/`netPrice()` yang hitung ulang dpp/netDpp sendiri di client, PLUS konsumsi field ini — lihat catatan Status di bawah, **migrasi FE BELUM dikerjakan di sesi ini**, sengaja di luar scope, cuma backend/API yang diselaraskan).
- **`serviceChargeId`/`serviceChargeRate`/`serviceChargeAmount` (2026-11-18, BARU, level item & package)** — kolom baru `tr_order_detail`/`tr_order_detail_package`, lihat [`SAVE ORDER.md`](SAVE%20ORDER.md) buat formula kalkulasinya.
- **`totalServiceCharge`/`orderFee`/`deliveryCost` (2026-11-18, BARU, level header)** — `totalServiceCharge` hasil `Σ qty × serviceChargeAmount` semua baris (`OrderServices::RecalculateOrderTotals()`), `orderFee`/`deliveryCost` FE-trusted (manual input, gak dihitung ulang). Lihat [`SAVE ORDER.md`](SAVE%20ORDER.md) buat detail lengkap.
- `ulid` tiap baris `listOrder[]`/`menuPackageList[]` — **wajib** disertakan kalau item itu dikirim ulang ke [`SAVE ORDER.md`](SAVE%20ORDER.md) (penanda "item existing", bukan item baru).
- `cancelAt`/`cancelNotes` — terisi kalau baris item ini sudah di-cancel individual (lihat [`CANCEL ITEM.md`](CANCEL%20ITEM.md)) — baris tetap muncul di response (tidak di-filter keluar), FE yang menentukan cara menampilkannya (biasanya dicoret/disembunyikan).
- `itemIdReal`/`categoryId`/`subCategoryId` — dipakai ulang untuk `matchesPromoTarget()` kalau order ini mau diberi promo tambahan.
- Tidak ada filter status — order `paid`/`cancel` tetap bisa dipanggil (beda dari `onlyViewOrder` di bawah).

## Only View Order (ringkas, read-only)

```
GET /api/order/only-view/{order_number}
```

Header order **tanpa** `listOrder[]` — murni untuk tampilan ringkas (preview sebelum klik edit, atau badge info).

Response: sama field header seperti di atas (`orderNumber`, `orderName`, `totalBilling`, dst), **tanpa** `listOrder`, `tableSectionId`/`tableName`/`memberId` dkk.

- **Filter status**: cuma order `pending` yang bisa diambil (`WHERE status = ?`, hardcode `'pending'`) — order `hold`/`paid`/`cancel` → data kosong/error.

## Alur Implementasi

- **`view-order`**: dipanggil saat kasir klik meja yang sudah ada order pending/hold, untuk masuk ke mode edit cart (`orderPage.vue`) — hasil `listOrder[]` jadi state awal cart, `ulid` tiap baris dipakai untuk membedakan update vs insert saat `SAVE ORDER.md` dipanggil ulang.
- **`only-view`**: siap pakai di backend dan punya wrapper `OnlyViewOrder()` di frontend (`src/services/Order.js`), **tapi saat ini tidak pernah benar-benar dipanggil** — satu-satunya tempat yang mengimpornya (`paymentPage.vue`) cuma import nama ini lalu tetap memanggil `ViewOrder()` (versi lengkap) di `onMounted()`. Jadi endpoint ini **belum ada consumer aktif** — cantumkan apa adanya untuk migrasi (siap pakai, belum dipakai), jangan dihapus begitu saja karena backend dan wrapper frontend-nya sudah lengkap, kemungkinan disiapkan untuk kebutuhan preview ringan yang belum diimplementasikan di UI.

## Sumber

- `app/Http/Controllers/OrderController.php` — `viewOrder()`, `onlyViewOrder()`.
- `app/Services/OrderServices.php` — `ViewOrder()`, `OnlyViewOrder()`.
- Frontend: `src/services/Order.js` (`ViewOrder`, `OnlyViewOrder`).

## Status

Didokumentasikan 2026-10-12, berdasar pembacaan kode langsung (belum ada verifikasi ulang lewat HTTP request live di sesi ini).

**Tervalidasi live (2026-11-18)** — audit gap: `ViewOrder()`/`OnlyViewOrder()` ternyata belum ikut field2 baru (`totalServiceCharge`/`orderFee`/`deliveryCost` header, `serviceChargeId`/`serviceChargeRate`/`serviceChargeAmount`/`netPrice` item & package) walau kolomnya sudah lama ada & aktif dipakai `SaveOrder()`/`RecalculateOrderTotals()`. Ditambahkan ke query SQL `ViewOrder()` (header + item + package) dan `OnlyViewOrder()` (header). Dites via `tinker` manggil `OrderServices::ViewOrder()`/`OnlyViewOrder()` langsung — data test (`total_service_charge`/`order_fee`/`delivery_cost` di `tr_order`, `service_charge_id`/`rate`/`amount` di `tr_order_detail`/`tr_order_detail_package`) di-set sementara ke nilai non-zero (order asli nilainya `0`/`NULL` semua), hasil panggilan balikin field-field itu **persis** sesuai nilai yang di-set (`totalServiceCharge=1234.00`/`orderFee=2500.00`/`deliveryCost=7500.00` di header `ViewOrder()`; `serviceChargeAmount=333.33`/`11.11` di item/package; `totalServiceCharge=999.00`/`orderFee=100.00`/`deliveryCost=200.00` di `OnlyViewOrder()` pakai order `pending` terpisah). Semua data test direvert ke `0`/`NULL` abis verifikasi. `php -l` bersih.

**`netDpp` DIHAPUS dari response (2026-11-18, lanjutan)** — awalnya `netPrice` ditambahkan SEBAGAI TAMBAHAN (dobel sama `dpp`/`netDpp` lama), tapi dikoreksi: `dpp`/`netDpp` lama cuma alias SQL demi compat yang bikin 3 field (`netPrice`/`dpp`/`netDpp`) nunjuk ke cuma 2 kolom (`net_price`/`dpp`) — membingungkan, apalagi backend (`SaveOrder()`) sendiri sudah full migrasi ke nama `netPrice`/`dpp`. Keputusan: `netDpp` **dihapus total** dari query `ViewOrder()`, `dpp` di response sekarang baca kolom `dpp` APA ADANYA (BUKAN lagi alias kolom `net_price`) — artinya **makna `dpp` di response BERUBAH** dari dulu "sebelum diskon" jadi sekarang "setelah diskon" (ikut makna kolom DB yang benar). Tervalidasi live via `tinker`: panggil `ViewOrder()` setelah perubahan, dikonfirmasi response gak punya key `netDpp` sama sekali (`property_exists()` return `false`), `netPrice`/`dpp` tampil sesuai kolom masing-masing. `php -l` bersih (sempat error syntax sekali — komentar SQL pakai tanda kutip ganda yang menutup string PHP lebih awal, sudah diperbaiki pakai kutip biasa tanpa simbol itu).

**⚠️ PENDING, sengaja BELUM dikerjakan di sesi ini — migrasi `posv1-vue` (FE)**: `orderPage.vue` (`recomputeDppTax()`, `netPrice()`, `hitungTotalFromItemQty()`), `paymentPage.vue`, `MemberPage.vue`, `DayStartEndPage.vue` semuanya masih aktif pakai nama field `dpp`/`netDpp` — **baik baca dari response API maupun hitung ulang sendiri di client** (bukan cuma passthrough). Breaking change backend ini **belum aman dipakai FE existing** sampai semua pemakai itu ikut di-rename (`dpp`→`netPrice`, `netDpp`→`dpp`) secara konsisten — scope kerja terpisah, cukup besar (bukan cuma rename variabel, ada logic kalkulasi real-time yang perlu dicek ulang tiap baris). Jangan deploy backend ini ke environment yang FE-nya belum ikut diupdate.
