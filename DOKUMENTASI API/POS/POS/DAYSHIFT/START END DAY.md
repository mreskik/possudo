# Start Day / End Shift / End Day / Current Day

**⚠️ Bug response code**: `CurrentDay()`, `StartDay()`, `Endshift()` (3 dari 14 endpoint di folder ini) punya `catch` block yang balikin `"code" => 0` **bahkan saat terjadi error** — error akan terlihat seperti sukses dengan `message` berisi teks error. **Wajib ditangani benar di FE baru** — jangan percaya `code === 0` tanpa cek lebih lanjut untuk 3 endpoint ini.

## Current Day

```
GET /api/dayshift/get
```

Dayshift yang **sedang aktif** (`dayout_time IS NULL`), atau `null` kalau tidak ada yang aktif.

Response: `{ "code": 0, "data": { "ulid": "POSSBE20261012080000", "branch_id": 51, "dayin_time": "2026-10-12T08:00:00+07:00", "dayin_total": "500000.00", "dayout_time": null } }`

## Start Day

```
POST /api/dayshift/start
```

**Request:** `{ "start_cash": "500000.00" }`

**Response:** `{ "code": 0, "message": "success" }` (lihat catatan bug code di atas)

- **Proteksi race condition**: `BranchModel::lockForUpdate()` dalam transaction — mencegah 2 request `StartDay` bersamaan menghasilkan 2 dayshift aktif sekaligus (MySQL tidak dukung partial unique index seperti Postgres `WHERE dayout_time IS NULL`).
- Dayshift lain masih aktif (`dayout_time IS NULL`) → `"cannot start day because end day has not been done yet!"`.
- **Guard jam komputer mundur**: `start_cash` sekarang dibandingkan dengan `dayin_time` dayshift **terakhir** (apapun statusnya, sudah di-end-day atau belum) — kalau waktu sekarang lebih awal → `"computer time is incorrect..."`. Ini mencegah `ulid` dayshift baru (yang komposisinya pakai timestamp) merusak urutan `ORDER BY ulid` yang diasumsikan selalu naik.
- `ulid` dayshift — komposisi `POS{branch_code}{timestamp}`, per-branch (bukan per-terminal, beda dari `order_number`).

## End Shift

```
GET /api/dayshift/end-shift/{dayshift_ulid}
```

Tutup **1 shift** dalam dayshift (misal pergantian kasir di tengah hari) — bukan tutup hari penuh, lihat [`End Day`](#end-day) untuk itu.

**Response:** `{ "code": 0, "message": "success" }` (lihat catatan bug code di atas)

- Membuat baris baru `DayShiftDetailModel` (`shift_number` auto-increment per-dayshift), menandai titik waktu shift tsb.
- **Catatan pesan error membingungkan**: kondisi `dayout_time != null` (dayshift ini sudah pernah di-End Day) membalikkan pesan `"cannot end shift because start day has not been done yet!"` — pesannya **tidak cocok** dengan kondisi sebenarnya (seharusnya lebih ke arah "dayshift ini sudah ditutup", bukan "start day belum dilakukan"). Perlu diperbaiki kalimatnya saat migrasi, jangan di-port apa adanya.

## End Day

```
POST /api/dayshift/end-day
```

**Request:** `{ "dayshift_ulid": "POSSBE...", "aktual_ending_cash": "480000.00", "notes": "Selisih kas -20rb" }`

**Response:** `{ "code": 0, "message": "end day success!" }` — **SELALU sukses** kalau step SAVE (lihat di bawah) berhasil, walau push/jurnal ke ERP gagal di background.

**Arsitektur 4 tahap** (disusun ulang 2026-09-24, dirancang supaya kegagalan push TIDAK membatalkan penutupan hari yang sudah tersimpan lokal):

1. **VALIDASI** — dayshift harus ada dan `dayout_time` masih `null` (belum pernah di-End Day) → `"start day has never been done!"` / `"end day has already been done!"`.
2. **SAVE** — transaksi database **terpisah dan sempit**, cuma `UPDATE tr_dayshift` (`dayout_time`/`dayout_total`/`dayout_notes`), **commit duluan** sebelum menyentuh push/jurnal sama sekali. `sync_at` di-reset `null` supaya baris ini ikut ke-push ulang (sudah pernah ke-push saat Start Day).
3. **PUSH** — 6 fungsi push ke ERP (dayshift → dayshift_detail → order → order_detail → order_detail_package → order_payment, urutan wajib) dalam `try/catch` **lokal** — gagal **TIDAK** melempar exception ke luar, cuma di-log. Request client tetap sukses walau tahap ini gagal — otomatis di-retry oleh command `sync:push` (lihat [`../../INTERNAL/PUSH DATA.md`](../../INTERNAL/PUSH%20DATA.md)).
4. **JURNAL** — **HANYA** dipanggil kalau tahap PUSH semuanya sukses (endpoint jurnal ERP akan menolak kalau data `pos_dayshift`/`pos_order` belum ada di sana). Gagal → dicatat log, **tidak** melempar exception — bisa di-retry manual dari modul Dayshift Jurnal di ERP.

**Efek samping**: otomatis memanggil `PrintServices::PrintEndDay()` (cetak laporan tutup hari) setelah SAVE berhasil, sebelum PUSH/JURNAL.

**Timeout khusus di frontend**: `API.post(..., { timeout: 60000 })` — lebih tinggi dari default (20 detik), karena worst-case tahap PUSH bisa makan ~35 detik (6 fungsi, masing-masing timeout 5+30 detik).

## Alur Implementasi

1. Dashboard load → `CurrentDay()` dicek via `globalstore.js`. Tidak ada dayshift aktif → redirect paksa ke halaman Start Day (`router/index.js`, guard `kesiapan()`).
2. Kasir input `start_cash` → `StartDay()` → masuk Dashboard.
3. Sepanjang hari, bisa `EndShift()` berkali-kali (pergantian kasir) — ini **tidak** menutup dayshift, cuma mencatat titik waktu shift.
4. Akhir hari → `EndDay()` dengan `aktual_ending_cash` (hasil hitung kas fisik) — **wajib** `dayshift_ulid` dari dayshift yang sedang aktif.

## Sumber

- `app/Http/Controllers/DayShiftController.php` — `CurrentDay()`, `StartDay()`, `Endshift()`, `EndDay()`.
- `app/Services/DayShiftServices.php` — `GetDayShift()`, `StartDay()`, `EndShift()`, `EndDay()`.
- Frontend: `src/services/DayshiftServices.ts`, `src/modules/dashboard/daystart/DayStartPage.vue`, `src/router/index.js` (guard `kesiapan`).

## Status

Didokumentasikan 2026-10-12, berdasar pembacaan kode langsung (belum ada verifikasi ulang lewat HTTP request live di sesi ini).
