# Save Order

```
ANY /api/order/save-order
```

Endpoint **paling krusial** di seluruh POS — 1 endpoint untuk **membuat order baru** DAN **menambah item ke order yang sudah ada** (mode ditentukan dari isi body, bukan path/method berbeda). Termasuk alur apply promo, lihat "Alur Promo" di bawah.

**⚠️ Arsitektur penting (UPDATE 2026-11-18)**: Diskon promo **TETAP dihitung sepenuhnya di FRONTEND** (`orderPage.vue`, `discountAmount`/`discountPercent` dipercaya apa adanya dari body request, TIDAK divalidasi ulang). TAPI **5 field hasil turunan harga per baris item** — `netPrice`, `dpp`, `taxAmount`, `serviceChargeAmount`, `total` — **SEKARANG DIHITUNG ULANG SERVER** (`OrderServices::CalculateLineItem()`), **BUKAN** dipercaya mentah dari FE lagi. **Field HEADER order** — `totalItem`, `subTotal`, `totalDiscount`, `totalTax`, `totalServiceCharge`, `totalBilling` — **JUGA DIHITUNG ULANG SERVER** (`OrderServices::RecalculateOrderTotals()`, dipanggil setelah baris item tersimpan, baca **SEMUA** baris `tr_order_detail`/`tr_order_detail_package` milik order itu — bukan cuma yang di request kali ini, penting buat mode 2a/2b yang nambah ke order existing). **Pengecualian**: `orderFee`/`deliveryCost` (level header) **TETAP dari FE apa adanya** — itu input manual (ongkos kirim/biaya tambahan), bukan turunan dari baris item, jadi tidak ada yang bisa "dihitung ulang" dari situ. FE **BOLEH TETAP MENGIRIM** semua field turunan di atas di request (buat display real-time di UI, gak error kalau dikirim), tapi nilai yang beneran dipakai & tersimpan ke DB **SELALU** hasil hitungan server, FE punya nilainya **sepenuhnya diabaikan**. Lihat "Formula Kalkulasi Harga" di bawah buat rumus lengkap + contoh. Ini **beda arsitektur** dari sudomobile (endpoint `Calculate()` server-side) di satu sisi — sekarang POS juga punya proteksi server serupa buat field-field ini, walau diskon sendiri tetap arsitektur lama (FE-trusted).

## Ada 3 variasi request — 2 mode, mode ke-2 punya 2 sub-mode per baris

| # | Mode | Trigger | Dipakai untuk |
|---|---|---|---|
| 1 | Order baru | `orderNumber: ""` | Order pertama kali dibuat |
| 2a | Tambah item — item BARU | `orderNumber` terisi, baris `listOrder[]` **tanpa** `ulid` | Nambah menu baru ke order pending/hold yang sudah ada |
| 2b | Tambah item — UPDATE item existing | `orderNumber` terisi, baris `listOrder[]` **punya** `ulid` | Apply/ubah promo pada item yang sudah tersimpan (qty bisa ikut berubah karena split promo) |

Satu request `listOrder[]` **boleh mencampur** baris 2a dan 2b sekaligus (sebagian ber-`ulid`, sebagian tidak) — kondisi umum di dunia nyata (nambah item baru sambil promo di item lama ikut berubah).

---

### Mode 1 — Order Baru (`orderNumber: ""`)

