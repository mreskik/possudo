# Save Order

```
ANY /api/order/save-order
```

Endpoint **paling krusial** di seluruh POS — 1 endpoint untuk **membuat order baru** DAN **menambah item ke order yang sudah ada** (mode ditentukan dari isi body, bukan path/method berbeda). Termasuk alur apply promo, lihat "Alur Promo" di bawah.

**⚠️ Arsitektur penting**: **SEMUA kalkulasi harga, pajak, dan diskon promo dilakukan di FRONTEND** (`orderPage.vue`). Backend **TIDAK melakukan re-kalkulasi apa pun** — `price`, `taxAmount`, `discountAmount`, `dpp`, `netDpp`, `total` disimpan apa adanya dari body request, tanpa validasi ulang terhadap harga master/rate pajak asli. Ini **beda arsitektur total** dari sudomobile (yang punya endpoint `Calculate()` server-side sebagai sumber kebenaran) — kalau frontend baru dibangun ulang, **logic kalkulasi harus di-port persis**, bukan diasumsikan server akan "menjaga" angkanya.

## Request — mode "order baru" (`orderNumber: ""`)

```json
{
  "orderNumber": "",
  "terminalId": 1,
  "orderName": "Budi",
  "customerPhoneNumber": "081234567890",
  "orderSource": "pos",
  "tableSectionId": 1,
  "tableId": 10,
  "memberId": null,
  "visitPurposeId": 7,
  "orderPax": 2,
  "totalItem": 2,
  "subTotal": "111930.00",
  "totalTax": "11193.00",
  "totalBilling": "123123.00",
  "totalDiscount": "15000.00",
  "listOrder": [
    {
      "menuPricelistId": 93,
      "menuId": 120,
      "qty": 2,
      "flagInclusiveTax": true,
      "price": "20000.00",
      "taxId": 100,
      "taxType": "vat",
      "taxRate": "11.00",
      "taxAmount": "1980.00",
      "promoId": 23,
      "isFreeItemPromo": false,
      "discountPercent": "20.00",
      "discountAmount": "4000.00",
      "dpp": "18018.00",
      "netDpp": "14414.40",
      "total": "16000.00",
      "notes": "less ice",
      "menuPackageList": [
        {
          "menuPackageId": 210,
          "menuId": 55,
          "qty": 1,
          "flagInclusiveTax": true,
          "price": "5000.00",
          "taxId": 100,
          "taxType": "vat",
          "taxRate": "11.00",
          "taxAmount": "495.00",
          "promoId": null,
          "discountPercent": "0.00",
          "discountAmount": "0.00",
          "dpp": "4504.50",
          "netDpp": "4504.50",
          "total": "5000.00",
          "notes": null
        }
      ]
    }
  ]
}
```

- `orderNumber: ""` — **wajib string kosong eksplisit**, bukan `null`/tidak dikirim, untuk trigger mode "order baru".
- `menuId` — walau namanya "menu id", nilainya sebenarnya `mr_item_conv.id` (ID hasil konversi satuan), **bukan** `mr_item.id` langsung — konsisten dengan nilai `itemId` yang dibalikin [`../MASTER/MENU LIST.md`](../MASTER/MENU%20LIST.md).
- `listOrder[].promoId`/`menuPackageList[].promoId` — **promo di-apply PER ITEM/SUB-ITEM**, bukan per order (beda dari pola `use_promo_ids` level-order yang dipakai sudomobile). Lihat "Alur Promo" di bawah.
- Backend **mengecek stok** (`mr_item.stok_qty`/`flag_soldout`, lewat JOIN `mr_pricelist_detail`→`mr_item_conv`→`mr_item`) — ini **satu-satunya** validasi server yang nyata di titik ini. Stok tidak cukup → `{ "code": 100, "message": "Stok yang tersedia untuk {nama item} hanya {n}" }`, seluruh transaksi di-rollback.
- `dayshift` harus aktif (`tr_dayshift.dayout_time IS NULL`) — tidak ada → `"dayshift is not active, cannot create order!"`.
- `tableSectionId` harus valid — tidak ketemu → `"invalid table section!"`.

## Request — mode "tambah item" (`orderNumber` terisi)

Body sama, `orderNumber` diisi order yang sudah ada. **2 sub-mode per baris `listOrder[]`**:
- **Item baru** (tidak punya `ulid`) — insert baris baru, sama seperti mode order baru.
- **Item existing** (punya `ulid`, match baris `tr_order_detail` yang sudah ada) — **UPDATE** `qty`/`promoId`/`discountAmount`/`taxAmount`/dll pada baris itu (dipakai saat apply/ubah promo pada item yang sudah tersimpan — "split promo bisa mengubah qty", lihat Alur Promo).

Hanya order dengan status `pending`/`hold` yang bisa ditambah item.

## Response

```json
{ "code": 0, "data": { "orderNumber": "NOSBE2026101209001234" } }
```

Gagal — field `message` (bukan `data`), lihat daftar kemungkinan error di atas + `"This promo is for members only"` (barrier promo, lihat Alur Promo).

## Alur Promo — KRUSIAL, baca sebelum migrasi FE

**Promo di-apply dan di-kalkulasi SEPENUHNYA di frontend** (`applyPromo()`, `orderPage.vue`), server cuma menyimpan hasil akhirnya. Memahami logic ini **wajib** untuk migrasi, karena tidak ada endpoint "Calculate" server-side yang bisa dijadikan acuan.

### 1. Ambil daftar promo eligible

