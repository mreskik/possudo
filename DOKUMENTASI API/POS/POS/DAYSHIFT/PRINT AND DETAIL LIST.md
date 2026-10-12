# Print Reports & Detail List

Semua endpoint di sini memicu cetak fisik ke printer — pola serupa [`../ORDER/PRINT BILL.md`](../ORDER/PRINT%20BILL.md), bukan endpoint data biasa.

## Detail List (data, bukan print)

```
GET /api/dayshift/detail-list/{dayshift_ulid}
```

**Satu-satunya endpoint non-print** di file ini — daftar baris `tr_dayshift_detail` (log pergantian shift) untuk 1 dayshift. Response: `{ "code": 0, "data": [ { "shift_number": 1, "shift_time": "...", "shift_user_id": 5 } ] }`.

## Print Report

```
GET /api/dayshift/print-report/{dayshift_ulid}
```

**TIDAK melakukan apa pun saat ini** — baris `PrintServices::PrintReport($dayshift_ulid)` **dikomentari** di kode, endpoint cuma balikin `{ "code": 0, "message": "ashiappp" }` tanpa efek nyata. Secara fungsional dead, tapi route-nya tetap aktif/accessible.

## Print Report By Shift

```
GET /api/dayshift/print-report-shift/{dayshift_detail_ulid}
```

Cetak laporan 1 shift spesifik (`PrintServices::PrintPerShiftv2()`) — **parameter berbeda** dari endpoint lain di file ini (pakai `dayshift_detail_ulid`, bukan `dayshift_ulid`). Response: `{ "code": 0, "message": "ashiappp" }` kalau sukses.

## Print Current Shift Report

```
GET /api/dayshift/print-current-shift-report/{dayshift_ulid}
```

Cetak laporan shift yang sedang berjalan saat ini (`PrintServices::PrintCurrentShift()`). Response: `{ "code": 0, "message": "success print current shift report" }`.

## Print Endday Report

```
GET /api/dayshift/print-endday-report/{dayshift_ulid}
```

Cetak ulang laporan End Day (`PrintServices::PrintEndDay()`) — fungsi yang **sama** dipanggil otomatis saat [`START END DAY.md`](START%20END%20DAY.md) `EndDay()` berhasil, di sini dipakai untuk **cetak ulang** manual. Response: `{ "code": 0, "message": "success print endday report" }`.

## Print Report Dayshift

```
GET /api/dayshift/print-report-dayshift/{dayshift_ulid}
```

Cetak laporan dayshift (`PrintServices::PrintReportDaysift()`) — nama fungsi mirip "Print Report" di atas tapi **beda implementasi** (bukan alias). Response: `{ "code": 0, "message": "success print report dayshift" }`.

## Alur Implementasi

Dipanggil dari berbagai tombol "Print"/"Reprint" di `DayStartEndPage.vue` — karena semuanya trigger-and-forget tanpa validasi hasil cetak yang jelas (sama pola [`../ORDER/PRINT BILL.md`](../ORDER/PRINT%20BILL.md)), FE tidak bisa mengandalkan response untuk konfirmasi print benar-benar terjadi di printer fisik.

**Catatan migrasi**: `print-report` (dead, tidak melakukan apa-apa) sebaiknya **tidak** di-port sebagai fitur aktif di FE baru — cek dulu apakah memang masih dibutuhkan atau sudah digantikan sepenuhnya oleh endpoint print lain di file ini.

## Sumber

- `app/Http/Controllers/DayShiftController.php` — `ListDayShiftDetail()`, `printReport()`, `printReportByShift()`, `printCurrentShiftReport()`, `printEndayReport()`, `printReportDaysift()`.
- `app/Services/PrintServices.php` — `PrintPerShiftv2()`, `PrintCurrentShift()`, `PrintEndDay()`, `PrintReportDaysift()` (`PrintReport()` tidak pernah dipanggil, sesuai catatan di atas).
- Frontend: `src/services/DayshiftServices.ts` (`DayShiftDetailList`, `printReport`, `printReportByShift`), `src/modules/dashboard/daystart_end/DayStartEndPage.vue`.

## Status

Didokumentasikan 2026-10-12, berdasar pembacaan kode langsung (belum ada verifikasi ulang lewat HTTP request live di sesi ini). Logic cetak fisik (`PrintServices`) tidak ditelusuri detail — di luar scope dokumentasi API (urusan driver printer, bukan kontrak HTTP).