**Request:**

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
  "totalDiscount": "15000.00",
  "totalTax": "11193.00",
  "totalServiceCharge": "5590.50",
  "orderFee": "2000.00",
  "deliveryCost": "1000.00",
  "totalBilling": "131713.50",
  "listOrder": [
    {
      "menuPricelistId": 93,
      "menuId": 120,
      "qty": 2,
      "flagInclusiveTax": true,
      "taxId": 100,
      "taxType": "vat",
      "serviceChargeId": 16,
      "promoId": 23,
      "isFreeItemPromo": false,
      "discountPercent": "20.00",
      "price": "20000.00",
      "netPrice": "18018.00",
      "discountAmount": "4000.00",
      "dpp": "14414.40",
      "taxAmount": "1980.00",
      "serviceChargeAmount": "900.90",
      "total": "16000.00",
      "notes": "less ice",
      "menuPackageList": [
        {
          "menuPackageId": 210,
          "menuId": 55,
          "qty": 1,
          "flagInclusiveTax": true,
          "taxId": 100,
          "taxType": "vat",
          "serviceChargeId": 16,
          "promoId": null,
          "discountPercent": "0.00",
          "price": "5000.00",
          "netPrice": "4504.50",
          "discountAmount": "0.00",
          "dpp": "4504.50",
          "taxAmount": "495.00",
          "serviceChargeAmount": "225.23",
          "total": "5000.00",
          "notes": null
        }
      ]
    }
  ]
}
```

**`serviceChargeId`/`serviceChargeRate`/`serviceChargeAmount` (2026-11-18, BARU — lihat update LANJUTAN di bawah)** — kolom baru di `tr_order_detail`/`tr_order_detail_package` (migration `2026_11_18_000000_add_service_charge_columns_to_tr_order_detail.php`). **Status TERKINI (setelah update "taxRate/serviceChargeRate di-resolve server" di "Formula Kalkulasi Harga")**: `serviceChargeId` dipercaya dari FE (jadi dasar lookup), `serviceChargeRate`/`serviceChargeAmount` **TIDAK LAGI** dipercaya dari FE — `serviceChargeRate` di-lookup server dari `mr_tax` by `serviceChargeId` (`OrderServices::ResolveTaxRate()`), `serviceChargeAmount` dihitung server (`CalculateLineItem()`). FE **boleh tetap kirim** `serviceChargeRate`/`serviceChargeAmount` (gak error), tapi nilainya diabaikan total — lihat "Formula Kalkulasi Harga" untuk detail lengkap. `serviceChargeId` didapat dari [`MENU LIST.md`](../MASTER/MENU%20LIST.md)/[`MENU LIST PER VISIT PURPOSE.md`](../MASTER/MENU%20LIST%20PER%20VISIT%20PURPOSE.md) (field level item/sub-item, bukan level visit purpose). `serviceChargeId` opsional/boleh `null` (item tanpa service charge, rate dianggap `0`) — kalau diisi, harus id yang beneran ada di `mr_tax` (gak ketemu → `Exception`, order gagal tersimpan). **Belum ada konsumen** di sisi lain (sync push ke sudocore2, jurnal endday) — scope sesi ini SENGAJA cuma penyimpanan di POS lokal, menyusul terpisah.

**`totalServiceCharge`/`orderFee`/`deliveryCost` (2026-11-18, BARU)** — field LEVEL ORDER (header `tr_order`, beda dari `serviceChargeAmount` di atas yang per-baris item). `totalServiceCharge` → kolom `tr_order.total_service_charge` (**di-rename** dari `service_charge`, migration `2026_11_18_000001_rename_service_charge_to_total_service_charge_on_tr_order.php` — nama lama ambigu, bisa disalahartikan `tax_id` service charge) — **⚠️ walau tetap boleh dikirim FE, nilainya SEKARANG DIABAIKAN & DIHITUNG ULANG SERVER** (`Σ qty × serviceChargeAmount` seluruh baris, lihat "Formula Kalkulasi Harga" di bawah), **BEDA** dari `orderFee`/`deliveryCost` → kolom `tr_order.order_fee`/`delivery_cost` (nama field sama, nama kolom sama, cuma field ini **SEBELUMNYA belum pernah dibaca/ditulis sama sekali** di `SaveOrder()`, selalu default `0.00` — walau `delivery_cost` sendiri sudah dipakai aktif di laporan dayshift, `netsales_dc_total`/"Netsales Delivery Cost", dan diisi dari order mobile lewat `MobileOrderPullServices.php`) yang **TETAP dipercaya apa adanya dari FE** (bukan turunan baris item, input manual — tidak ada yang bisa "dihitung ulang" dari situ). **Urutan field request di atas SENGAJA**: `subTotal → totalDiscount → totalTax → totalServiceCharge → orderFee → deliveryCost → totalBilling` — ngikutin urutan logis kalkulasi (subtotal, dipotong diskon, ditambah pajak, ditambah service charge, ditambah biaya order, ditambah ongkos kirim, jadi total akhir), bukan urutan alfabet/sembarang. **Lihat "Server-side header calculation" di bawah buat field header mana aja yang dihitung ulang vs tetap dari FE.**

**Response sukses:**

```json
{ "code": 0, "data": { "orderNumber": "NO1<branch_code>20261118140530" } }
```

**Response gagal** (contoh — lihat daftar lengkap kemungkinan error di bawah):

```json
{ "code": 100, "message": "This table already has a pending order!" }
```

Catatan: baris `listOrder[]` **tidak punya** `ulid` sama sekali di mode ini — field itu belum ada karena baris belum pernah tersimpan.

- `orderNumber: ""` — **wajib string kosong eksplisit**, bukan `null`/tidak dikirim, untuk trigger mode "order baru".
- `menuId` — walau namanya "menu id", nilainya sebenarnya `mr_item_conv.id` (ID hasil konversi satuan), **bukan** `mr_item.id` langsung — konsisten dengan nilai `itemId` yang dibalikin [`../MASTER/MENU LIST.md`](../MASTER/MENU%20LIST.md).
- `listOrder[].promoId`/`menuPackageList[].promoId` — **promo di-apply PER ITEM/SUB-ITEM**, bukan per order (beda dari pola `use_promo_ids` level-order yang dipakai sudomobile). Lihat "Alur Promo" di bawah.
- **⚠️ `netPrice`/`dpp` (2026-11-18, DI-RENAME dari `dpp`/`netDpp`)** — nama lama MEMBINGUNGKAN: field `dpp` lama sebenarnya net price SEBELUM diskon (`flagInclusiveTax ? price/(1+taxRate/100) : price`), sementara `netDpp` (dpp SETELAH dipotong diskon) yang SEBENARNYA jadi basis pengenaan pajak (`taxAmount = netDpp × taxRate/100`) — istilah akuntansi "DPP" semestinya merujuk ke nilai KEDUA, bukan yang pertama. Ditukar namanya (kolom DB `tr_order_detail`/`tr_order_detail_package` ikut di-rename, migration `2026_11_18_000002_rename_dpp_net_dpp_columns.php`): `netPrice` (field request, dulu `dpp`) = nilai sebelum diskon, `dpp` (field request, dulu `netDpp`) = nilai setelah diskon = basis pajak yang benar. **Response [`VIEW ORDER.md`](VIEW%20ORDER.md) TIDAK ikut berubah nama** (tetap `dpp`/`netDpp`) — cuma alias SQL internal di `ViewOrder()` yang diselaraskan ke kolom baru, shape response sengaja dipertahankan biar gak breaking consumer existing. **Scope rename SENGAJA dibatasi ke POS lokal (migration + `SaveOrder()`/`ViewOrder()` doang)** — konsumen lain kolom ini (`KioskController.php`, `DayShiftServices.php`, `MobileOrderPullServices.php`, `PrintServices.php`) **PENDING, belum disentuh** — menyusul terpisah, dampaknya lebih besar (termasuk payload dari sudomobile yang kemungkinan masih pakai makna lama).
- `listOrder[].isFreeItemPromo` — cuma disimpan di level item1 (`tr_order_detail.is_free_item_promo`). `menuPackageList[].isFreeItemPromo` **TIDAK ADA kolomnya** di `tr_order_detail_package` — walau dikirim, tidak tersimpan/dibaca sama sekali untuk sub-item package.
- Backend **mengecek stok** (`mr_item.stok_qty`/`flag_soldout`, lewat JOIN `mr_pricelist_detail`→`mr_item_conv`→`mr_item`) — ini **satu-satunya** validasi server yang nyata di titik ini. Stok tidak cukup → `{ "code": 100, "message": "Stok yang tersedia untuk {nama item} hanya {n}" }`, seluruh transaksi di-rollback.
- `dayshift` harus aktif (`tr_dayshift.dayout_time IS NULL`) — tidak ada → `"dayshift is not active, cannot create order!"`.
- **Guard `notification_end` (2026-11-17, baru)** — baca `mr_branch_setting.flag_notification_end`/`notification_end` (field lama, sudah ada sejak dulu, tapi sebelumnya belum ada logic konsumsi apa pun di POS — cuma CRUD/sync passthrough). Kalau `flag_notification_end = true` **dan** jam sekarang (`now()->format('H:i:s')`) sudah **`>=`** `notification_end` (format `"HH:MM:SS"`) → order baru ditolak `"cannot create new order, you must end day first!"`. `flag_notification_end = false` (default) → guard ini **tidak aktif sama sekali**, tidak ada pengecekan jam. **Tidak ada filter tanggal/hari** — murni perbandingan jam. **Cuma berlaku di mode order baru** (`orderNumber: ""`) — mode "tambah item ke order existing" (blok kode terpisah) **tidak kena guard ini**. **Kondisi tambahan tanggal (2026-11-24, permintaan owner):** guard ini sekarang membandingkan juga **tanggal komputer dengan tanggal start day** (`dayin_time` dayshift aktif). Tanggal komputer **sama** dengan start day → **aman** (order baru boleh, berapa pun jamnya). Tanggal komputer **sudah lebih dari** start day **dan** jam sekarang `>=` `notification_end` → **ditolak**. Efeknya: cabang yang buka lewat tengah malam bisa memakai flag ini (seluruh hari start day aman, baru ditolak setelah hari berganti dan jam lewat `notification_end`); sebaliknya untuk cabang tutup biasa (mis. 22:00), order baru **tidak lagi ditolak** pada hari start day itu sendiri setelah `notification_end`, baru ditolak mulai hari berikutnya setelah jam `notification_end`. `dayin_time` kosong (tidak mungkin untuk dayshift aktif) → aturan lama (cek jam saja). Berlaku **hanya untuk order baru** (`orderNumber` kosong), tambah item ke order existing dan pembayaran tidak punya guard ini.
- **Nomor antrian custom, `order_queue` (2026-11-19, baru)** — baca `mr_branch_setting.flag_queue`/`queue_start_from`/`queue_reset_after_each` (field lama, sejak 2026-11-03, sebelumnya belum ada logic konsumsi apa pun di POS — sama pola `notification_end`). `order_queue` (kolom internal, BUKAN field request/response) **selalu di-scope PER DAYSHIFT** (`tr_dayshift.ulid`) — tiap dayshift baru mulai dari awal lagi, gak nyambung lintas dayshift/tanggal kalender.
  - **`flag_queue = false` (default)** — behavior LAMA, tidak berubah: order pertama di dayshift dapat `order_queue = 1`, naik `+1` tiap order baru, tidak ada batas atas.
  - **`flag_queue = true`** — order pertama di dayshift dapat `order_queue = queue_start_from` (BUKAN selalu `1`). Order berikutnya naik `+1` seperti biasa, **TAPI** kalau nomor berikutnya akan **melebihi** `queue_reset_after_each` (strict lebih besar, bukan `>=`) → `order_queue` **reset** balik ke `queue_start_from`. `queue_reset_after_each = 0` berarti reset dimatikan (naik terus tanpa batas, walau `flag_queue = true` dan `queue_start_from` bukan `1`).
  - Contoh (`queue_start_from = 100`, `queue_reset_after_each = 102`): order ke-1→100, ke-2→101, ke-3→102, ke-4→**100 (reset)**, ke-5→101, dst.
  - **Tervalidasi live (2026-11-19)**: 4 order dibuat berurutan via `OrderServices::SaveOrder()` langsung ke dayshift kosong, hasil persis sesuai contoh di atas (100, 101, 102, lalu reset ke 100). Data test & `mr_branch_setting` dikembalikan ke kondisi semula setelah verifikasi.
- `tableSectionId` harus valid — tidak ketemu → `"invalid table section!"`.
- **Guard meja sudah ada order aktif (2026-10-27)** — kalau `tableId` diisi dan sudah ada order lain (order number manapun) di meja itu dengan status `pending`/`hold` → ditolak `"This table already has a pending order!"`. **Cuma berlaku di mode order baru** (`orderNumber: ""`) — mode "tambah item ke order existing" memang menambah ke order pending yang sama di meja itu, jadi tidak kena guard ini.
- **`memberId`** — kalau diisi (bukan `null`), disimpan ke `tr_order.member_id` dan dipakai sebagai basis 2 barrier promo server-side di bawah (`flag_required_member`, cek tier `flag_all_tiers`). `null`/tidak diisi → order dianggap guest, promo yang `flag_required_member = true` otomatis ditolak (lihat "Barrier server-side" di bawah).

### Mode 2a — Tambah Item: item BARU (`orderNumber` terisi, baris TANPA `ulid`)

Insert baris baru ke order yang sudah ada — sama seperti baris di mode 1, cuma `orderNumber` sudah terisi.

**Request:**

```json
{
  "orderNumber": "NO1<branch_code>20261118140530",
  "terminalId": 1,
  "orderName": "Budi",
  "orderPax": 2,
  "totalItem": 3,
  "subTotal": "131930.00",
  "totalDiscount": "15000.00",
  "totalTax": "13193.00",
  "totalServiceCharge": "6596.50",
  "orderFee": "2000.00",
  "deliveryCost": "1000.00",
  "totalBilling": "152719.50",
  "memberId": null,
  "listOrder": [
    {
      "menuPricelistId": 95,
      "menuId": 130,
      "qty": 1,
      "flagInclusiveTax": true,
      "taxId": 100,
      "taxType": "vat",
      "serviceChargeId": 16,
      "promoId": null,
      "isFreeItemPromo": false,
      "discountPercent": "0.00",
      "price": "20000.00",
      "netPrice": "18018.00",
      "discountAmount": "0.00",
      "dpp": "18018.00",
      "taxAmount": "1980.00",
      "serviceChargeAmount": "900.90",
      "total": "20000.00",
      "notes": null,
      "menuPackageList": []
    }
  ]
}
```

**Response sukses:**

```json
{ "code": 0, "data": { "orderNumber": "NO1<branch_code>20261118140530" } }
```

Catatan (2026-11-18, DIUPDATE): `orderName`, `orderPax`, `orderFee`, `deliveryCost`, `memberId` **wajib dikirim ulang** di cabang ini (dipakai buat `UPDATE tr_order`). `totalItem`, `subTotal`, `totalDiscount`, `totalTax`, `totalServiceCharge`, `totalBilling` **BOLEH TETAP dikirim** (gak error) tapi **TIDAK DIPAKAI SAMA SEKALI** — field itu sepenuhnya dihitung ulang server dari `RecalculateOrderTotals()` setelah baris item tersimpan (baca SEMUA baris order, termasuk yang sudah ada sebelumnya, bukan cuma baris di request ini). `terminalId`, `customerPhoneNumber`, `orderSource`, `tableSectionId`, `tableId`, `visitPurposeId` **tidak dipakai lagi** di cabang ini (sudah fix dari saat order dibuat pertama kali) — boleh dikirim atau diomit, tidak mempengaruhi apa pun.

### Mode 2b — Tambah Item: UPDATE item EXISTING (`orderNumber` terisi, baris PUNYA `ulid`)

Dipakai saat apply/ubah promo pada item yang sudah tersimpan — `ulid` didapat dari [`VIEW ORDER.md`](VIEW%20ORDER.md) (cart di-reload dulu sebelum submit).

**Request:**

```json
{
  "orderNumber": "NO1<branch_code>20261118140530",
  "orderName": "Budi",
  "orderPax": 2,
  "totalItem": 2,
  "subTotal": "111930.00",
  "totalDiscount": "21000.00",
  "totalTax": "9163.00",
  "totalServiceCharge": "4551.08",
  "orderFee": "2000.00",
  "deliveryCost": "1000.00",
  "totalBilling": "114714.08",
  "memberId": 5501,
  "listOrder": [
    {
      "ulid": "01J8X9QZ3K7NFYV2WRMEH6C4XP",
      "menuPricelistId": 93,
      "menuId": 120,
      "qty": 3,
      "promoId": 23,
      "isFreeItemPromo": false,
      "discountPercent": "20.00",
      "netPrice": "27027.00",
      "discountAmount": "6000.00",
      "dpp": "21621.60",
      "taxAmount": "2970.00",
      "serviceChargeAmount": "1351.35"
    }
  ]
}
```

**Response sukses:** sama persis mode 2a — `{ "code": 0, "data": { "orderNumber": "..." } }`.

**Penting (beda dari mode 1 & 2a)**: baris ber-`ulid` **hanya 9 field ini** yang benar-benar dibaca & di-update ke `tr_order_detail`: `ulid`, `qty`, `promoId`, `isFreeItemPromo`, `discountPercent`, `discountAmount`, `netPrice`, `dpp`, `taxAmount`, `serviceChargeAmount` (2026-11-18, BARU — ikut di-update karena basisnya sama dengan `taxAmount`, `dpp` (basis pajak), yang berubah kalau qty/diskon berubah lewat split promo). Field lain seperti `menuPricelistId`, `menuId`, `price`, `taxId`, `taxType`, `taxRate`, `serviceChargeId`, `serviceChargeRate`, `notes`, `menuPackageList` **boleh ikut dikirim** (tidak error) tapi **diabaikan sepenuhnya** oleh server — nilai lama di DB tidak berubah, jadi field itu di contoh di atas cuma dikirim sebagai referensi FE, bukan yang dipakai backend. `serviceChargeId`/`serviceChargeRate` sengaja TIDAK ikut di-update di sini — nilainya per visit purpose, tidak berubah karena promo/diskon.

---

Hanya order dengan status `pending`/`hold` yang bisa ditambah item (mode 2a & 2b).

**Response gagal** (sama bentuknya di ketiga mode) — field `message` (bukan `data`):

```json
{ "code": 100, "message": "This promo is for members only" }
```

Lihat daftar lengkap kemungkinan error per mode di catatan masing-masing di atas, plus "Alur Promo" untuk error terkait promo (`"This promo is for members only"`).

## Formula Kalkulasi Harga — SERVER-SIDE, berjalan nyata (2026-11-18)

Ada 2 lapis kalkulasi server, dalam urutan eksekusi: **header** dulu (total keseluruhan order) dibangun dari hasil **item** (tiap baris `listOrder[]`/`menuPackageList[]`) — tapi secara DEPENDENSI, item dihitung duluan (per baris), baru header menjumlahkan semua baris itu. Section di bawah ini disusun header dulu (gambaran besar), baru item/item package (detail per baris).

### Server-side header calculation

Setelah baris `listOrder[]`/`menuPackageList[]` selesai disimpan (insert di mode 1/2a, update di mode 2b), server memanggil `OrderServices::RecalculateOrderTotals($order_number)` — fungsi ini `SELECT` **SEMUA** baris `tr_order_detail`/`tr_order_detail_package` milik order itu (bukan cuma baris yang ada di request SaveOrder() kali ini — penting di mode 2a/2b karena order bisa sudah punya baris lain sebelumnya), jalankan `CalculateLineItem()` (lihat "Server-side item & item package calculation" di bawah) per baris, lalu jumlahkan:

| Field header | Formula | Sumber |
|---|---|---|
| `totalItem` | `Σ qty` | dihitung ulang server |
| `subTotal` | `Σ (qty × netPrice)` | dihitung ulang server |
| `totalDiscount` | `Σ (qty × discountAmount)` | dihitung ulang server |
| `totalTax` | `Σ (qty × taxAmount)` | dihitung ulang server |
| `totalServiceCharge` | `Σ (qty × serviceChargeAmount)` | dihitung ulang server |
| `orderFee` | — | **TETAP dari FE**, dibaca ulang dari `tr_order` |
| `deliveryCost` | — | **TETAP dari FE**, dibaca ulang dari `tr_order` |
| `grandTotal` | `Σ (qty × total) + orderFee + deliveryCost` | dihitung ulang server, **BARU (2026-11-20)** — nilai SEBELUM dibulatkan (ini yang SEBELUMNYA namanya `totalBilling`) |
| `roundingAmount` | lihat "Formula Rounding" di bawah | dihitung ulang server, **BARU (2026-11-20)** |
| `totalBilling` | `grandTotal + roundingAmount` | dihitung ulang server |

`orderFee`/`deliveryCost` itu **input manual level header** (ongkos kirim/biaya tambahan yang gak nempel ke baris item manapun), bukan turunan dari baris item — makanya satu-satunya 2 field yang **TETAP dipercaya dari FE apa adanya**, ditambahkan langsung ke `grandTotal` di akhir rumus (bukan lagi langsung ke `totalBilling` — lihat perubahan rounding di bawah). Field header lain (`totalItem`/`subTotal`/`totalDiscount`/`totalTax`/`totalServiceCharge`/`grandTotal`/`roundingAmount`/`totalBilling`) yang dikirim FE di request **SEPENUHNYA DIABAIKAN**.

#### Formula Rounding (2026-11-20, BARU)

Setelah `grandTotal` final (sudah termasuk semua item + `totalServiceCharge` + `orderFee` + `deliveryCost`), `roundingAmount` dihitung dari setting `mr_branch_setting` milik branch order ini:

```
sisa = fmod(grandTotal, rounding_nearst_value)
roundingAmount = (flag_rounding == true DAN rounding_nearst_value > 0 DAN sisa != 0) ? rounding_nearst_value - sisa : 0
totalBilling = grandTotal + roundingAmount
```

`flag_rounding`/`rounding_nearst_value` sudah lama ada sebagai setting (migration `2026_11_03_000000_add_columns_to_mr_branch_setting.php`), baru dipakai transaksi sejak perubahan ini. Mode pembulatan SELALU "ke kelipatan terdekat DI ATAS" — kolom `rounding_mode` ('up'/'down') tidak pernah dipakai dan sudah **DIHAPUS** (2026-11-24, ERP migration 354 + POS migration `2026_11_24_000000`) — hanya 1 mode. Kalau `grandTotal` sudah pas kelipatan `rounding_nearst_value` (sisa bagi `0`), `roundingAmount` TETAP `0` — tidak ikut nambah 1 kelipatan penuh.

**Tervalidasi live (2 skenario via tinker)**: (1) mode 1, kirim `subTotal`/`totalTax`/`totalServiceCharge`/`totalDiscount`/`totalBilling`/`totalItem` sengaja `999999` (angka ngawur), `orderFee="2000.00"`/`deliveryCost="1000.00"`, 1 baris item (`qty=2`, `price=20000` inclusive, `taxRate=10%`, `scRate=5%`, tanpa diskon) → hasil tersimpan `totalItem=2`, `subTotal=34782.60`, `totalTax=3478.26`, `totalServiceCharge=1739.14`, `totalBilling=43000.00` (= `40000 + 2000 + 1000`) — semua cocok hitungan manual, nilai `999999` sepenuhnya diabaikan; (2) mode 2a (tambah item ke order hasil skenario 1), kirim `orderFee="2500.00"`/`deliveryCost="1500.00"` baru + 1 item tambahan (`qty=1`, `price=10000` exclusive, sama rate) → hasil `totalItem=3` (2+1), `subTotal=44782.60` (34782.60+10000), `totalTax=4478.26` (3478.26+1000), `totalServiceCharge=2239.14` (1739.14+500), `totalBilling=55500.00` (= `51500 + 2500 + 1500`) — dikonfirmasi server **beneran menjumlahkan SEMUA baris order** (item lama + item baru), bukan cuma yang di request kali ini, dan `orderFee`/`deliveryCost` baru berhasil meng-override yang lama. `php -l` bersih. Data test dibersihkan setelah tiap skenario.

**Scope**: perubahan ini juga memperbaiki `RecalculateOrderTotals()` (fungsi lama, sebelumnya cuma dipakai `SaveMoveItem()`) — ditambahkan `serviceChargeRate` ke formula (sebelumnya cuma `taxRate` yang dilepas dari harga inclusive), disesuaikan ke kolom `net_price`/`dpp` hasil rename, dan diperbaiki 1 inkonsistensi lama: `total_discount` **sebelumnya TIDAK dikali `qty`** (beda dari `sub_total`/`total_tax`/`total_billing` yang semuanya dikali `qty`) — sekarang konsisten, semua dikali `qty`. Perbaikan ini otomatis ikut berlaku juga di `SaveMoveItem()` (satu fungsi, dua pemanggil).

### Server-side item & item package calculation

**Ini formula yang BENERAN DIJALANKAN SERVER** saat `SaveOrder()` dipanggil (`OrderServices::CalculateLineItem()`), bukan lagi cuma referensi konsep — lihat "Status" di paling bawah dokumen buat hasil verifikasi live. Dijalankan di **setiap baris** `listOrder[]`/`menuPackageList[]`, di mode 1, 2a, **dan** 2b — hasilnya yang dijumlahkan jadi header di atas.

Komponen pungutan per item (simulasi dengan `tax_rate=10%`, `service_charge_rate=5%`, total gabungan `15%`):

| Komponen | Rate |
|---|---|
| Tax | 10% |
| Service Charge | 5% |
| **Total gabungan** | **15%** |

Urutan hitung per baris item (urutan standar PPN — pajak+service charge dilepas dulu dari harga inclusive, BARU diskon dipotong, BARU pajak+service charge final dihitung ulang dari basis setelah diskon):

| Langkah | Formula |
|---|---|
| 1. `netPrice` | `flagInclusiveTax ? price / (1 + (taxRate + serviceChargeRate) / 100) : price` |
| 2. `dpp` | `netPrice - discountAmount` |
| 3. `taxAmount` | `dpp × taxRate / 100` |
| 4. `serviceChargeAmount` | `dpp × serviceChargeRate / 100` |
| 5. `total` | `dpp + taxAmount + serviceChargeAmount` |

**Input yang TETAP dipercaya dari FE** (tidak ikut dihitung ulang): `price`, `flagInclusiveTax`, `discountAmount` (diskon sendiri **sengaja TIDAK** di-re-derive dari `discountPercent`/promo rules — itu kompleks, alur promo FE tetap arsitektur lama, lihat "Alur Promo" di bawah).

**`taxRate`/`serviceChargeRate` TIDAK LAGI dipercaya dari FE (2026-11-18, BARU)** — `OrderServices::ResolveTaxRate(?int $taxId): float` di-panggil SEBELUM `CalculateLineItem()`, query `mr_tax` by `taxId`/`serviceChargeId`, hasilnya itu yang dipakai (bukan `taxRate`/`serviceChargeRate` yang dikirim FE — boleh tetap dikirim, diabaikan). `taxId`/`serviceChargeId` kosong/`null` → rate `0` (valid, bukan error — item tanpa pajak/service charge). `taxId`/`serviceChargeId` **terisi tapi tidak ketemu** di `mr_tax` → `Exception` jelas (`"tax/service charge dengan id {id} tidak ditemukan"`), order gagal tersimpan sama sekali. Berlaku di mode 1 & 2a (item & package) — mode 2b di bawah sudah otomatis ikut benar karena `tax_rate`/`service_charge_rate` yang di-`SELECT` dari baris existing itu sendiri hasil resolve ini (dari saat baris itu pertama kali disimpan). **Contoh JSON di dokumen ini sudah dibersihkan** — `taxRate`/`serviceChargeRate` sengaja gak disertakan lagi di `listOrder[]`/`menuPackageList[]` mode 1 & 2a, biar gak menyesatkan field mana yang masih dibaca (boleh tetap dikirim FE kalau belum sempat dibersihkan, gak error, cuma gak ada gunanya).

**Mode 2b (update item existing via `ulid`)** — request body di mode ini **tidak bawa** `price`/`taxRate`/`serviceChargeRate`/`flagInclusiveTax` (cuma 9 field "ringan" yang dikirim). 4 input itu di-**`SELECT` ulang dari baris `tr_order_detail` yang sudah ada** (nilainya tidak berubah dari saat baris pertama kali dibuat), digabung dengan `discountAmount` **baru** dari request ini, baru dihitung ulang — field `total` **ikut di-update** juga di sini (sebelumnya tidak, sekarang 10 field total yang di-update: `qty`, `promoId`, `isFreeItemPromo`, `discountPercent`, `discountAmount`, `netPrice`, `dpp`, `taxAmount`, `serviceChargeAmount`, `total`). `serviceChargeId`/`serviceChargeRate` sendiri **tetap tidak** ikut di-update (nilainya per visit purpose, tidak berubah karena diskon).

**Contoh simulasi**:

| Contoh | Item | Price | Inclusive? | `netPrice` | Discount | `dpp` | `tax` | `serviceCharge` | `total` |
|---|---|---:|---|---:|---:|---:|---:|---:|---:|
| Tanpa diskon | Americano | 20000.00 | Ya | 17391.30 | 0.00 | 17391.30 | 1739.13 | 869.57 | 20000.00 |
| Dengan diskon | Americano | 20000.00 | Ya | 17391.30 | 347.83 | 17043.48 | 1704.35 | 852.17 | 19600.00 |
| Exclusive tax | Cappuccino | 25000.00 | Tidak | 25000.00 | 500.00 | 24500.00 | 2450.00 | 1225.00 | 28175.00 |

**Cara baca**:
- **Baris 1 (tanpa diskon)** — `price` sudah termasuk tax+service charge (`flagInclusiveTax=true`) → `netPrice = 20000 / (1 + 0.15) = 17391.30`. Tanpa diskon, `dpp = netPrice` apa adanya. `tax = 17391.30 × 10% = 1739.13`, `serviceCharge = 17391.30 × 5% = 869.57`, `total = 17391.30 + 1739.13 + 869.57 = 20000.00` (balik ke harga awal, konsisten — inclusive tanpa diskon memang harus balik ke `price`).
- **Baris 2 (dengan diskon)** — `netPrice` sama (`17391.30`, belum kena diskon), tapi `discountAmount=347.83` dipotong dari situ dulu → `dpp = 17391.30 - 347.83 = 17043.48` (basis pajak yang SUDAH kena diskon — bukan `netPrice` mentah). `tax`/`serviceCharge`/`total` semua dihitung ULANG dari `dpp` yang baru ini, bukan dari `netPrice`.
- **Baris 3 (exclusive tax)** — `flagInclusiveTax=false` → `netPrice = price` apa adanya (`25000.00`, gak ada yang dilepas). `dpp = 25000 - 500 = 24500.00`. `tax = 24500 × 10% = 2450.00`, `serviceCharge = 24500 × 5% = 1225.00`, `total = 24500 + 2450 + 1225 = 28175.00`.

**Koreksi dari sumber gambar aslinya**: versi awal tabel ini (dibawa user, spreadsheet) punya typo di baris Cappuccino — kolom `tax`/`service charge`-nya kebalik-tukar rate (`tax` kelihatan pakai rate `5%` dan `service charge` pakai rate `15%` gabungan, bukan `5%` murni) sehingga hasilnya beda (`tax=1225`, `service charge=3675`, `total=29400`). Tabel di atas sudah dikoreksi pakai rate yang konsisten dengan definisi komponen (`tax=10%`, `service charge=5%` terpisah, bukan digabung).

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

`SUM(qty × netPrice)` dari baris yang cocok harus `>= promo.min_buy_amount`, kalau tidak → toast error "Promo requirements not met". **`min_buy_amount` sendiri tidak divalidasi ulang di server** — kalau FE di-bypass, server tetap menyimpan apa adanya. (`netPrice` yang dipakai di formula ini SEKARANG dihitung ulang server — lihat "Formula Kalkulasi Harga" di bawah — tapi itu bukan validasi `min_buy_amount`, cuma basis angkanya yang lebih kuat dari manipulasi.)

### 4. Hitung diskon per tipe promo

- **`rupiah`** — potongan tetap per qty (`type_rupiah_amount × qty`), **di-cap ke DPP baris itu** (diskon tidak boleh melebihi DPP, supaya `netDpp` tidak negatif).
- **`percent`** — `discountableTotal × (type_percent_rate / 100)`, dengan `type_percent_limit_amount` sebagai cap maksimum kalau diisi.
- **`freeitem`** — baris baru dibuat (`buildFreeItemLine()`), bukan diskon pada baris existing.
- **`bill`** (2026-11-26) — Bill Discount, nominal di `type_bill_amount`. **Belum ada logika hitungnya di SaveOrder**; saat dicek di kode (`OrderServices.php`) tidak ada percabangan per tipe promo sama sekali, jadi diskon dikirim sebagai angka jadi dari FE. Jangan mengandalkan daftar tipe di atas sebagai perilaku server tanpa mengecek kode.

`promo.flag_include_package` — kalau `true`, sub-item package (`menuPackageList[]`) di baris itu **ikut** didiskon dengan rasio yang sama; kalau `false`, cuma item utama.

### 5. Split-remainder (promo dengan `apply_limit_per_item`)

Kalau `qty` baris lebih besar dari `apply_limit_per_item` sisa promo itu, baris **di-split jadi 2**: bagian yang kena diskon (qty dikurangi) tetap di baris asli, sisanya (`qty` asli dikurangi `discountableQty`) jadi **baris baru terpisah** tanpa promo (`_splitRemainderId` menandai baris itu "pecahan" dari promo tertentu — field internal FE, **tidak** dikirim ke backend).

### 6. Barrier server-side (satu-satunya validasi promo yang BENERAN di-enforce server)

Dicek **sekali** di awal `SaveOrder()` (bukan per-baris), kumpulkan semua `promoId` unik yang kepakai (dari `listOrder[].promoId` + `menuPackageList[].promoId`):

- **`flag_required_member`** — kalau order **tanpa** `memberId` tapi memakai promo yang `mr_promo.flag_required_member = true` → `{ "code": 100, "message": "This promo is for members only" }`, **seluruh order ditolak** (bukan cuma baris itu).
- **`flag_all_tiers` + tier member** — kalau ada `memberId` dan promo yang dipakai `flag_all_tiers = false`, tier member (`mr_member.tier_level`, default `1` kalau kosong) **harus** match salah satu baris `mr_promo_tier` promo itu, kalau tidak → error sama persis ("This promo is for members only", pesan generik tidak dibedakan dari barrier required-member).

#### Cek promo aktif + belum berakhir (2026-11-26, BARU)

Tahap tambahan di `SaveOrder()` (`OrderServices::ValidatePromoActive()`), dijalankan **setelah** dua barrier di atas dan **sebelum** cek stok (jadi penolakan terjadi sebelum ada tulis ke DB). Berlaku di mode 1 (order baru) dan mode 2a (tambah item baru). Satu query batch ke `mr_promo` untuk semua `promoId` unik (`listOrder[].promoId` + `menuPackageList[].promoId`). Urutan cek per promo:

1. **Promo tidak ditemukan** di `mr_promo` lokal → `{ "code": 100, "message": "Promo (id 42) was not found, please sync promotion data" }`
2. **`is_active = false`** → `"Promo \"<nama promo>\" is not active anymore"`
3. **`period_end` sudah lewat** → `"Promo \"<nama promo>\" has expired on 2026-11-25"`

- `period_end` adalah kolom DATE: promo yang berakhir **hari ini masih valid** sampai hari berganti. `period_end` `NULL` = tanpa batas akhir (sama seperti filter `GetPromoList`). `period_start` **tidak** dicek di tahap ini.
- **Hanya item BARU yang dicek** (baris tanpa `ulid`). Baris lama di mode 2b / baris lama yang ikut terkirim di mode 2a (punya `ulid`) dilewati — promo itu sah saat item dipesan, jadi kasir yang menambah pesanan di meja yang ordernya memakai promo yang baru berakhir (mis. lewat tengah malam) tidak terblokir. Package item lama ikut dilewati lewat item induknya.
- **Mode 2b (update item existing) tidak dicek sama sekali**: memang hanya berisi baris ber-`ulid`.
- **`SaveMoveItem()` (pindah item/meja) dikecualikan** — `SaveOrder($data, false)` (parameter `$validatePromoActive = false`), karena item yang dipindah sudah ada sebelumnya dan tidak boleh gagal gara-gara promonya keburu nonaktif/berakhir.
- Seluruh order ditolak (bukan hanya baris itu) dan transaksi di-rollback, pola sama dua barrier di atas. Pesan memakai bahasa Inggris, konsisten dengan `"This promo is for members only"`.
- **Tidak mencakup** `KioskController::editExistingOrder` dan `MobileOrderPullServices` (dua-duanya sengaja tidak lewat `SaveOrder()`); `KioskController::SaveOrder` untuk order baru ikut tercakup karena memanggil `OrderServices::SaveOrder()`.

**Yang TIDAK divalidasi ulang server**: target promo (`promo_for`/`targetIds`), `min_buy_amount`, `apply_limit_per_day`/`apply_limit_per_item`, nilai diskon itu sendiri. Kalau frontend baru salah hitung atau sengaja dimanipulasi, server akan menyimpan nilai yang salah tanpa mendeteksinya — **kecuali** 2 barrier di atas.

## Print Checker — kapan di-trigger (2026-11-04)

3 fungsi print checker (`PrintServices::PrintTableChecker2()`, `PrintMainChecker2()`, `PrintPriparationStation()`) dipanggil bareng di 2 titik di `SaveOrder()` (order baru DAN tambah item ke order existing) — tapi **sekarang kondisional**, berdasar kolom baru `mr_table_section.print_checker_on` (`'save_order'` atau `'save_payment'`, default `'save_order'`, ditarik dari ERP `master_table_section.print_checker_on` lewat sync, lihat `DOKUMENTASI API/backend/MASTER/MASTER TABLE SECTION.md` repo sudocore2):

```php
if ($order_source !== 'kiosk' && $tablesection->print_checker_on === 'save_order') {
  // PrintTableChecker2() + PrintMainChecker2() + PrintPriparationStation()
}
```

- **`print_checker_on === 'save_order'`** (default) — print checker jalan **di sini** (`SaveOrder()`), SAMA seperti perilaku lama sebelum field ini ada.
- **`print_checker_on === 'save_payment'`** — print checker **TIDAK** jalan di `SaveOrder()`, dipindah ke [`../PAYMENT/SAVE EDIT VIEW PAYMENT.md`](../PAYMENT/SAVE%20EDIT%20VIEW%20PAYMENT.md) (`PaymentServices::SavePayment()`) — baru nge-print pas pembayaran sukses, bukan pas order disimpan.
- **Kiosk** — independen dari `print_checker_on`, **selalu** ditunda ke `SavePayment()` (perilaku lama, sudah ada sebelum field ini — nunggu kepastian bayar dulu karena order Kiosk belum tentu jadi dibayar).

4 kombinasi hasil (`order_source` × `print_checker_on`):

| order_source | print_checker_on | Print di `SaveOrder()`? | Print di `SavePayment()`? |
|---|---|---|---|
| pos (non-kiosk) | `save_order` | ✅ | ❌ |
| pos (non-kiosk) | `save_payment` | ❌ | ✅ |
| kiosk | `save_order` | ❌ | ✅ |
| kiosk | `save_payment` | ❌ | ✅ |

**Tervalidasi (2026-11-04)**: logic kondisi disimulasikan langsung pakai `TableSectionModel` real (table section id 27) dengan tiap kombinasi `print_checker_on`/`order_source` — hasil cocok 100% dengan tabel di atas. Belum dites lewat HTTP `SaveOrder()` penuh (butuh payload request kompleks + autentikasi, risiko salah bentuk payload), tapi kondisi boolean-nya sendiri sudah diverifikasi benar dengan data table section asli.

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

**Guard `notification_end` (2026-11-17)**: tervalidasi live via `OrderServices::SaveOrder()` dipanggil langsung (tinker), 3 skenario — (1) `flag_notification_end=false` → guard tidak aktif, lolos ke guard berikutnya ("invalid table section!"); (2) `flag_notification_end=true`, `notification_end="10:00:00"` (sudah lewat, jam saat test 15:11) → ditolak `"cannot create new order, you must end day first!"`; (3) `flag_notification_end=true`, `notification_end="23:59:00"` (belum sampai) → lolos ke guard berikutnya. Setting dikembalikan ke nilai semula (`flag_notification_end=false`, `notification_end="00:00:00"`) setelah testing. `php -l` bersih.

**`serviceChargeId`/`serviceChargeRate`/`serviceChargeAmount` (2026-11-18)**: kolom baru ditambahkan via migration `2026_11_18_000000_add_service_charge_columns_to_tr_order_detail.php` (pola persis `tax_id`/`tax_rate`/`tax_amount`, nullable + default 0 buat rate/amount) ke `tr_order_detail` & `tr_order_detail_package`. `OrderServices::SaveOrder()` diupdate baca 3 field ini dari body request (mode 1 & 2a, `?? null`/`?? 0` buat backward-compat) + `serviceChargeAmount` ikut di-update di mode 2b (sama alasan `taxAmount`). Tervalidasi live via `OrderServices::SaveOrder()` dipanggil langsung (tinker) mode order baru — kirim `serviceChargeId=16`, `serviceChargeRate="5.00"`, `serviceChargeAmount="900.90"`, hasil query `tr_order_detail` setelahnya cocok persis dengan yang dikirim. Data test dibersihkan setelah verifikasi. **Scope SENGAJA cuma POS lokal** — sync push kolom ini ke sudocore2 dan konsumsi di jurnal endday **BELUM disentuh**, menyusul terpisah.

**`totalServiceCharge`/`orderFee` (2026-11-18)**: `tr_order.service_charge` **di-rename** jadi `total_service_charge` (migration `2026_11_18_000001_rename_service_charge_to_total_service_charge_on_tr_order.php`) — rename murni, tipe/default tidak berubah. Konsumen lama kolom ini ikut diupdate: `DayShiftServices.php` (6 titik `$orderitem->service_charge` → `$orderitem->total_service_charge`, dipakai akumulasi laporan dayshift dari query `tr_order tro.*`) dan `MobileOrderPullServices.php` (key insert `'service_charge'` → `'total_service_charge'` saat pull order dari mobile/member app). `OrderServices::SaveOrder()` diupdate baca `totalServiceCharge`/`orderFee` dari body request (mode 1, 2a, 2b — keduanya masuk `$order`/`$order_update`, `?? 0` buat backward-compat) — `order_fee` kolomnya sudah ada lama tapi **belum pernah dibaca/ditulis sama sekali** sebelum perubahan ini. Urutan field request diubah jadi `subTotal → totalDiscount → totalTax → totalServiceCharge → orderFee → totalBilling` di ketiga mode, ngikutin urutan logis kalkulasi. Tervalidasi live via tinker mode order baru — kirim `totalServiceCharge="900.90"`, `orderFee="2000.00"`, hasil query `tr_order` setelahnya cocok persis. `php -l` bersih di `OrderServices.php`/`DayShiftServices.php`/`MobileOrderPullServices.php`. Data test dibersihkan. **Scope SENGAJA cuma POS lokal** — sync push ke sudocore2 **BELUM disentuh**, menyusul terpisah.

**`platform_fee` dihapus (2026-11-18)**: kolom `tr_order.platform_fee` **DIHAPUS TOTAL** (migration `2026_11_18_000003_drop_platform_fee_from_tr_order.php`) — keputusan user, kolom ini gak pernah diisi `SaveOrder()` dan dianggap gak relevan lagi. Sebelum dihapus, kolom ini dipakai aktif di 6 blok laporan dayshift (`DayShiftServices.php` — variabel `$netsales_pf_total`, baris output "Netsales Platform Fee", ikut dijumlahkan ke rumus `gross_sales`) dan ditulis dari `MobileOrderPullServices.php` (order mobile/member app) — **semua konsumen itu ikut dihapus bareng** (24 titik total di `DayShiftServices.php`: 6× deklarasi, 6× akumulasi, 6× keluar dari rumus `gross_sales`, 6× baris output laporan). Efek nyata: baris "Netsales Platform Fee" hilang dari laporan, `gross_sales` gak lagi menjumlahkan komponen ini. Tervalidasi live via tinker — `GetReportCurrentShift()` jalan tanpa error, dikonfirmasi baris "Netsales Platform Fee" sudah tidak ada di hasil. `php -l` bersih di `DayShiftServices.php`/`MobileOrderPullServices.php`. Dokumentasi lain yang masih dump field ini (`START END DAY.md`, `ORDER LIST.md`, `VIEW SALES.md`) ikut diperbaiki (field dihapus + sekalian dikoreksi `service_charge`→`total_service_charge` yang ternyata masih tertinggal di sana).

**`deliveryCost` (2026-11-18, BARU)**: kolom `tr_order.delivery_cost` sudah ADA lama (gak perlu migration), tapi sama persis kasus `order_fee` sebelumnya — **belum pernah dibaca/ditulis sama sekali** di `SaveOrder()`, walau sudah dipakai aktif di laporan dayshift (`$netsales_dc_total`, "Netsales Delivery Cost") dan diisi dari order mobile (`MobileOrderPullServices.php`). `OrderServices::SaveOrder()` diupdate baca `deliveryCost` dari body request (mode 1, 2a, 2b — `?? 0` buat backward-compat), ditaruh **setelah** `orderFee` di urutan field (`... → orderFee → deliveryCost → totalBilling`). Tervalidasi live via tinker — kirim `deliveryCost="5000.00"`, hasil query `tr_order` setelahnya cocok persis. `php -l` bersih. Data test dibersihkan. **Scope SENGAJA cuma POS lokal** — sync push ke sudocore2 **BELUM disentuh**, menyusul terpisah.

**`netPrice`/`dpp` rename (2026-11-18)**: kolom `tr_order_detail`/`tr_order_detail_package` `dpp`/`net_dpp` **ditukar namanya** jadi `net_price`/`dpp` (migration `2026_11_18_000002_rename_dpp_net_dpp_columns.php`, pakai kolom perantara buat swap aman) — alasan lengkap di catatan field `netPrice`/`dpp` di atas. `OrderServices::SaveOrder()` diupdate baca `netPrice`/`dpp` dari body request (semua mode — 1, 2a untuk item1+package, 2b untuk item1). `OrderServices::ViewOrder()` ikut diperbaiki (**minimal fix, WAJIB** — query SQL eksplisit sebut nama kolom lama, kalau tidak diubah bakal error `Unknown column` sejak kolom di-rename) — cuma alias SQL yang diselaraskan ke kolom baru (`trod.net_price as dpp`, `trod.dpp as netDpp`), **response JSON key TETAP `dpp`/`netDpp`** (tidak breaking consumer existing). Tervalidasi live via tinker: `SaveOrder()` kirim `netPrice="18018.00"`/`dpp="15018.00"` (dengan `discountAmount="3000.00"`) → kolom DB `net_price=18018.00`/`dpp=15018.00` (= `net_price - discount_amount`, benar) → `ViewOrder()` dipanggil setelahnya, tidak crash, hasil `dpp=18018.00`/`netDpp=15018.00` (response shape lama, nilai dari kolom baru). `php -l` bersih. Data test dibersihkan. **Scope SENGAJA dibatasi ke migration + `SaveOrder()`/`ViewOrder()` doang** — `KioskController.php` (`recomputeKioskUnit()`, `mapKioskOrderPayload()`, insert langsung di `editExistingOrder()`), `DayShiftServices.php` (akumulasi `dpp_total` buat laporan — otomatis JADI BENAR secara makna setelah rename, TIDAK perlu diubah kodenya), `MobileOrderPullServices.php` (payload dari sudomobile/`mb_order`, kemungkinan masih pakai makna lama — butuh keputusan pemetaan terpisah), dan `PrintServices.php` (kalkulasi harga tampilan struk, 2 fungsi, pakai makna "sebelum diskon" — perlu ganti ke kolom `net_price`) **PENDING, belum disentuh sama sekali** — menyusul terpisah, karena dampaknya lebih besar dan melibatkan kontrak dengan app Kiosk & sudomobile (repo eksternal).

**Server-side price calculation — level ITEM (2026-11-18, PERUBAHAN ARSITEKTUR)**: fungsi baru `OrderServices::CalculateLineItem($price, $taxRate, $serviceChargeRate, $flagInclusiveTax, $discountAmount)` — hitung `netPrice`/`dpp`/`taxAmount`/`serviceChargeAmount`/`total` SERVER-SIDE (formula lengkap di "Formula Kalkulasi Harga" di atas), dipanggil di **semua titik** `tr_order_detail`/`tr_order_detail_package` kena insert/update: mode 1 (item1+package), mode 2a (item1+package), mode 2b (item1, baca 4 input dari baris existing via `SELECT` dulu). 5 field itu yang dikirim FE **DIABAIKAN TOTAL** — kalau tetap dikirim (buat display di UI), nilainya **tidak dipakai sama sekali**, yang tersimpan ke DB selalu hasil `CalculateLineItem()`. **Tervalidasi live (2 skenario via tinker)**: (1) mode 1, kirim `taxAmount`/`serviceChargeAmount`/`netPrice`/`dpp`/`total` sengaja `999999.00` (angka ngawur) → hasil tersimpan `netPrice=17391.30`/`dpp=17391.30`/`taxAmount=1739.13`/`serviceChargeAmount=869.57`/`total=20000.00` (cocok persis simulasi tanpa-diskon di atas, nilai `999999` sepenuhnya diabaikan); (2) mode 2b, baris existing hasil test (1) di-update dengan `discountAmount="347.83"` baru + field lain sengaja `999999.00` lagi → hasil `netPrice=17391.30`/`dpp=17043.47`/`taxAmount=1704.35`/`serviceChargeAmount=852.17`/`total=19600.00` (cocok persis simulasi dengan-diskon, dikonfirmasi server BENERAN baca ulang `price_pos`/`tax_rate`/`service_charge_rate`/`flag_inclusive_tax` dari baris existing, bukan dari request). `php -l` bersih. Data test dibersihkan setelah tiap skenario.

**Server-side price calculation — level HEADER (2026-11-18, LANJUTAN)**: `OrderServices::RecalculateOrderTotals()` (fungsi lama, sebelumnya cuma dipakai `SaveMoveItem()`) **diupdate total & sekarang JUGA dipanggil dari `SaveOrder()`** (mode 1 setelah insert item, mode 2a/2b setelah insert/update item, sebelum `DB::commit()`) — menghitung `totalItem`/`subTotal`/`totalDiscount`/`totalTax`/`totalServiceCharge`/`totalBilling` dari `Σ(qty × hasil CalculateLineItem())` **SEMUA** baris `tr_order_detail`/`tr_order_detail_package` milik order (bukan cuma baris di request kali ini). Perubahan di fungsi itu: (a) formula ikut `serviceChargeRate` (dulu cuma `taxRate`), (b) baca kolom `net_price`/`service_charge_rate` hasil rename/field baru, (c) ditambahkan `total_service_charge` ke hasil, (d) ditambahkan baca-ulang `order_fee`/`delivery_cost` dari `tr_order` (TETAP dari FE, cuma dijumlahkan ke `total_billing` di akhir, bukan dihitung ulang), (e) **bugfix**: `total_discount` sebelumnya **TIDAK** dikali `qty` (beda dari 3 field lain yang semua dikali `qty`) — sekarang konsisten. Detail formula di "Server-side header calculation" di atas. **Tervalidasi live (2 skenario via tinker, lanjutan dari 2 skenario level item di atas)**: (1) mode 1, header dikirim FE sengaja `999999` semua + `orderFee="2000.00"`/`deliveryCost="1000.00"`, 1 item (`qty=2`, inclusive) → hasil `totalItem=2`/`subTotal=34782.60`/`totalTax=3478.26`/`totalServiceCharge=1739.14`/`totalBilling=43000.00` (cocok hitungan manual, `999999` diabaikan); (2) mode 2a tambah 1 item exclusive ke order yang sama + `orderFee`/`deliveryCost` baru (`2500`/`1500`) → hasil akumulasi SEMUA baris (`totalItem=3`, `subTotal=44782.60`, dst, `totalBilling=55500.00`), dikonfirmasi server baca ulang baris lama+baru, bukan cuma delta request. `php -l` bersih. Data test dibersihkan. Perbaikan `RecalculateOrderTotals()` ini otomatis ikut berlaku di `SaveMoveItem()` juga (satu fungsi, dua pemanggil) — belum ada test live khusus `SaveMoveItem()` di sesi ini, tapi logic-nya sama persis dipakai kedua caller.

**`taxRate`/`serviceChargeRate` di-resolve server-side dari `mr_tax` (2026-11-18, LANJUTAN)**: fungsi baru `OrderServices::ResolveTaxRate(?int $taxId): float` — query `mr_tax` by `id`, `null`/kosong → rate `0`, id terisi tapi tidak ketemu → `Exception`. Dipanggil di mode 1 & 2a (item & package, 4 titik) **sebelum** `CalculateLineItem()` — `taxRate`/`serviceChargeRate` yang dikirim FE **tidak lagi dipakai sama sekali** (diganti hasil lookup), kolom DB (`tax_rate`/`service_charge_rate`) juga ikut disimpan dari hasil lookup ini, bukan dari request. Mode 2b tidak perlu disentuh — rate-nya dibaca dari baris existing (`SELECT`) yang sejak awal sudah hasil lookup ini. `KioskController::recomputeKioskUnit()` ikut diupdate pakai fungsi yang sama (`OrderServices::ResolveTaxRate()` dibuat `public`), jadi perilaku Kiosk konsisten dengan POS. **Tervalidasi live (2 skenario via curl ke Kiosk `save-order`, port 8098)**: (1) kirim `tax_rate="999.00"`/`service_charge_rate="999.00"` (sentinel sengaja salah) dengan `tax_id=10` (rate asli 10%)/`service_charge_id=16` (rate asli diset 5% buat test) → hasil tersimpan `tax_rate=10.00`/`service_charge_rate=5.00` (bukan `999`), `tax_amount`/`service_charge_amount`/`dpp` semua cocok hitungan manual pakai rate asli; (2) kirim `tax_id=999999` (tidak ada di `mr_tax`) → `{"code":100,"message":"tax/service charge dengan id 999999 tidak ditemukan"}`, order gagal tersimpan. `php -l` bersih. Data test & master data yang diubah sementara (`mr_tax.rate`, `flag_kiosk`, `mr_terminal.table_section_id`) dikembalikan ke semula setelah verifikasi.

Diupdate 2026-11-24: guard `notification_end` ditambah kondisi tanggal vs start day (lihat bullet Guard `notification_end` di atas). Tervalidasi dengan memanggil `OrderServices::SaveOrder()` langsung dengan jam komputer disimulasikan (`Carbon::setTestNow`): 10 skenario (tanggal sama, tanggal lebih + jam sebelum/sesudah `notification_end`, 2 hari lewat, cabang lintas tengah malam `02:00`, cabang tutup biasa `22:00`, flag mati) semuanya sesuai; setelan cabang dikembalikan, tidak ada order yang tertulis.

Diupdate 2026-11-26: ditambah tahap cek promo aktif + belum berakhir (lihat sub-bagian "Cek promo aktif + belum berakhir" di bagian Barrier server-side). Tervalidasi dengan memanggil `OrderServices::ValidatePromoActive()` (reflection, tinker) pada 5 promo sementara di `mr_promo` lokal: nonaktif ditolak, `period_end` kemarin ditolak, `period_end` hari ini lolos, aktif lolos, tanpa tanggal akhir lolos, id tidak ada ditolak; item ber-`ulid` (nonaktif/kadaluarsa) dilewati; promo di package item baru ikut dicek, di package item lama dilewati. Promo sementara dihapus setelah tes.

Diupdate 2026-11-26 (perbaikan 3 bug di cabang tambah pesanan, `orderNumber` terisi): (1) **Transaksi dimulai lebih awal** — `DB::beginTransaction()` dipindah ke sebelum loop item, sehingga update item existing (mode 2b, baris ber-`ulid`) dan insert/stok item baru satu transaksi. Sebelumnya update item existing ter-commit duluan; kalau cek stok item baru di request yang sama gagal, update item lama tetap permanen dan total header tidak ter-recalculate. (2) **`ulid` divalidasi milik order ini** — update dan select baris item sekarang memakai `AND order_number = ?`; `ulid` yang tidak dikenal, milik order lain, atau sudah di-cancel ditolak dengan `"Order item <ulid> was not found in order <order_number> or has been cancelled"` (sebelumnya baris order lain ikut berubah, atau error mentah property-on-null). (3) **Guard order tidak ada/sudah ditutup** — order yang bukan `pending`/`hold` ditolak dengan `"Order <order_number> was not found or is already closed, cannot add items"` (sebelumnya error mentah `Attempt to read property \"total_batch\" on null`); `listOrder` `null`/tidak dikirim sekarang ditangani (`(array)` cast), sama seperti cabang order baru. Tervalidasi lewat pemanggilan langsung `SaveOrder()` (tinker): order tidak ada ditolak, order `paid` ditolak, `listOrder` null lolos, `ulid` order lain ditolak dan qty baris order lain tidak berubah, update item lama + item baru yang stoknya kurang → ditolak DAN qty item lama tetap (ter-rollback), update item lama saja → tersimpan. **Catatan**: tes ini sempat menulis permanen ke 1 order pending dan 1 item di `db_pos` (kolom `order_name` order itu tidak bisa dipulihkan ke nilai awalnya); lihat catatan sesi. **Belum diperbaiki**: race stok (SELECT tanpa `FOR UPDATE`), `order_number` beresolusi 1 detik, `order_queue` dibaca sebelum transaksi.

