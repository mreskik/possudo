# Report, Dayshift List, Pending Order

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

## Report (v1, LEGACY — tidak dipakai FE lagi)

```
GET /api/dayshift/report/{dayshift_ulid}
```

Laporan ringkasan 1 dayshift — **struktur array numeric-index**. **Sudah digantikan [`Report V2`](#report-v2)**, route dan kode-nya tetap ada (tidak dihapus) tapi `DayStartEndPage.vue` tidak memanggilnya lagi. Tidak perlu di-port untuk migrasi kecuali ada kebutuhan spesifik yang belum diketahui.

## Report V2 (aktif dipakai)

```
GET /api/dayshift/report-v2/{dayshift_ulid}
```

Laporan lengkap 1 dayshift — **struktur key-value**, 1:1 sama persis dengan section yang dicetak `PrintServices::PrintEndDay()` (jadi angka di layar = angka di struk fisik, tidak ada 2 sumber kebenaran).

Response (struktur ringkas — isi lengkap sangat panjang, lihat kode untuk detail per-field):
```json
{
  "code": 0,
  "data": {
    "dayshift": { "ulid": "...", "dayin_total": "500000.00", "dayout_total": "480000.00" },
    "dayin_user_name": "Kasir 1",
    "dayshift_detail": [ { "shift_number": 1, "shift_time": "..." } ],
    "sales_by_menu": [...],
    "sales_by_table": [...],
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
    "item_category_summary": [...],
    "staff_sales_summary": [...],
    "payment_method_summary": [...],
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

## Pending Order

```
GET /api/dayshift/pending-order
```

Semua order `status = 'pending'` **lintas dayshift** (tidak di-scope ke dayshift aktif).

Response: `{ "code": 0, "data": [ { "order_number": "...", "status": "pending", "...": "tr_order lengkap" } ] }`

Dipakai saat End Day — kalau masih ada order pending yang belum selesai, biasanya ditampilkan sebagai peringatan sebelum kasir melanjutkan tutup hari.

## Alur Implementasi

- `dayshift-list` dipakai di `ShiftLogPage.vue` — riwayat log shift lintas hari.
- `report-v2` dipakai `DayStartEndPage.vue` — ditampilkan sebelum kasir konfirmasi End Day, dan sebagai ringkasan pasca-End Day.
- `pending-order` — dicek sebelum proses End Day untuk warning "masih ada order belum selesai".

## Sumber

- `app/Http/Controllers/DayShiftController.php` — `DayShiftList()`, `Report()`, `ReportV2()`, `pendingOrder()`.
- `app/Services/DayShiftServices.php` — `GetDayshiftList()`, `GetReport()` (v1), `GetReportCurrentShiftforTampilan()`, `GetReportSummaryV2()`, `breakdownWithPercent()` (private).
- Frontend: `src/services/DayshiftServices.ts`, `src/modules/dashboard/shiftlog/ShiftLogPage.vue`, `src/modules/dashboard/daystart_end/DayStartEndPage.vue`.

## Status

Didokumentasikan 2026-10-12, berdasar pembacaan kode langsung (belum ada verifikasi ulang lewat HTTP request live di sesi ini). Report V2 sangat kompleks (ratusan baris kalkulasi) — ringkasan ini struktural, disarankan baca `GetReportSummaryV2()`/`GetReportCurrentShiftforTampilan()` langsung kalau butuh detail formula tiap angka.

Diupdate 2026-10-20: ditambahkan section `staff_payment_summary` — perubahan dilakukan konsisten di 4 fungsi sumber data (`GetReportCurrentShiftforTampilan()`, `GetReportDayshiftorEndDay()`, `GetReportCurrentShift()`, `GetReportPerShift()`), `GetReportSummaryV2()`, dan 4 fungsi print (`PrintEndDay()`, `PrintReportDaysift()`, `PrintCurrentShift()`, `PrintPerShiftv2()` — lihat [`PRINT AND DETAIL LIST.md`](PRINT%20AND%20DETAIL%20LIST.md)). FE (`DayStartEndPage.vue`, repo `posv1-vue`) ditambahkan card baru "Staff Payment Summary".
