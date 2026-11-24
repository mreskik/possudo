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

## Sales Summary: scope & rumus (2026-11-24)

Section "SALES SUMMARY" di keempat struk (`PrintEndDay()`, `PrintReportDaysift()`, `PrintCurrentShift()`, `PrintPerShiftv2()`) memakai data, rumus, dan aturan scope yang **sama persis** dengan `report-v3` — rumus lengkap + penjelasan ada di [`LIST AND DETAIL.md`](LIST%20AND%20DETAIL.md#sales-summary-scope--rumus-2026-11-24), tidak diulang di sini.

Scope window per endpoint print (awal inklusif `>=`, akhir eksklusif `<`; order dipilih lewat **status + `order_out` (paid) / `cancel_at` / `void_at`**, pending/hold tanpa window — alasan paid pakai `order_out` bukan `payment_at`: lihat [`LIST AND DETAIL.md`](LIST%20AND%20DETAIL.md#sales-summary-scope--rumus-2026-11-24)):

| Endpoint | Fungsi print | Fungsi hitung | Awal | Akhir |
|---|---|---|---|---|
| `print-current-shift-report/{id}` | `PrintCurrentShift()` | `GetReportCurrentShift()` | `shift_time` detail terakhir; kalau belum ada detail = `dayin_time` | `dayout_time` kalau sudah tutup, kalau belum = sampai sekarang |
| `print-endday-report/{id}` (hanya dayshift aktif) | `PrintReportDaysift()` | `GetReportDayshiftorEndDay()` | `dayin_time` | sampai sekarang |
| `print-report-dayshift/{id}` (+ panggilan otomatis End Day) | `PrintReportDaysift()` | `GetReportDayshiftorEndDay()` | `dayin_time` | `dayout_time` kalau sudah tutup, kalau belum = sampai sekarang |
| `print-report-shift/{detail_id}` | `PrintPerShiftv2()` | `GetReportPerShift()` | `shift_time` shift sebelumnya (shift 1 = `dayin_time`) | `shift_time` shift ini |
| *(tanpa endpoint HTTP aktif)* | `PrintEndDay()` | `GetReportDayshiftorEndDay()` | sama dengan `print-report-dayshift` | sama |

`tr_dayshift_detail.shift_time` = waktu **akhir** shift (saat End Shift). Shift terakhir (setelah End Shift terakhir sampai End Day) tidak punya baris detail sendiri, jadi **hanya bisa dicetak lewat `print-current-shift-report`**, bukan `print-report-shift`.

Isi baris SALES SUMMARY sekarang (urutan cetak):

```
Total Bills       (SUM total_billing, amount)
Bill Count        (jumlah order paid)           <- baris baru
Average / Bill    (Total Bills / Bill Count)
On Hold
Pending
Other Cost        (order other_cost/FOC, bukan pendapatan)   <- baris baru
-----
Sales (Subtotal)  (SUM sub_total, sebelum diskon)
Discount
DPP
Service Charge
Order Fee                                         <- baris baru
Delivery Cost                                     <- baris baru
Rounding                                          <- baris baru
PB1 (Tax)
VAT
Total Tax
-----
Net Sales         (SUM total_billing - total_tax)
Gross Sales       (SUM total_billing)
```

**CATEGORY SUMMARY** (di keempat struk) sekarang: **qty = head saja, amount = head + body** (body = isi package, masuk kategori head-nya, nilai dikali qty) — aturan lengkap di [`LIST AND DETAIL.md`](LIST%20AND%20DETAIL.md#category-summary-item_category_summary--section-category-summary--2026-11-24). Section **ITEM BY CATEGORY SUMMARY** di struk yang sama ikut diperbaiki (2026-11-24): dalam tiap kategori **item HEAD dicetak di atas, item BODY (isi package) di bawah**, body ikut kategori head, nilai dikali qty — jadi `Total Item By Category` sama dengan `Total Category`.

Semua nilai dibaca **by key** lewat `DayShiftServices::recapAmount()`, bukan index posisi `$sales_recap[N]` — index lama bergeser 1 (PB1 tercetak angka VAT, VAT tercetak Gross Sales, Gross Sales tercetak Pax Total, Total Bills tercetak Avg Netsales Per Bill) di keempat struk.

## Alur Implementasi

Dipanggil dari berbagai tombol "Print"/"Reprint" di `DayStartEndPage.vue` — karena semuanya trigger-and-forget tanpa validasi hasil cetak yang jelas (sama pola [`../ORDER/PRINT BILL.md`](../ORDER/PRINT%20BILL.md)), FE tidak bisa mengandalkan response untuk konfirmasi print benar-benar terjadi di printer fisik.

**Non-blocking (2026-11-23)**: SEMUA endpoint print di file ini (kecuali "Detail List" yang bukan print) sekarang **non-blocking** — `DayShiftController` tidak lagi panggil `PrintServices::PrintXxx()` langsung, melainkan `PrintServices::dispatchPrintJob()` (HTTP self-call ke endpoint internal `api/internal/print/*`, localhost-only, timeout 1 detik). Response HTTP balik cepat TANPA menunggu print fisik selesai — proses print sebenarnya jalan di request terpisah (`PrintJobController`, `ignore_user_abort(true)`), jadi tetap jalan sampai selesai walau request pemanggil sudah "pergi" karena timeout. Ini memperbaiki bug lama: printer network bermasalah (`NetworkPrintConnector::fsockopen`) yang dulu bisa nge-hang sampai 60 detik dan menahan response.

**Akses & keamanan endpoint print (2026-11-24):**

| Endpoint | Penjaga |
|---|---|
| `api/internal/print/*` (yang benar-benar mencetak; dipanggil `dispatchPrintJob()`) | middleware **`restrict.localhost`**: hanya IP koneksi `127.0.0.1` / `::1`, selain itu `403 Forbidden`. Tidak ada validasi token. Header proxy (`X-Forwarded-For` dst) **tidak** dipercaya, jadi tidak bisa dipalsukan dari komputer lain |
| `dayshift/print-*`, `order/print-bill`, `sales/reprint/*`, `setting/test-print*` (endpoint publik pemicu cetak) | **tidak ada penjaga IP maupun token** di level rute; perangkat apa pun yang bisa menjangkau server bisa memicunya |

**Self-call selalu ke loopback:** `dispatchPrintJob()` memanggil `api/internal/print/{path}` ke **`127.0.0.1`** (scheme dan port dari request, header `Host` asli diteruskan; jika `https`, verifikasi sertifikat dimatikan khusus untuk panggilan loopback ini). Sebelum 2026-11-24 URL dibangun dari host yang dipakai browser, sehingga kalau POS dibuka lewat **IP LAN atau nama host** (mis. `http://192.168.0.138/api/dayshift/print-report-dayshift/...`) panggilan ke diri sendiri datang dari IP LAN server (bukan loopback) dan **ditolak `restrict.localhost` (403) secara senyap** — endpoint publik tetap membalas `"success print report dayshift"` tetapi tidak ada yang tercetak. Dibuka lewat `localhost` selalu jalan. Perbaikan ini hanya mengubah alamat tujuan panggilan; **penjaga `restrict.localhost` tidak diubah**, jadi komputer lain tetap tidak bisa memanggil `/internal/print/*` langsung.

Panggilan dari job latar belakang tanpa request HTTP (`MobileOrderPullServices`) tetap memakai `APP_URL` (`.env` dev: `http://localhost`); pastikan nilainya `localhost`/`127.0.0.1` di komputer POS.
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

Diupdate 2026-11-24: section SALES SUMMARY di 4 struk dibenahi — scope window berbasis status (`order_out` untuk paid, `cancel_at`, `void_at`), rumus baru, 4 baris baru (Bill Count, Order Fee, Delivery Cost, Rounding), pembacaan by key. Lihat section "Sales Summary: scope & rumus". **Dites live**: 4 fungsi print dipanggil sungguhan dengan data uji sementara dan station Network sementara yang menunjuk ke listener TCP lokal (bukan printer fisik) — byte ESC/POS yang diterima diparse, angka Sales Summary tiap struk cocok dengan hitungan manual (End Day tutup: Total Bills 228.100 / 4 bill; shift berjalan 25.050 / 1 bill; shift 2: 62.000 / 1 bill). Station dan data uji dihapus setelahnya. Cetak ke printer fisik sungguhan **tidak** dites.

Diupdate 2026-11-24 (lanjutan): CATEGORY SUMMARY di 4 struk mengikuti aturan owner (qty head saja, amount head + body). Contoh struk End Day data uji: Qty 8, Total 228.050 (sebelumnya Qty 11, Total 105.850). Dites lewat listener TCP lokal, printer fisik tidak dites.

Diupdate 2026-11-24 (lanjutan 2): ITEM BY CATEGORY SUMMARY mengurutkan head di atas body. Contoh struk data uji (package qty 2): `NASI LEMAK NUSANTARA (8) 166.050` (head) lalu `CARAME MACHIATO (2) 40.000` dan `NASI LEMAK NUSANTARA (4) 22.000` (body); `Total Item By Category` 228.050 = `Total Category`. Dites lewat listener TCP lokal.

Diupdate 2026-11-24 (lanjutan 3): baris **Other Cost** ditambahkan di SALES SUMMARY (setelah Pending) — nilai order berstatus `other_cost` (FOC/fasilitas) apa adanya, tidak ikut Total Bills/Net Sales/Gross Sales.

Diupdate 2026-11-24 (lanjutan 4): CASH FLOW SUMMARY di 4 struk — `Expected Cash` = `Cash In + Starting Cash` (baris `Cash Out` tetap tercetak tapi selalu 0 dan tidak ikut rumus). `Cash In` hanya metode bertipe CASH (`payment_method_type_id = 1`). Rumus lengkap: [`LIST AND DETAIL.md`](LIST%20AND%20DETAIL.md#cash-flow-summary-cash_flow_summary--2026-11-24).

Diupdate 2026-11-24 (lanjutan 5): section **ITEM BY CATEGORY SUMMARY** —
- ~~Baris body dicetak lebih menjorok~~ **DIBATALKAN (2026-11-24, lanjutan 7)**: baris head dan body sekarang dicetak **rata**, awalan sama (`  - `). Pembeda head/body hanya urutan: head di atas, body di bawah. Konsekuensi: menu yang sama bisa tampil dua kali (sebagai head dan sebagai body) tanpa penanda visual.
- **Nama menu dipotong otomatis** (`PrintServices::fitLabel()`) supaya baris muat di lebar printer: nama dipotong dan diakhiri `..`, qty (` (3)`) tetap utuh. Printer sempit (mis. 20 kolom) menyusutkan awalan dulu sebelum memotong nama. Fungsi ini sekarang dipakai di **semua section breakdown pada struk laporan** (Sales Type, Order Source, Category, Staff Sales, Payment Method, Staff Payment, Item By Category) untuk label baris dan label footer (mis. `Total Item By Category`, 22 karakter, sebelumnya sudah melebihi kolom di printer 32); `kirikakan()` sendiri tidak diubah dan tetap tidak memotong, jadi struk lain (bill, payment, checker) tidak terpengaruh. Baris tetap seperti semula (judul section tidak dipotong; printer membungkusnya sendiri).
- Contoh printer 32 kolom: `    - NASI LEMA.. (4)     22.000`. Dites: 96 kombinasi (lebar 20/32/42/48 × awalan × nama panjang × qty) tidak ada yang melebihi lebar; struk dicetak ke listener TCP lokal, printer fisik tidak dites.

Diupdate 2026-11-24 (lanjutan 6): pemotongan nama otomatis (`fitLabel()`) diperluas ke semua section breakdown struk laporan (lihat catatan di atas). Dites: 100 baris pada printer 20/32/42/48 kolom dengan nama staf/metode/kategori sangat panjang tidak ada yang melebihi lebar; struk End Day penuh pada printer 32 kolom juga bersih. Printer fisik tidak dites.

Diupdate 2026-11-24 (lanjutan 7): indentasi body di ITEM BY CATEGORY SUMMARY dibatalkan, diratakan lagi dengan head (permintaan owner). Pengurutan head di atas body, pemotongan nama otomatis (`fitLabel`), dan total tidak berubah. Dites ulang: lebar baris di printer 20/32/42/48 kolom tetap tidak ada yang melebihi lebar; struk dicetak ke listener TCP lokal (printer fisik tidak dites).

Diupdate 2026-11-24 (lanjutan 8): **bug cetak senyap saat POS dibuka lewat IP LAN diperbaiki** (lihat "Akses & keamanan endpoint print"). Direproduksi end-to-end dengan data uji + printer tiruan (listener TCP) dan server uji: sebelum perbaikan, host `127.0.0.1` struk tercetak, host IP LAN **tidak ada struk** (padahal endpoint membalas success); sesudah perbaikan keduanya tercetak. Dites juga: URL self-call untuk IP LAN port 80/8080, nama host, `localhost`, `https`; akses langsung dari IP LAN ke `/internal/print/*` tetap 403 (juga dengan header `X-Forwarded-For`/`Host` palsu). Belum dites di Apache produksi dan printer fisik.