Diupdate 2026-11-26 (lanjutan): `catch` di `SaveOrder()` dan `SaveMoveItem()` sekarang hanya `DB::rollBack()` kalau fungsi itu sendiri yang membuka transaksi (`DB::transactionLevel()` dibandingkan dengan level saat fungsi dipanggil). Sebelumnya rollBack tanpa syarat, sehingga error validasi yang dilempar SEBELUM `beginTransaction()` ikut membatalkan transaksi milik pemanggil. Tervalidasi di dalam transaksi pembungkus: error "order tidak ada" (sebelum begin) tidak membatalkan pembungkus (level tetap 1); error stok (setelah begin) hanya membatalkan transaksi `SaveOrder()` sendiri; setelah pembungkus di-rollback, penanda tulis ikut hilang dan tidak ada residu di DB.

Diupdate 2026-11-26 (3 perbaikan terakhir, ketiganya berdasarkan pembacaan kode `OrderServices.php`, bukan dokumentasi): (1) **Race stok** — pengecekan+pengurangan stok dipindah ke `ReserveStock()` yang mengunci baris `mr_item` dengan `SELECT ... FOR UPDATE` di dalam transaksi, menggabungkan qty per ITEM (bukan per `pricelist_detail`) dan mengunci terurut id item supaya tidak deadlock. Aturan bisnis tidak berubah (`stok_qty = 0` = tidak dilacak, item `flag_soldout` dilewati, pesan error stok sama). Tambahan: `pricelist_detail` yang tidak ketemu menghasilkan `"Menu pricelist <id> was not found"`, dan `flag_soldout` memakai `<= 0` (bukan `=== 0`). Dipakai di cabang order baru dan tambah pesanan. (2) **`order_number` unik** — `GenerateOrderNumber()` mengecek `tr_order` dan menggeser waktu +1 detik kalau nomor sudah dipakai (maks 120x); format `NO<terminal><branch_code><YmdHis>` dan panjangnya tidak berubah, hanya bisa beberapa detik lebih maju dari `order_in` kalau ada bentrokan. (3) **Lock pembuatan order baru** — `SaveOrder()` cabang order baru mengambil MySQL named lock `pos_new_order` (`GET_LOCK`, tunggu maks 10 detik, gagal -> `"System is busy creating another order, please try again"`) SEBELUM nomor order dan `order_queue` dibuat, dan melepasnya segera setelah `DB::commit()` (plus `finally` sebagai pengaman). Akibatnya pembuatan order BARU antar terminal berurutan sehingga `order_queue` dan `order_number` tidak bisa kembar; tambah pesanan tidak terkena lock ini. Tervalidasi lewat tinker di dalam transaksi pembungkus yang di-rollback: stok 5 -> 3 diambil -> sisa 2, 3 lagi ditolak (`hanya 2`), 2 lagi habis (`stok 0`, `flag_soldout 1`), item soldout dilewati; nomor di detik yang sama dengan order existing bergeser ke `...103811`; lock tidak bisa diambil koneksi lain saat dipegang dan bisa diambil setelah dilepas; lock bebas lagi setelah `SaveOrder()` order baru gagal validasi; koneksi kedua yang menahan `FOR UPDATE` pada baris item membuat `ReserveStock()` menunggu lalu kena `Lock wait timeout (1205)`; data kembali ke nilai awal setelah rollback. **Belum diuji**: alur penuh order baru sampai commit (butuh dayshift aktif) dan dua request benar-benar bersamaan dari HTTP.

