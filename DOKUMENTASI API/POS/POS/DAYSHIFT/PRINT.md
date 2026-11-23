# Print Reports

Semua endpoint di sini memicu cetak fisik ke printer — pola serupa [`../ORDER/PRINT BILL.md`](../ORDER/PRINT%20BILL.md), bukan endpoint data biasa.

**"Detail List" (data, bukan print) PINDAH ke [`LIST AND DETAIL.md`](LIST%20AND%20DETAIL.md#detail-list) (2026-11-23)** — endpoint itu bukan print sama sekali, cocoknya digabung sama endpoint data log shift lain (`dayshift-list`), bukan nyampur di file yang isinya murni print.

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

## Print Curent Dayshift Report

```
GET /api/dayshift/print-endday-report/{dayshift_ulid}
```

**REFACTOR TOTAL (2026-11-23)** — endpoint ini SEKARANG KHUSUS dayshift yang **MASIH BERJALAN** (`dayout_time` belum terisi). **DITOLAK** (`code: 100`) kalau dayshift yang dimaksud **sudah** di-End Day — pesan: `"dayshift sudah di-End Day, gunakan print-report-dayshift untuk cetak ulang!"`. Dayshift tidak ditemukan → `code: 100`, `"dayshift not found!"`.

Secara internal manggil `PrintServices::PrintReportDaysift()` (BUKAN `PrintServices::PrintEndDay()` lagi) — struk yang tercetak format **3-baris-kasir** (Cashier Start/Cashier End/Cashier Print — lihat section "Print Report Dayshift" di bawah untuk detail isi strukturnya). Karena dayshift masih aktif, "Cashier End" akan tampil kosong (belum ada yang nutup shift).

Response sukses: `{ "code": 0, "message": "success print endday report" }`.

**Sebelum refactor ini**: endpoint ini manggil `PrintServices::PrintEndDay()` (1-baris-kasir) dan bisa dipanggil untuk dayshift mana pun (aktif atau tutup) tanpa guard apa pun — sekarang dibalik total: GUARD WAJIB AKTIF, fungsi print-nya ganti ke `PrintReportDaysift()`.

`PrintServices::PrintEndDay()` **masih ada di kode** (tidak dihapus), tapi sejak refactor ini **tidak ada 1 pun endpoint HTTP** yang memanggilnya lagi — baik titik ini maupun panggilan otomatis End Day (lihat catatan di [`START END DAY.md`](START%20END%20DAY.md)) sudah dipindah ke `PrintReportDaysift()`.

## Print Report Dayshift

```
GET /api/dayshift/print-report-dayshift/{dayshift_ulid}
```

Cetak laporan dayshift (`PrintServices::PrintReportDaysift()`) — nama fungsi mirip "Print Report" di atas tapi **beda implementasi** (bukan alias). **TANPA guard** — bisa dipanggil untuk dayshift mana pun, aktif ATAUPUN sudah di-End Day (beda dari "Print Endday Report (On Running)" di atas yang guard-nya KHUSUS dayshift aktif). Response: `{ "code": 0, "message": "success print report dayshift" }`.

**Sekarang dipanggil dari DUA pintu**: endpoint ini sendiri (tanpa guard, dari awal), DAN endpoint "Print Endday Report (On Running)" di atas (dengan guard khusus aktif, sejak refactor 2026-11-23) — DAN panggilan otomatis saat `EndDay()` berhasil (lihat [`START END DAY.md`](START%20END%20DAY.md), TANPA guard di titik itu karena dayshift-nya sudah pasti ditutup duluan di proses End Day).

`GetReportDayshiftorEndDay()` (service di balik fungsi ini) **sejak 2026-11-23 bisa dipanggil untuk dayshift AKTIF** (`dayout_time` NULL) — scope datanya otomatis jadi "dari jam buka sampai SEKARANG" (bukan sampai jam tutup, karena belum ada). Sebelumnya fungsi ini SELALU melempar exception `"end day has not been done yet!"` kalau dayshift belum ditutup — guard itu sudah dihapus.

## Alur Implementasi

Dipanggil dari berbagai tombol "Print"/"Reprint" di `DayStartEndPage.vue` — karena semuanya trigger-and-forget tanpa validasi hasil cetak yang jelas (sama pola [`../ORDER/PRINT BILL.md`](../ORDER/PRINT%20BILL.md)), FE tidak bisa mengandalkan response untuk konfirmasi print benar-benar terjadi di printer fisik.

**Non-blocking (2026-11-23)**: SEMUA endpoint print di file ini (kecuali "Detail List" yang bukan print) sekarang **non-blocking** — `DayShiftController` tidak lagi panggil `PrintServices::PrintXxx()` langsung, melainkan `PrintServices::dispatchPrintJob()` (HTTP self-call ke endpoint internal `api/internal/print/*`, localhost-only, timeout 1 detik). Response HTTP balik cepat TANPA menunggu print fisik selesai — proses print sebenarnya jalan di request terpisah (`PrintJobController`, `ignore_user_abort(true)`), jadi tetap jalan sampai selesai walau request pemanggil sudah "pergi" karena timeout. Ini memperbaiki bug lama: printer network bermasalah (`NetworkPrintConnector::fsockopen`) yang dulu bisa nge-hang sampai 60 detik dan menahan response.

**Catatan migrasi**: `print-report` (dead, tidak melakukan apa-apa) sebaiknya **tidak** di-port sebagai fitur aktif di FE baru — cek dulu apakah memang masih dibutuhkan atau sudah digantikan sepenuhnya oleh endpoint print lain di file ini.

## Sumber

- `app/Http/Controllers/DayShiftController.php` — `printReport()`, `printReportByShift()`, `printCurrentShiftReport()`, `printEndayReport()` (guard dayshift aktif, 2026-11-23), `printReportDaysift()`.
- `app/Services/PrintServices.php` — `PrintPerShiftv2()`, `PrintCurrentShift()`, `PrintEndDay()` (masih ada, TANPA caller HTTP aktif sejak 2026-11-23), `PrintReportDaysift()`, `dispatchPrintJob()` (`PrintReport()` tidak pernah dipanggil, sesuai catatan di atas).
- `app/Services/DayShiftServices.php` — `GetReportDayshiftorEndDay()` (guard `dayout_time` wajib terisi DIHAPUS 2026-11-23, sekarang bisa dayshift aktif maupun tutup).
- `app/Http/Controllers/PrintJobController.php` — endpoint internal yang benar-benar menjalankan print (dipanggil `dispatchPrintJob()`, localhost-only).
- Frontend: `src/services/DayshiftServices.ts` (`printReport`, `printReportByShift`), `src/modules/dashboard/daystart_end/DayStartEndPage.vue`.

## Status

Didokumentasikan 2026-10-12, berdasar pembacaan kode langsung. Logic cetak fisik (`PrintServices`) tidak ditelusuri detail — di luar scope dokumentasi API (urusan driver printer, bukan kontrak HTTP).

Diupdate 2026-10-20: semua 4 fungsi print di sini (`PrintEndDay()`, `PrintReportDaysift()`, `PrintCurrentShift()`, `PrintPerShiftv2()`) dapat section baru "STAFF PAYMENT SUMMARY" (breakdown staff × payment method) — lihat detail isi section di [`LIST AND DETAIL.md`](LIST%20AND%20DETAIL.md#report-v2-aktif-dipakai) (field `staff_payment_summary`, sama data persis dengan yang dicetak di sini).

Diupdate 2026-11-23: (1) semua endpoint print di file ini jadi non-blocking (`dispatchPrintJob()`, lihat "Alur Implementasi"); (2) "Print Endday Report" di-refactor jadi "(On Running)" — guard khusus dayshift aktif, fungsi print-nya pindah ke `PrintReportDaysift()`; (3) `GetReportDayshiftorEndDay()` sekarang bisa dipanggil untuk dayshift aktif, tidak lagi exclusive buat yang sudah di-End Day. Dites live lewat HTTP request sungguhan (dayshift aktif & tutup, guard tertolak & lolos) di sesi ini.

Diupdate 2026-11-23 (lanjutan): section "Detail List" DIPINDAH ke [`LIST AND DETAIL.md`](LIST%20AND%20DETAIL.md#detail-list) — judul file disesuaikan jadi "Print Reports" doang (bukan lagi "& Detail List"), karena sekarang isinya murni endpoint print, tidak ada lagi endpoint data biasa di sini.

Diupdate 2026-11-23 (lanjutan lagi): file di-rename `PRINT AND DETAIL LIST.md` -> `PRINT.md` (lebih pendek, sesuai isinya yang sekarang murni print doang).