`GET /master/promo-list/{visit_purpose_id}` (lihat [`../MASTER/PROMO LIST.md`](../MASTER/PROMO%20LIST.md)) — dipanggil saat dialog promo dibuka, **tanpa** `member_type_id` di titik ini (member app-POS belum tentu dipilih duluan).

### 2. Cocokkan promo ke baris cart (`matchesPromoTarget`)

```
promo.promo_for === 'category'    → promo.targetIds.includes(line.categoryId)
promo.promo_for === 'subcategory' → promo.targetIds.includes(line.subCategoryId)
promo.promo_for === 'item'        → promo.targetIds.includes(line.itemIdReal)
```

Tidak ada baris yang cocok → toast error "Promo not applicable", promo dibatalkan.

### 3. Validasi `min_buy_amount` (client-side)

`SUM(qty × netPrice)` dari baris yang cocok harus `>= promo.min_buy_amount`, kalau tidak → toast error "Promo requirements not met". **Tidak ada validasi ulang di server** — kalau FE di-bypass, server tetap menyimpan apa adanya.

### 4. Hitung diskon per tipe promo

- **`rupiah`** — potongan tetap per qty (`type_rupiah_amount × qty`), **di-cap ke DPP baris itu** (diskon tidak boleh melebihi DPP, supaya `netDpp` tidak negatif).
- **`percent`** — `discountableTotal × (type_percent_rate / 100)`, dengan `type_percent_limit_amount` sebagai cap maksimum kalau diisi.
- **`freeitem`** — baris baru dibuat (`buildFreeItemLine()`), bukan diskon pada baris existing.

`promo.flag_include_package` — kalau `true`, sub-item package (`menuPackageList[]`) di baris itu **ikut** didiskon dengan rasio yang sama; kalau `false`, cuma item utama.

### 5. Split-remainder (promo dengan `apply_limit_per_item`)

Kalau `qty` baris lebih besar dari `apply_limit_per_item` sisa promo itu, baris **di-split jadi 2**: bagian yang kena diskon (qty dikurangi) tetap di baris asli, sisanya (`qty` asli dikurangi `discountableQty`) jadi **baris baru terpisah** tanpa promo (`_splitRemainderId` menandai baris itu "pecahan" dari promo tertentu — field internal FE, **tidak** dikirim ke backend).

### 6. Barrier server-side (satu-satunya validasi promo yang BENERAN di-enforce server)

Dicek **sekali** di awal `SaveOrder()` (bukan per-baris), kumpulkan semua `promoId` unik yang kepakai (dari `listOrder[].promoId` + `menuPackageList[].promoId`):

- **`flag_required_member`** — kalau order **tanpa** `memberId` tapi memakai promo yang `mr_promo.flag_required_member = true` → `{ "code": 100, "message": "This promo is for members only" }`, **seluruh order ditolak** (bukan cuma baris itu).
- **`flag_all_tiers` + tier member** — kalau ada `memberId` dan promo yang dipakai `flag_all_tiers = false`, tier member (`mr_member.tier_level`, default `1` kalau kosong) **harus** match salah satu baris `mr_promo_tier` promo itu, kalau tidak → error sama persis ("This promo is for members only", pesan generik tidak dibedakan dari barrier required-member).

**Yang TIDAK divalidasi ulang server**: target promo (`promo_for`/`targetIds`), `min_buy_amount`, `apply_limit_per_day`/`apply_limit_per_item`, nilai diskon itu sendiri. Kalau frontend baru salah hitung atau sengaja dimanipulasi, server akan menyimpan nilai yang salah tanpa mendeteksinya — **kecuali** 2 barrier di atas.

## Alur Implementasi (non-promo)

1. Kasir pilih meja kosong (lihat [`LIST TABLE BY SECTION.md`](LIST%20TABLE%20BY%20SECTION.md)) atau meja yang sudah ada order pending.
2. Tambah item dari [`../MASTER/MENU LIST.md`](../MASTER/MENU%20LIST.md) ke cart lokal (state Vue, belum ke server).
3. (Opsional) buka dialog promo, pilih promo yang eligible — kalkulasi diskon terjadi sepenuhnya di FE (lihat Alur Promo).
4. Kasir submit → `SaveOrder(data)` (`Order.js`) → `POST /order/save-order` dengan **seluruh snapshot cart** (bukan cuma delta perubahan).
5. Sukses → `orderNumber` dibalikin, dipakai untuk render struk/lanjut ke Payment.
6. Menambah item ke order pending yang sudah ada → cart di-reload dulu via [`VIEW ORDER.md`](VIEW%20ORDER.md) (dapat `ulid` tiap baris existing), item baru ditambahkan ke array yang sama, dikirim ulang dengan `orderNumber` terisi.

## Sumber

- `app/Http/Controllers/OrderController.php` — `saveOrder()`.
- `app/Services/OrderServices.php` — `SaveOrder()`, `GenerateOrderNumber()`, `ValidatePromoTierBarrier()` (private).
- Frontend: `src/services/Order.js` (`SaveOrder`), `src/modules/dashboard/order/orderPage.vue` (`applyPromo()`, `matchesPromoTarget()`, `clearSinglePromo()`, `clearPromo()`, `buildFreeItemLine()`, `hitungTotalFromItemQty()`).

## Status

Didokumentasikan 2026-10-12, berdasar pembacaan kode langsung (belum ada verifikasi ulang lewat HTTP request live di sesi ini). Alur promo di-trace lengkap dari frontend (`applyPromo()` dkk) ke backend (barrier `flag_required_member`/tier) — ini bagian paling kompleks di seluruh POS, disarankan baca kode aslinya langsung kalau ada bagian yang kurang jelas dari ringkasan ini.
