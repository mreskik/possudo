# List, Detail, Report, Pending Order

## Dayshift List

```
GET /api/dayshift/dayshift-list
```

Riwayat **semua** dayshift (lintas hari, tidak dibatasi), diurut `ulid DESC` (terbaru dulu).

Response:
```json
{
  "code": 0,
  "data": [
    {
      "ulid": "POSSBE20261012080000",
      "branch_id": 51,
      "dayin_time": "2026-10-12T08:00:00+07:00",
      "dayin_total": "500000.00",
      "dayin_user_name": "Kasir 1",
      "dayout_time": "2026-10-12T22:00:00+07:00",
      "dayout_total": "480000.00",
      "dayout_user_name": "Kasir 2"
    }
  ]
}
```

- `dayin_user_name`/`dayout_user_name` — resolve via 2x LEFT JOIN `mr_user` (alias beda) ke `dayin_user_id`/`dayout_user_id` — `dayout_user_name` tetap `null` selama dayshift masih berjalan (belum di-End Day).

## Detail List

```
GET /api/dayshift/detail-list/{dayshift_ulid}
```

**PINDAH dari [`PRINT.md`](PRINT.md) (2026-11-23)** — endpoint ini bukan print, cocoknya digabung bareng endpoint data log shift lain di sini (`dayshift-list`), bukan nyampur di file yang isinya murni print.

Daftar baris `tr_dayshift_detail` (log pergantian shift) untuk 1 dayshift — query langsung (`DayShiftDetailModel::where('dayshift_ulid', ...)->get()`, TIDAK lewat `DayShiftServices.php`).

Response:
```json
{ "code": 0, "data": [ { "shift_number": 1, "shift_time": "...", "shift_user_id": 5 } ] }
```

## Report (v1, LEGACY — tidak dipakai FE lagi)

```
GET /api/dayshift/report/{dayshift_ulid}
```

