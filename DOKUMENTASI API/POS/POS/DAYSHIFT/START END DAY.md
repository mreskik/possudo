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

**Guard pending order (baru, 2026-11-17)** — dicek **PALING AWAL** di `DayShiftController::EndDay()`, SEBELUM memanggil `DayShiftServices::EndDay()` sama sekali (jadi tahap VALIDASI/SAVE/PUSH/JURNAL di bawah tidak akan jalan kalau guard ini gagal). Query-nya diadopsi langsung dari [`GET /api/dayshift/pending-order`](#pending-order) (`TrOrderModel::where('status', 'pending')->get()`) — **status `pending` GLOBAL, TIDAK difilter per `dayshift_ulid`**, sama persis behavior endpoint itu. **Status `hold` SENGAJA tidak ikut diblokir** (2026-11-24, dikonfirmasi) — order hold memang dirancang boleh menginap lintas dayshift; konsekuensinya laporan dayshift/shift memakai `order_out` (paid) / `cancel_at` / `void_at` (bukan `dayshift_ulid`) sebagai penentu masuk window mana — lihat [`LIST AND DETAIL.md`](LIST%20AND%20DETAIL.md#sales-summary-scope--rumus-2026-11-24).

Kalau ada order pending:
```json
{
  "code": 100,
  "message": "cannot end day because there are still pending orders, please complete or cancel them first",
  "data": [
    {
      "order_number": "NO312320261112091053",
      "payment_number": null,
      "dayshift_ulid": "POS12320261112090727",
      "branch_id": 27,
      "terminal_id": 3,
      "order_name": "asd",
      "customer_phone_number": null,
      "order_source": "pos",
      "order_type": "takeaway",
      "table_section_id": 18,
      "table_id": 0,
      "total_batch": 1,
      "order_date": "2026-11-12",
      "order_queue": 1,
      "order_in": "2026-11-12 09:10:53",
      "order_out": null,
      "member_id": null,
      "visit_purpose_id": 1,
      "pax": 1,
      "status": "pending",
      "waiter_name": "Muhammad Al Fathan",
      "sender_name": "JUSE",
      "chasier_name": "Muhammad Al Fathan",
      "delivery_cost": "0.00",
      "order_fee": "0.00",
      "total_service_charge": "0.00",
      "total_item": 1,
      "sub_total": "25000.00",
      "total_discount": "0.00",
      "total_tax": "2272.73",
      "grand_total": "25000.00",
      "rounding_amount": "0.00",
      "total_billing": "25000.00",
      "rounding_amount": "0.00",
      "flag_inclusive_tax": true,
      "cancel_notes": "",
      "cancel_at": null,
      "cancel_by": null,
      "cancel_print_ke": 0,
      "void_notes": "",
      "void_at": null,
      "void_by": null,
      "void_print_ke": 0,
      "payment_at": null,
      "payment_by": null,
      "print_ke": 0,
      "payment_notes": "",
      "moved_at": null,
      "moved_by": null,
      "created_at": "2026-11-12T02:10:53.000000Z",
      "updated_at": "2026-11-12T03:48:29.000000Z",
      "created_by": null,
      "updated_by": null,
      "sync_at": "2026-11-12 10:48:30"
    }
  ]
}
```

(1 object di atas = 1 baris `tr_order` apa adanya, SEMUA kolom model ikut, tidak ada whitelist field — kalau ada beberapa order pending, `data` berisi array dengan banyak object seperti ini.)

`data` isinya **daftar order pending itu sendiri** (bukan cuma pesan teks) — struktur 1 baris identik response [`pending-order`](#pending-order), biar FE bisa langsung tampilkan daftar order yang perlu diselesaikan/dibatalkan dulu ke kasir, tanpa perlu hit endpoint `pending-order` secara terpisah.

**Tervalidasi live (2026-11-17)**: dipanggil dengan `dayshift_ulid` dummy yang pasti invalid (`"DUMMY"`) — guard pending order menolak request SEBELUM sempat mengevaluasi `dayshift_ulid` sama sekali (tidak menghasilkan error `"start day has never been done!"` dari tahap VALIDASI di bawah, melainkan `code: 100` dari guard ini duluan), membuktikan urutan eksekusinya benar-benar paling awal.

**Arsitektur 4 tahap** (disusun ulang 2026-09-24, dirancang supaya kegagalan push TIDAK membatalkan penutupan hari yang sudah tersimpan lokal — guard pending order di atas berjalan SEBELUM tahap 1 ini):

1. **VALIDASI** — dayshift harus ada dan `dayout_time` masih `null` (belum pernah di-End Day) → `"start day has never been done!"` / `"end day has already been done!"`.
2. **SAVE** — transaksi database **terpisah dan sempit**, cuma `UPDATE tr_dayshift` (`dayout_time`/`dayout_total`/`dayout_notes`), **commit duluan** sebelum menyentuh push/jurnal sama sekali. `sync_at` di-reset `null` supaya baris ini ikut ke-push ulang (sudah pernah ke-push saat Start Day).
3. **PUSH** — 6 fungsi push ke ERP (dayshift → dayshift_detail → order → order_detail → order_detail_package → order_payment, urutan wajib) dalam `try/catch` **lokal** — gagal **TIDAK** melempar exception ke luar, cuma di-log. Request client tetap sukses walau tahap ini gagal — otomatis di-retry oleh command `sync:push` (lihat [`../../INTERNAL/PUSH DATA.md`](../../INTERNAL/PUSH%20DATA.md)).
4. **JURNAL** — **HANYA** dipanggil kalau tahap PUSH semuanya sukses (endpoint jurnal ERP akan menolak kalau data `pos_dayshift`/`pos_order` belum ada di sana). Gagal → dicatat log, **tidak** melempar exception — bisa di-retry manual dari modul Dayshift Jurnal di ERP.

**Efek samping**: otomatis memanggil `PrintServices::PrintEndDay()` (cetak laporan tutup hari) setelah SAVE berhasil, sebelum PUSH/JURNAL.

**Timeout khusus di frontend**: `API.post(..., { timeout: 60000 })` — lebih tinggi dari default (20 detik), karena worst-case tahap PUSH bisa makan ~35 detik (6 fungsi, masing-masing timeout 5+30 detik).

## Pending Order

```
GET /api/dayshift/pending-order
```

Daftar **semua** order dengan `status = 'pending'` — **GLOBAL, TIDAK difilter** per `dayshift_ulid`/branch/terminal (`TrOrderModel::where('status', 'pending')->get()`, tanpa `WHERE` tambahan apapun).

**Response:** sama persis struktur `data` dari guard pending order di [`End Day`](#end-day) di atas (lihat contoh JSON lengkap di situ) — `data` adalah array baris `tr_order` apa adanya (semua kolom model, tidak ada transformasi/whitelist field), bedanya endpoint ini selalu `code: 0` (bukan `100`), karena memang bukan guard penolakan, cuma daftar informasi biasa.

**Dipakai juga sebagai guard di [`End Day`](#end-day)** (2026-11-17) — query yang sama di-reuse langsung di awal `DayShiftController::EndDay()` untuk menolak penutupan hari kalau masih ada order pending, lihat bagian "Guard pending order" di section End Day di atas.

## Alur Implementasi

1. Dashboard load → `CurrentDay()` dicek via `globalstore.js`. Tidak ada dayshift aktif → redirect paksa ke halaman Start Day (`router/index.js`, guard `kesiapan()`).
2. Kasir input `start_cash` → `StartDay()` → masuk Dashboard.
3. Sepanjang hari, bisa `EndShift()` berkali-kali (pergantian kasir) — ini **tidak** menutup dayshift, cuma mencatat titik waktu shift.
4. Akhir hari → `EndDay()` dengan `aktual_ending_cash` (hasil hitung kas fisik) — **wajib** `dayshift_ulid` dari dayshift yang sedang aktif, dan **tidak boleh ada order pending** (lihat guard di section End Day).

## Sumber

- `app/Http/Controllers/DayShiftController.php` — `CurrentDay()`, `StartDay()`, `Endshift()`, `EndDay()` (guard pending order baru, 2026-11-17), `pendingOrder()`.
- `app/Services/DayShiftServices.php` — `GetDayShift()`, `StartDay()`, `EndShift()`, `EndDay()`.
- `app/Models/TrOrderModel.php` — dipakai langsung di controller (bukan lewat Service) buat guard pending order maupun endpoint `pendingOrder()` itu sendiri.
- Frontend: `src/services/DayshiftServices.ts`, `src/modules/dashboard/daystart/DayStartPage.vue`, `src/router/index.js` (guard `kesiapan`).

## Status

Didokumentasikan 2026-10-12, berdasar pembacaan kode langsung (belum ada verifikasi ulang lewat HTTP request live di sesi ini).
