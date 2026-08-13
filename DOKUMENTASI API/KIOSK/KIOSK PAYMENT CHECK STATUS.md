# Kiosk Payment Check Status

```
GET /api/kiosk/payment/check-status/{order_number}
```

Dipanggil Kiosk buat **polling** (mis. tiap beberapa detik) sambil QR ditampilin ke customer, abis manggil [KIOSK PAYMENT REQUEST.md](./KIOSK%20PAYMENT%20REQUEST.md). Cuma butuh `order_number` — **bukan** `order_id` (Kiosk gak pernah pegang itu, `order_id` bisa beda-beda tiap attempt gara-gara retry, lihat "Retry & cancel" di `KIOSK PAYMENT REQUEST.md`). Server yang cari sendiri attempt terbaru buat order itu.

Logic di `App\Services\PaymentGatewayServices::CheckStatus()`.

## Response

```json
{ "code": 0, "data": { "status": "paid", "order_number": "NO4TB20260812161503" } }
```

`status` — `pending` / `paid` / `cancel` / `failed` / `expired`. **Beda dari status internal gateway** (`payment_gateway`/`tr_kiosk_payment_request`, yang masih pakai istilah Midtrans `settlement`) — sengaja di-remap `settlement` → `paid` di response ini, biar konsisten sama `tr_order.status` (yang emang udah pakai `paid`, bukan `settlement`). Data yang kesimpen di `payment_gateway` (Postgres) dan `tr_kiosk_payment_request.status` **tetap** `settlement` apa adanya, gak ikut berubah — remap cuma di titik response ini.

Kiosk yang mutusin UI-nya:

- `pending` → tetep nunggu, terus polling.
- `paid` → pembayaran sukses, lanjut ke halaman sukses/struk.
- `cancel` / `failed` / `expired` → nampilin "gagal, coba lagi" (Kiosk tinggal manggil ulang `payment/request`, attempt lama otomatis ke-handle, lihat `KIOSK PAYMENT REQUEST.md`).

Order gak ketemu:

```json
{ "code": 100, "message": "order tidak ditemukan" }
```

Belum pernah ada request pembayaran buat order ini (`payment/request` belum pernah dipanggil):

```json
{ "code": 100, "message": "belum pernah ada request pembayaran buat order ini" }
```

## Alur di dalemnya

1. **Idempotency guard duluan** — cek `tr_order.payment_number` udah keisi belum. Kalau udah, langsung balikin `paid` **tanpa** ngecek ulang ke Midtrans atau manggil `SavePayment()` lagi (polling berkali-kali gak dobel proses).
2. Kalau belum, ambil attempt terbaru dari `tr_kiosk_payment_request` (`ORDER BY created_at DESC LIMIT 1`), live-check `GET {PAYMENT_GATEWAY_ENDPOINT}/payment-gateway/{order_id}`.
3. Status attempt di-update lokal (`tr_kiosk_payment_request.status`) sesuai hasil live-check — **apa adanya** dari gateway (`settlement`, bukan `paid`, lihat catatan remap di bagian Response).
4. Kalau `settlement` → panggil `PaymentServices::SavePayment()` (yang **sama** dipakai POS) — `payment_detail` di-construct dari `payment_method_id`/`amount` yang udah kesimpen di `tr_kiosk_payment_request` (bukan dari client). Ini yang generate `payment_number`, ubah `tr_order.status` jadi `paid`, insert `tr_order_payment`, dan (karena `order_source='kiosk'`) trigger print kitchen yang sengaja ditunda dari `save-order` (lihat [KIOSK SAVE ORDER.md](./KIOSK%20SAVE%20ORDER.md)). **Print struk pembayaran** (`PrintServices::PrintPayment()`) di dalam `SavePayment()` **di-skip** kalau `mr_terminal.flag_printer_frontend` order itu `true` (terminal-nya nge-print struk lewat browser, bukan printer server) — print kitchen **tetap jalan** terlepas dari flag ini, dapur tetap butuh tau apa yang harus dimasak. Data struknya bisa diambil lewat [KIOSK PRINT DATA.md](./KIOSK%20PRINT%20DATA.md) (`GET /api/kiosk/print-data/{order_number}`), dipanggil abis `check-status` pertama kali balikin `paid`.
5. **`tr_order_payment.payment_gateway_order_id`** di-backfill abis `SavePayment()` sukses — `SavePayment()` sendiri (shared logic sama POS) gak tau soal kolom ini, jadi diisi terpisah di sini, nyambungin balik ke attempt spesifik mana yang beneran kebayar.
6. Kalau `expired` → `tr_order.status` ikut di-sync jadi `expired` juga (guard `WHERE status = 'pending'`, biar gak nabrak state lain). Sebelum ini, order yang QR-nya kadaluarsa (gak pernah discan) nyangkut `pending` selamanya di `tr_order` meski attempt-nya sendiri udah `expired` — sekarang order-nya ikut kebawa status yang bener.

## Tervalidasi live (2026-08-12)

Order test dibuat → `payment/request` → `check-status` pas masih `pending` → status `payment_gateway` di-update jadi `settlement` (simulasi hasil webhook, langsung di Postgres) → `check-status` dipanggil lagi:

- Balikin `status: settlement`.
- `tr_order.status` jadi `paid`, `payment_number` ke-generate.
- `tr_order_payment` ke-insert (`payment_method_id`/`payment_amount` bener, sesuai yang disimpen `tr_kiosk_payment_request` pas request dibuat) — **termasuk `payment_gateway_order_id`** yang ke-backfill bener (sempet kelewat di percobaan pertama, langsung ketauan & dibenerin).
- `tr_order_detail.done_print` jadi `1` (print kitchen ke-trigger, sebelumnya ketunda dari `save-order`).
- **Idempotency**: `check-status` dipanggil lagi abis `settlement` → tetap balikin `settlement`, `tr_order_payment` **gak nambah baris baru** (masih 1), gak manggil `SavePayment()` ulang.

Data test dibersihin semua abis verifikasi.

## Tervalidasi live (2026-08-12) — remap `paid` & sync `expired`

**Settlement → `paid`**: order test → `payment/request` → status `payment_gateway` di-set `settlement` manual (Postgres) → `check-status` balikin `{"status":"paid",...}` (bukan `settlement`), `tr_order.status` jadi `paid`. Dipanggil lagi (idempotency) → tetap `paid`, `tr_order_payment` masih 1 baris (gak dobel).

**Expired → sync ke `tr_order`**: order test lain → `payment/request` → status `payment_gateway` di-set `expired` manual (Postgres) → `check-status` balikin `{"status":"expired",...}` **dan** `tr_order.status` ikut jadi `expired` (sebelumnya nyangkut `pending`), `tr_kiosk_payment_request.status` juga `expired`.

Ditegasin: query langsung ke `payment_gateway` (Postgres) & `tr_kiosk_payment_request` selama test nunjukin kolom `status`-nya **tetap `settlement`** apa adanya (gak ikut ke-remap jadi `paid`) — remap murni di response `check-status` doang.