Laporan ringkasan 1 dayshift — **struktur array numeric-index**. **Sudah digantikan [`Report V3`](#report-v3-2026-11-13-baru)** (V2 juga sudah deprecated, lihat [`Report V2`](#report-v2-deprecated-usang-dan-kurang-lengkap-pakai-report-v3-saja)), route dan kode-nya tetap ada (tidak dihapus) tapi `DayStartEndPage.vue` tidak memanggilnya lagi. Tidak perlu di-port untuk migrasi kecuali ada kebutuhan spesifik yang belum diketahui.

## Report V2 (DEPRECATED, usang dan kurang lengkap, pakai Report V3 saja)

> **⛔ DEPRECATED — USANG DAN KURANG LENGKAP. PAKAI [`Report V3`](#report-v3-2026-11-13-baru) SAJA (2026-11-24).** **Usang:** `sales_summary` V2 masih membaca `sales_recapitulation` lewat **index posisi** yang bergeser 1 dari urutan array sebenarnya, sehingga `total_bills`, `pb1`, `vat`, dan `gross_sales` kemungkinan berisi angka field lain. **Kurang lengkap:** tidak punya `bill_count`, `order_fee`, `delivery_cost`, `rounding`, `other_cost`, `other_cost_count`, dan rumus/scope baru ([Sales Summary: scope & rumus](#sales-summary-scope--rumus-2026-11-24)) tidak diterapkan di sini. Route dan kode V2 **tidak dihapus** karena FE (`DayStartEndPage.vue`) masih memanggilnya **hanya** untuk `sales_by_menu` dan `sales_by_table` yang belum ada di V3 — summary lainnya di FE sudah memakai V3. Isi section di bawah dipertahankan sebagai referensi struktur saja.

```
GET /api/dayshift/report-v2/{dayshift_ulid}
```

Laporan **shift berjalan** (bukan seluruh dayshift: dari End Shift terakhir, atau `dayin_time` kalau belum pernah End Shift, sampai `dayout_time`/sekarang — lihat [Sales Summary: scope & rumus](#sales-summary-scope--rumus-2026-11-24)) — **struktur key-value**, dulu dirancang 1:1 dengan section struk `PrintServices::PrintEndDay()`. **Sejak 2026-11-24 kesamaan itu TIDAK lagi berlaku untuk `sales_summary`**: struk dan V3 sudah memakai rumus/scope baru, sedangkan `sales_summary` V2 masih memakai pembacaan index lama (belum disesuaikan, lihat catatan PERHATIAN di section Report V3). Section lain (`sales_type_summary` dst) tidak berubah.

Response (struktur ringkas — isi lengkap sangat panjang, lihat kode untuk detail per-field):
```json
{
  "code": 0,
  "data": {
    "dayshift": { "ulid": "...", "dayin_total": "500000.00", "dayout_total": "480000.00" },
    "dayin_user_name": "Kasir 1",
    "dayshift_detail": [ { "shift_number": 1, "shift_time": "..." } ],
    "sales_by_menu": [
      {
        "menu_name": "Nasi Goreng",
        "qty": 12,
        "sub_total": 180000,
        "discount_amount": 0,
        "vat_amount": 0,
        "pb1_amount": 18000,
        "grand_total": 198000
      }
    ],
    "sales_by_table": [
      { "table_name": "Table 01", "total_amount": 150000, "total_order": 3 }
    ],
    "sales_summary": {
      "total_bills": 0,
      "average_per_bill": 0,
      "on_hold": 0,
      "pending": 0,
      "sales_subtotal": 0,
      "discount": 0,
      "dpp": 0,
      "service_charge": 0,
      "pb1": 0,
      "vat": 0,
      "total_tax": 0,
      "net_sales": 0,
      "gross_sales": 0
    },
    "sales_type_summary": [ { "label": "Dine In", "amount": 0, "percent": 0 } ],
    "order_source_summary": [
      { "label": "POS", "qty": 0, "amount": 0, "percent": 0 },
      { "label": "QR Order", "qty": 0, "amount": 0, "percent": 0 },
      { "label": "Mobile App", "qty": 0, "amount": 0, "percent": 0 },
      { "label": "Kiosk", "qty": 0, "amount": 0, "percent": 0 }
    ],
    "item_category_summary": [ { "label": "Food", "qty": 15, "amount": 450000, "percent": 60 } ],
    "staff_sales_summary": [ { "label": "Kasir 1", "qty": 8, "amount": 300000, "percent": 40 } ],
    "payment_method_summary": [ { "label": "CASH", "qty": 5, "amount": 250000, "percent": 50 } ],
    "staff_payment_summary": [ { "label": "Kasir 1 - CASH", "qty": 0, "amount": 0, "percent": 0 } ],
    "cash_flow_summary": {
      "starting_cash": 500000,
      "cash_in": 0,
      "cash_out": 0,
      "expected_cash": 500000,
      "actual_cash": 480000
    }
  }
}
```

- **8 section utama**, masing-masing nama section (`sales_summary`, `sales_type_summary`, `order_source_summary`, `item_category_summary`, `staff_sales_summary`, `payment_method_summary`, `staff_payment_summary`, `cash_flow_summary`) **sama persis** dengan section di struk cetak End Day — kalau butuh pemahaman detail arti tiap angka, cocokkan ke `PrintServices::PrintEndDay()`.
- `order_source_summary` — **4 kategori FIXED** (`pos`/`qr`/`mobile`/`kiosk`), **selalu** ditampilkan semua walau salah satu belum punya transaksi (`amount: 0`) — bukan cuma kategori yang ada datanya.
- `staff_payment_summary` (2026-10-20, baru) — breakdown **2 dimensi** staff × payment method (siapa yang bikin order, dibayar pakai metode apa), beda dari `staff_sales_summary` (cuma per staff) dan `payment_method_summary` (cuma per metode). `label` sudah digabung `"Nama Staff - Nama Metode"` dari query SQL (`CONCAT`), bukan 2 field terpisah — keputusan sadar biar bisa langsung reuse `breakdownWithPercent()`/`printBreakdownSection()` tanpa modifikasi. `qty` = jumlah baris pembayaran (`COUNT(tr_order_payment.ulid)`), bukan quantity barang.
- `cash_flow_summary.cash_out` — **selalu `0`** di kode saat ini (field placeholder, belum ada sumber data "uang keluar" yang diimplementasikan).
- Field passthrough (`dayshift`, `dayin_user_name`, `dayshift_detail`, `sales_by_menu`, `sales_by_table`) — dari `GetReportCurrentShiftforTampilan()` (fungsi laporan v1), diikutkan supaya `DayStartEndPage.vue` tidak perlu panggil 2 endpoint terpisah.

## Report V3 (2026-11-13, baru)

```
GET /api/dayshift/report-v3/{dayshift_ulid}
```

**Sama persis [`Report V2`](#report-v2-deprecated-usang-dan-kurang-lengkap-pakai-report-v3-saja)** — **struktur** dan section lain identik (reuse logic yang sama); khusus `sales_summary` isinya sudah berbeda dari V2 sejak 2026-11-24 (lihat catatan PERHATIAN di bawah) — bedanya 2 hal:
1. **Minus 2 field passthrough**: `sales_by_menu` dan `sales_by_table` **tidak ada** di response.
2. **8 section dibungkus** dalam 1 blok baru `"report_detail"` (7 section sales + `cash_flow_summary`) — sebelumnya semua ini flat di top-level sama kayak V2.

Response (struktur ringkas — isi lengkap sangat panjang, lihat kode untuk detail per-field):
```json
{
  "code": 0,
  "data": {
    "dayshift": { "ulid": "...", "dayin_total": "500000.00", "dayout_total": "480000.00" },
    "dayin_user_name": "Kasir 1",
    "dayshift_detail": [ { "shift_number": 1, "shift_time": "..." } ],
    "report_detail": {
      "sales_summary": {
        "total_bills": 0,
        "bill_count": 0,
        "average_per_bill": 0,
        "on_hold": 0,
        "pending": 0,
        "other_cost": 0,
        "other_cost_count": 0,
        "sales_subtotal": 0,
        "discount": 0,
        "dpp": 0,
        "service_charge": 0,
        "order_fee": 0,
        "delivery_cost": 0,
        "rounding": 0,
        "pb1": 0,
        "vat": 0,
        "total_tax": 0,
        "net_sales": 0,
        "gross_sales": 0
      },
      "sales_type_summary": [ { "label": "Dine In", "amount": 0, "percent": 0 } ],
      "order_source_summary": [
        { "label": "POS", "qty": 0, "amount": 0, "percent": 0 },
        { "label": "QR Order", "qty": 0, "amount": 0, "percent": 0 },
        { "label": "Mobile App", "qty": 0, "amount": 0, "percent": 0 },
        { "label": "Kiosk", "qty": 0, "amount": 0, "percent": 0 }
      ],
      "item_category_summary": [ { "label": "Food", "qty": 15, "amount": 450000, "percent": 60 } ],
      "staff_sales_summary": [ { "label": "Kasir 1", "qty": 8, "amount": 300000, "percent": 40 } ],
      "payment_method_summary": [ { "label": "CASH", "qty": 5, "amount": 250000, "percent": 50 } ],
      "staff_payment_summary": [ { "label": "Kasir 1 - CASH", "qty": 0, "amount": 0, "percent": 0 } ],
      "cash_flow_summary": {
        "starting_cash": 500000,
        "cash_in": 0,
        "cash_out": 0,
        "expected_cash": 500000,
        "actual_cash": 480000
      }
    }
  }
}
```

Dibanding response Report V2 di atas: **tidak ada** key `sales_by_menu`/`sales_by_table`, dan 8 section (`sales_summary`, `sales_type_summary`, `order_source_summary`, `item_category_summary`, `staff_sales_summary`, `payment_method_summary`, `staff_payment_summary`, `cash_flow_summary`) sekarang **nested** di bawah key `report_detail` — bukan flat di top-level seperti V2. Field passthrough (`dayshift`, `dayin_user_name`, `dayshift_detail`) tetap di top-level, sama posisinya seperti V2.

Dibuat untuk consumer yang cuma butuh section summary (gak perlu render tabel Sales By Menu/Sales By Table) — bukan pengganti V2, V2 tetap dipakai `DayStartEndPage.vue` apa adanya.

> **PERHATIAN (2026-11-24)** — perubahan di bagian "Sales Summary: scope & rumus" di bawah **hanya diterapkan ke V3 dan 4 print**. `sales_summary` **Report V2 BELUM disesuaikan**: V2 masih membaca `sales_recapitulation` lewat **index posisi** (`amountAt(13)`, `amountAt(7)`, dst) yang sudah terbukti bergeser 1 dari urutan array sebenarnya (lihat daftar di bawah), jadi beberapa field V2 (`total_bills`, `pb1`, `vat`, `gross_sales`) kemungkinan berisi angka field lain. Kalimat "V3 sama persis V2" di atas hanya berlaku untuk **struktur**, bukan lagi untuk **isi angka** `sales_summary`.

## Panduan migrasi Report V2 → V3 (untuk tim FE)

> **Status (2026-11-24):** frontend POS `posv1-vue` (`DayStartEndPage.vue` via `composables/useDayshiftV2.js`) **sudah dimigrasi** ke `report-v3` untuk semua section summary. `report-v2` masih dipanggil dari FE **hanya** untuk dua tabel yang belum ada di V3, yaitu `sales_by_menu` dan `sales_by_table`. Kalau backend nanti menyediakan dua tabel itu di V3 (atau Anda memutuskan tabelnya tidak dipakai lagi), hapus pemanggilan `ReportV2` di `useDayshiftV2.js` dan V2 boleh dihapus.

Report V2 sudah **deprecated** (usang dan kurang lengkap). Kalau halaman Anda masih memanggil `GET /api/dayshift/report-v2/{dayshift_ulid}`, pindahkan ke `GET /api/dayshift/report-v3/{dayshift_ulid}`. Parameter dan autentikasi sama; yang berubah hanya bentuk dan isi response.

**1. Perubahan bentuk (struktur)**

| V2 | V3 |
|---|---|
| `data.sales_summary`, `data.sales_type_summary`, `data.order_source_summary`, `data.item_category_summary`, `data.staff_sales_summary`, `data.payment_method_summary`, `data.staff_payment_summary`, `data.cash_flow_summary` (flat) | semuanya di bawah `data.report_detail.*` (nama section sama) |
| `data.dayshift`, `data.dayin_user_name`, `data.dayshift_detail` | tetap di top-level `data.*`, tidak berubah |
| `data.sales_by_menu`, `data.sales_by_table` | **tidak ada di V3**. Kalau tabel Sales By Menu / Sales By Table masih dibutuhkan, beri tahu backend (belum ada penggantinya) |

**2. Perubahan isi `sales_summary` (breaking untuk tampilan)**

| Field | Perubahan |
|---|---|
| `total_bills` | **berubah arti**: sekarang **nominal** (`SUM(total_billing)`), bukan jumlah order. Jumlah order ada di field baru `bill_count`. Kalau UI menampilkan `total_bills` sebagai angka "jumlah bill", ganti ke `bill_count` |
| `average_per_bill` | `total_bills / bill_count` |
| `sales_subtotal` | sekarang `SUM(sub_total)` (sebelum diskon); dulu sama dengan `net_sales` |
| `net_sales` | `SUM(total_billing − total_tax)` (sudah termasuk service charge, order fee, delivery cost, rounding) |
| `gross_sales` | `SUM(total_billing)` |
| `pb1`, `vat`, `gross_sales`, `total_bills` | di V2 angkanya **salah** (indeks bergeser); V3 sudah benar. Jangan membandingkan angka V2 lama dengan V3 |
| **field baru** | `bill_count`, `order_fee`, `delivery_cost`, `rounding`, `other_cost`, `other_cost_count` |

- `other_cost` = order yang dibayar tipe OTHER (FOC/fasilitas, mis. untuk karyawan). Tampilkan sebagai baris terpisah. Nilainya **bukan pendapatan**, dan tidak ikut `total_bills`, `net_sales`, atau `gross_sales`.
- `item_category_summary`: `qty` hanya menghitung item induk (head); `amount` = head + isi package (body). Aturan lengkap ada di bagian "Category Summary" di bawah.

**3. Perubahan scope (periode data)**

Endpoint `report-v3` melaporkan **shift yang sedang berjalan** (dari End Shift terakhir, atau dari jam buka kalau belum pernah End Shift, sampai sekarang atau sampai jam tutup). Ini bukan total satu hari penuh kalau sudah ada End Shift. Detail dan tabel window ada di bagian "Sales Summary: scope & rumus" di bawah.

**4. Endpoint print**

Endpoint print (`print-*`) **tidak berubah kontraknya** (URL, parameter, response sama). Hanya isi struk yang berubah: baris baru Bill Count, Other Cost, Order Fee, Delivery Cost, Rounding, dan urutan Item By Category (item head di atas, isi package di bawah). Detail di [`PRINT.md`](PRINT.md).

## Sales Summary: scope & rumus (2026-11-24)

Berlaku untuk `report-v3` dan 4 print ([`PRINT.md`](PRINT.md#sales-summary-scope--rumus-2026-11-24)) — dihitung 4 fungsi sumber data (`GetReportCurrentShiftforTampilan()`, `GetReportDayshiftorEndDay()`, `GetReportCurrentShift()`, `GetReportPerShift()`) yang blok hitungnya identik.

### Scope data (window)

Laporan ini **per dayshift / per shift**, bukan per tanggal kalender. Order dipilih lewat **status order + kolom waktu yang sesuai statusnya** (bukan lagi `order_in`/`order_out`/`dayshift_ulid`):

| Status order | Dihitung ke | Syarat window |
|---|---|---|
| `paid` | semua angka penjualan | `order_out` di dalam window (lihat catatan di bawah — BUKAN `payment_at`) |
| `cancel` | Cancel Total | `cancel_at` di dalam window |
| `void` | Void Total | `void_at` di dalam window |
| `other_cost` | `other_cost` (baris terpisah, **bukan** penjualan) | `order_out` di dalam window |
| `pending` / `hold` | `pending` / `on_hold` | **tanpa window** — semua yang masih outstanding (lintas dayshift, sengaja) |

Window per endpoint (awal inklusif `>=`, akhir eksklusif `<`):

| Endpoint | Fungsi hitung | Awal | Akhir |
|---|---|---|---|
| `report/{id}`, `report-v2/{id}`, `report-v3/{id}` | `GetReportCurrentShiftforTampilan` | `shift_time` baris `tr_dayshift_detail` terakhir; **kalau belum ada baris detail** = `dayin_time` (1 shift utuh) | `dayout_time` kalau dayshift sudah di-End Day, kalau belum = sampai sekarang |

Catatan penting:
- `tr_dayshift_detail.shift_time` adalah waktu **AKHIR** sebuah shift (saat End Shift ditekan), bukan awal. `EndDay` tidak membuat baris detail, jadi shift terakhir hanya bisa dilaporkan lewat jalur "shift berjalan".
- Order yang **dibuat di shift 1 tapi dibayar di shift 2** masuk shift 2 (ikut `order_out`). Sebelumnya (`order_in >= awal AND order_out <= akhir`) order seperti itu hilang dari kedua shift.
- Order `hold` memang boleh menginap lintas dayshift (pending diblokir saat End Day, hold tidak) — makanya `order_out`, bukan `dayshift_ulid` (yang diisi saat order **dibuat**), yang dipakai.
- **Kenapa paid pakai `order_out`, bukan `payment_at` (2026-11-24):** `order_out` = kapan **POS memproses** order. Untuk order POS nilainya sama dengan `payment_at` (sama-sama `now()` saat bayar). Untuk order mobile/QR, `order_out` = saat POS **menarik** order (`MobileOrderPullServices`, selalu di dalam dayshift aktif), sedangkan `payment_at` = waktu customer bayar dari sudomobile — bisa jatuh sebelum `dayin_time` dayshift tempat order ditarik (POS tutup) atau di dayshift sebelumnya yang jurnal End Day-nya sudah terkirim. Jurnal End Day mengelompokkan order lewat `dayshift_ulid` (= dayshift tempat ditarik), jadi `order_out` yang konsisten dengannya. Aman dari penimpaan: `Void()` hanya menimpa `order_out` saat status berubah jadi `void` (dihitung lewat `void_at`), order berstatus `paid` tidak pernah tertimpa.
- Order void: dihitung **hanya** sebagai Void Total di shift tempat void terjadi, tidak lagi sebagai penjualan (status-nya sudah `void`).
- Kalau dayshift sudah tutup, batas atas `dayout_time` mencegah order dayshift **sesudahnya** ikut terhitung (sebelumnya jalur "shift berjalan" tidak punya batas atas).
- Order mobile yang dibayar customer **saat POS tutup** (di antara End Day dan Start Day berikutnya), atau saat dayshift sebelumnya masih buka tapi baru ditarik setelah Start Day, masuk dayshift **tempat ia ditarik** (dites live 2026-11-24). Sebelumnya (memakai `payment_at`) order seperti itu hilang dari semua window, atau nyasar ke dayshift yang sudah ditutup.

### Rumus `sales_summary` (order `paid` di dalam window, dari kolom header `tr_order`)

| Field | Rumus |
|---|---|
| `total_bills` | `SUM(total_billing)` — **AMOUNT**, bukan jumlah order (berubah 2026-11-24) |
| `bill_count` | jumlah order paid (**field baru**) |
| `average_per_bill` | `SUM(total_billing) / bill_count` (0 kalau `bill_count` = 0) |
| `on_hold` / `pending` | `SUM(total_billing)` status `hold` / `pending`, tanpa window |
| `other_cost` / `other_cost_count` | `SUM(total_billing)` dan jumlah order status `other_cost` di window (**field baru**). `other_cost` = order yang dibayar pakai payment type OTHER (FOC/fasilitas, mis. untuk karyawan): datanya nyata tapi **bukan pendapatan**, jadi **tidak** masuk `total_bills`, `bill_count`, `net_sales`, `gross_sales`, Category Summary, maupun Payment Method |
| `sales_subtotal` | `SUM(sub_total)` — total `net_price × qty`, **sebelum diskon**, tanpa pajak & SC (sebelumnya sama dengan `net_sales`) |
| `discount` | `SUM(total_discount)` |
| `dpp` | `SUM(dpp × qty)` item + package |
| `service_charge` | `SUM(total_service_charge)` |
| `order_fee` | `SUM(order_fee)` (**field baru** di V3) |
| `delivery_cost` | `SUM(delivery_cost)` (**field baru** di V3) |
| `rounding` | `SUM(rounding_amount)` (**field baru**) |
| `pb1` / `vat` | `SUM(tax_amount × qty)` per `tax_type`, item + package |
| `total_tax` | `pb1 + vat` |
| `net_sales` | `SUM(total_billing - total_tax)` — pajak = titipan ke pemerintah; service charge, order fee, delivery cost, dan rounding ikut Net Sales karena itu pendapatan |
| `gross_sales` | `SUM(total_billing)` (sama dengan `total_bills`) |

- **Package**: qty efektif = `qty parent × qty package` (sama dengan `OrderServices::RecalculateOrderTotals()`). Sebelumnya hanya `qty package` sehingga DPP/PB1/VAT package kekecilan kalau qty parent > 1.
- `NettSalesJoinSql()` (hitung ulang net dari harga) **tidak dipakai lagi** di 4 fungsi ini; fungsi itu masih dipakai 2 fungsi legacy (`GetReport()`, `GetReportByShiftDetail()`, tidak punya caller aktif).

### Pembacaan by key (bukan index)

`sales_recapitulation` (array numeric, 18 elemen + 3 elemen baru di akhir: `Sales Subtotal`, `Rounding`, `Total Bills`) dulu dibaca V3/print lewat **index literal** dan bergeser 1 dari urutan sebenarnya. Sekarang semua pembaca memakai `DayShiftServices::recapAmount($recap, '<key>')` (cari berdasarkan teks `key`, tidak ketemu = `0`). Temuan bug lama yang dibenahi:

| Field | Index yang dibaca | Isi array sebenarnya di index itu |
|---|---|---|
| Total Bills | 13 | Avg Netsales Per Bill |
| PB1 | 7 | VAT |
| VAT | 8 | Gross Sales |
| Gross Sales | 9 | Pax Total |
| Sales (Subtotal) & Net Sales | 2 (keduanya) | Net Sales — jadi 2 baris mencetak angka yang sama |

(`average_per_bill` index 14 = Avg Gross Sales Per Bill = `SUM(total_billing)/jumlah order` kebetulan sudah benar.) Elemen baru **selalu ditambah di akhir** supaya consumer lama (`report/{id}` array numeric) tidak bergeser.

### Category Summary (`item_category_summary` / section CATEGORY SUMMARY) — 2026-11-24

Aturan dari owner: **qty dihitung dari HEAD saja, amount = HEAD + BODY.** Sumber datanya `sales_by_category` di 4 fungsi sumber data.

| Bagian | Sumber | Qty | Amount |
|---|---|---|---|
| **Head** | baris `tr_order_detail` (item biasa, atau induk package) | `qty` head | `total × qty` |
| **Body** | baris `tr_order_detail_package` (isi package) | **0** (tidak menambah qty) | `total × (qty head × qty package)` |

- **Kategori body = kategori HEAD-nya**, bukan kategori menu anak (package dijual sebagai 1 kesatuan di bawah head). Dites live: package ber-head kategori FOOD dengan anak kategori BEVERAGE seluruhnya masuk FOOD. *(Ini tafsiran dari aturan owner; kalau yang diinginkan body masuk kategori menu anaknya masing-masing, query-nya perlu diubah.)*
- **Bug lama yang ikut dibenahi:** kolom `tod.total` disimpan **per satu unit** (`OrderServices::CalculateLineItem`), tapi `grand_total` lama tidak dikali qty — item qty > 1 dan isi package kekecilan, dan qty ikut menghitung isi package. Contoh di data uji: Category Summary lama 105.850 padahal jumlah `grand_total` order 228.050.
- **Integritas:** `SUM(amount semua kategori)` = `SUM(tr_order.grand_total)` order paid di window (selisih dengan `total_bills` = `order_fee` + `delivery_cost` + `rounding`, karena itu bukan baris item).
- Berlaku di struk (CATEGORY SUMMARY, 4 print), `report-v3`, dan juga `item_category_summary` di `report-v2` (sama-sama membaca `sales_by_category`).
- **ITEM BY CATEGORY SUMMARY** (`sales_by_category_item`, hanya ada di 3 fungsi print; 2026-11-24): query yang sama diperbaiki dengan tambahan kolom `is_body` (0 = head, 1 = body/isi package) —
  - **Urutan: HEAD di atas, BODY di bawah** dalam tiap kategori (permintaan owner). SQL `ORDER BY category_name, is_body, menu_name`, dan `printCategoryItemBreakdownSection()` mengurutkan ulang item (`usort` stabil) supaya tidak bergantung urutan SQL.
  - Baris body memakai **nama menu anak**, tapi **kategori head-nya** (sama dengan Category Summary, jadi `Total Item By Category` = `Total Category`); qty body efektif = qty head × qty package; nilai dikali qty.
  - Head dan body dengan nama menu sama (mis. menu yang dijual sendiri dan juga jadi isi package) tampil sebagai **2 baris terpisah** — saat ini **tanpa penanda** di struk selain posisinya (head dulu, body setelahnya).
- `sales_by_menu` (hanya V2 raw passthrough) **belum diubah** — masih memakai query lama (`total` tidak dikali qty, qty body ikut terhitung).

### Cash Flow Summary (`cash_flow_summary`) — 2026-11-24

| Field | Rumus |
|---|---|
| `starting_cash` | `tr_dayshift.dayin_total` (modal awal dayshift) |
| `cash_in` | `SUM(tr_order_payment.payment_amount)` dari order paid di window, **hanya metode bertipe CASH** |
| `cash_out` | selalu `0` (placeholder, belum ada sumber data) dan **tidak ikut rumus `expected_cash`** |
| `expected_cash` | **`cash_in + starting_cash`** (aturan owner) |
| `actual_cash` | `tr_dayshift.dayout_total` |
| `variance` | `actual_cash − expected_cash` |

- **Penentuan "CASH" memakai ID, bukan teks**: `mr_payment_method.payment_method_type_id = 1` (id baris `CASH` di `mr_payment_method_type`; dicek di DB: 1 CASH, 2 CARD, 3 VOUCHER, 4 OTHER, 5 WALLET_PAYMENT). Semua metode bertipe 1 ikut dihitung, tidak peduli namanya (di DB dev: `CASH` id 2 dan `CASH - TONA` id 65). Tidak ada perbandingan nama metode.
- `payment_amount` sudah **bersih dari kembalian** (kembalian ada di kolom `change_amount` terpisah), jadi `cash_in` tidak perlu dikurangi kembalian.
- Order `other_cost` (tipe OTHER) bukan CASH dan tidak ada di daftar order paid, jadi tidak masuk `cash_in`.
- Berlaku di `report-v3` dan keempat struk. Report V2 (deprecated) masih memakai rumus lama `starting_cash + cash_in − cash_out` (hasilnya sama selama `cash_out` = 0).
- Catatan lama yang tetap berlaku: `starting_cash` pada struk/report level shift tetap `dayin_total` dayshift (bukan saldo awal tiap shift), jadi nilainya sama di semua shift dalam satu dayshift.

## Pending Order

```
GET /api/dayshift/pending-order
```

Semua order `status = 'pending'` **lintas dayshift** (tidak di-scope ke dayshift aktif) — `TrOrderModel::where('status', 'pending')->get()`, tanpa `WHERE` tambahan apapun.

Response: `{ "code": 0, "data": [ {...1 baris tr_order lengkap, semua kolom...} ] }` — contoh JSON lengkap 1 baris (semua kolom) ada di [`START END DAY.md`](START%20END%20DAY.md#end-day), section "Guard pending order" (struktur `data` di situ identik, cuma beda `code` — endpoint ini selalu `0`).

**Dipakai sebagai guard di End Day** (2026-11-17, `DayShiftController::EndDay()`) — kalau masih ada order pending, End Day **ditolak total** (`code: 100`, `data` berisi daftar order pending itu) sebelum tahap VALIDASI/SAVE/PUSH/JURNAL manapun dijalankan. Lihat [`START END DAY.md`](START%20END%20DAY.md#end-day) untuk detail lengkapnya — ini BUKAN lagi cuma "peringatan sebelum lanjut", tapi penolakan keras di server.

## Alur Implementasi

- `dayshift-list` dipakai di `ShiftLogPage.vue` — riwayat log shift lintas hari.
- `report-v2` **DEPRECATED — usang, kurang lengkap, pakai `report-v3` saja (lihat section Report V2)** — FE `DayStartEndPage.vue` sudah pindah ke `report-v3` untuk summary (2026-11-24); V2 tinggal dipanggil untuk `sales_by_menu` dan `sales_by_table` saja.
- `pending-order` — query yang sama (2026-11-17) di-reuse sebagai GUARD KERAS di server (`DayShiftController::EndDay()`), bukan cuma warning FE lagi — End Day ditolak total kalau masih ada order pending.

## Sumber

- `app/Http/Controllers/DayShiftController.php` — `DayShiftList()`, `ListDayShiftDetail()`, `Report()`, `ReportV2()`, `ReportV3()`, `pendingOrder()`.
- `app/Services/DayShiftServices.php` — `GetDayshiftList()`, `GetReport()` (v1), `GetReportCurrentShiftforTampilan()`, `GetReportSummaryV2()`, `GetReportSummaryV3()`, `breakdownWithPercent()` (private), `GetReportOrderList()` (private, scope window berbasis status — 2026-11-24), `recapAmount()` (public static, baca recap by key — 2026-11-24).
- Frontend: `src/services/DayshiftServices.ts` (`DayShiftDetailList`), `src/modules/dashboard/shiftlog/ShiftLogPage.vue`, `src/modules/dashboard/daystart_end/DayStartEndPage.vue`.

## Status

Didokumentasikan 2026-10-12, berdasar pembacaan kode langsung (belum ada verifikasi ulang lewat HTTP request live di sesi ini). Report V2 sangat kompleks (ratusan baris kalkulasi) — ringkasan ini struktural, disarankan baca `GetReportSummaryV2()`/`GetReportCurrentShiftforTampilan()` langsung kalau butuh detail formula tiap angka.

Diupdate 2026-10-20: ditambahkan section `staff_payment_summary` — perubahan dilakukan konsisten di 4 fungsi sumber data (`GetReportCurrentShiftforTampilan()`, `GetReportDayshiftorEndDay()`, `GetReportCurrentShift()`, `GetReportPerShift()`), `GetReportSummaryV2()`, dan 4 fungsi print (`PrintEndDay()`, `PrintReportDaysift()`, `PrintCurrentShift()`, `PrintPerShiftv2()` — lihat [`PRINT.md`](PRINT.md)). FE (`DayStartEndPage.vue`, repo `posv1-vue`) ditambahkan card baru "Staff Payment Summary".

Diupdate 2026-11-23 (lanjutan): file di-rename `REPORT AND HISTORY.md` -> `LIST AND DETAIL.md` (judul disesuaikan jadi "List, Detail, Report, Pending Order"), sejalan dengan `PRINT AND DETAIL LIST.md` yang juga di-rename jadi `PRINT.md` di periode yang sama.

Diupdate 2026-11-13: ditambahkan `report-v3` — `GetReportSummaryV3()` membangun ulang response yang sama persis dengan `GetReportSummaryV2()` (dipanggil `GetReportCurrentShiftforTampilan()` secara independen, bukan memanggil V2 lalu `unset()`), minus field `sales_by_menu`/`sales_by_table`. Belum ada verifikasi live/HTTP di sesi ini, sudah dicek `php -l` bersih di 3 file (`DayShiftServices.php`, `DayShiftController.php`, `routes/api.php`).

Diupdate 2026-11-17: `pending-order` sekarang juga dipakai sebagai GUARD KERAS di `DayShiftController::EndDay()` (bukan cuma informasi/warning FE lagi) — lihat [`START END DAY.md`](START%20END%20DAY.md#end-day), section "Guard pending order", untuk detail lengkap + contoh JSON + hasil verifikasi live.

Diupdate 2026-11-24: audit "Total Bills / Average per Bill" membuka 3 masalah, semuanya diperbaiki di `report-v3` + 4 print (lihat section "Sales Summary: scope & rumus"): (1) scope data pindah dari `order_in`/`order_out` ke status + `order_out` (paid) / `cancel_at` / `void_at` (order lintas-shift tidak hilang lagi); (2) rumus Net Sales/Gross Sales/Total Bills/Sales Subtotal didefinisikan ulang dari header `tr_order`, plus field baru `bill_count`, `order_fee`, `delivery_cost`, `rounding`; (3) pembacaan recap diganti dari index posisi (bergeser 1) ke by-key. **`report-v2` belum disentuh** (masih index posisi) — lihat catatan PERHATIAN di section Report V3. **Dites live** (194 pemeriksaan lulus, 0 gagal, termasuk 2 skenario order mobile di celah antar-dayshift) lewat pemanggilan langsung 4 fungsi hitung + `GetReportSummaryV3()` dengan data uji sementara (2 shift, order lintas-shift, package parent qty 2, cancel, void, hold, pending, dayshift tanpa detail, dayshift aktif vs tutup) — data uji dihapus setelahnya. **Belum dites lewat HTTP** (middleware token/branch tidak dilewati), tapi endpoint hanya membungkus `GetReportSummaryV3()` apa adanya.

Diupdate 2026-11-24 (lanjutan): **Category Summary** diperbaiki sesuai aturan owner (qty head saja, amount head + body, body ikut kategori head, nilai dikali qty) — lihat section "Category Summary" di atas. Dites live (234 pemeriksaan total lulus, 0 gagal, termasuk package dengan anak beda kategori + pemeriksaan `SUM(kategori) = SUM(grand_total)` di tiap skenario) dan struk End Day dicetak ulang ke listener lokal. ITEM BY CATEGORY SUMMARY dan `sales_by_menu` belum diubah.

Diupdate 2026-11-24 (lanjutan 2): **ITEM BY CATEGORY SUMMARY** — head diurutkan di atas body, nilai dikali qty, body ikut kategori head (lihat bullet di section "Category Summary"). Dites live: 282 pemeriksaan total lulus, 0 gagal (termasuk `SUM(item) = SUM(Category Summary)`, head tidak pernah muncul setelah body, qty body = qty head × qty package, kategori body = kategori head).

Diupdate 2026-11-24 (lanjutan 3): ditambahkan **Other Cost** (status `other_cost`, FOC/fasilitas) sebagai baris terpisah di `report-v3` (`other_cost`, `other_cost_count`) dan 4 struk — nilai apa adanya, tidak masuk hitungan pendapatan. Hanya logika laporan; logika pembayaran/status order tidak diubah. Dites live (304 pemeriksaan total lulus, 0 gagal, termasuk bahwa order other_cost tidak bocor ke Total Bills/Net/Gross/Category).

Diupdate 2026-11-24 (lanjutan 4): (1) **Cash Flow Summary**: `expected_cash = cash_in + starting_cash` (aturan owner, V3 + 4 struk); `cash_in` diverifikasi memakai `payment_method_type_id = 1` (id) di keempat fungsi sumber data, tidak ada perbandingan teks. (2) **Frontend `posv1-vue` disesuaikan**: summary dipindah ke `report-v3`, baris Sales Summary memakai field baru, `ReportV2` hanya untuk `sales_by_menu`/`sales_by_table`. Build FE (`vite build`) sukses; tampilan di browser belum dicoba.
