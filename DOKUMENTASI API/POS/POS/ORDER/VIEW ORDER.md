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
        "promoId": 23,
        "promoName": "Diskon 20%",
        "isFreeItemPromo": false,
        "discountPercent": "20.00",
        "discountAmount": "4000.00",
        "dpp": "18018.00",
        "netDpp": "14414.40",
        "total": "16000.00",
        "cancelAt": null,
        "cancelNotes": null,
        "itemIdReal": 131,
        "categoryId": 39,
        "subCategoryId": 12,
        "menuPackageList": [
          { "ulid": "01hx...", "menuPackageId": 210, "menuId": 55, "menuName": "NASI PUTIH", "qty": 1, "...": "..." }
        ]
      }
    ]
  }
}
```

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