## Promo type `bill` eksklusif (2026-11-26)

Promo dengan `mr_promo.type = 'bill'` (Bill Discount) **tidak boleh digabung** dengan promo lain di order yang sama. `ValidatePromoBillExclusive()` mengumpulkan semua `promoId` di `listOrder` (baris item + `menuPackageList[]`, termasuk baris free-item), mengambil id uniknya, dan kalau ada **lebih dari 1 id unik** dan salah satunya bertipe `bill`, request ditolak (`code: 100`):

```
Bill discount promo "<nama promo>" cannot be combined with other promos
```

- Boleh: banyak baris memakai promo bill yang **sama**; baris tanpa `promoId` bersama promo bill.
- Ditolak: bill + promo apa pun yang beda id (percent/rupiah/freeitem, atau promo bill kedua).
- Dicek di jalur order baru DAN tambah pesanan, terhadap seluruh payload (baris lama ber-ulid yang dikirim ulang ikut dihitung), juga di `SaveMoveItem`. Dicek sebelum tulis DB apa pun, jadi tidak ada data tersimpan. Yang dibandingkan hanya `promoId` yang ada di payload; baris order yang tidak ikut dikirim di request tambah pesanan tidak dilihat.
- Belum ada logika yang menghitung nominal diskon bill di server (lihat bagian tipe promo di atas).

**Tervalidasi** (tinker, promo sementara di transaksi yang di-rollback, jumlah promo tetap): bill saja → lolos; bill + item tanpa promo → lolos; bill + percent → ditolak; bill di package + percent di item → ditolak; percent + percent → lolos; tanpa promo → lolos. Belum diuji lewat endpoint SaveOrder penuh.
