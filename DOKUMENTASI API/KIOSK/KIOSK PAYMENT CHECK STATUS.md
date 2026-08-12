# Kiosk Payment Check Status

```
GET /api/kiosk/payment/check-status/{order_number}
```

Dipanggil Kiosk buat **polling** (mis. tiap beberapa detik) sambil QR ditampilin ke customer, abis manggil [KIOSK PAYMENT REQUEST.md](./KIOSK%20PAYMENT%20REQUEST.md). Cuma butuh `order_number` — **bukan** `order_id` (Kiosk gak pernah pegang itu, `order_id` bisa beda-beda tiap attempt gara-gara retry, lihat "Retry & cancel" di `KIOSK PAYMENT REQUEST.md`). Server yang cari sendiri attempt terbaru buat order itu.

Logic di `App\Services\PaymentGatewayServices::CheckStatus()`.

## Response

```json
{ "code": 0, "data": { "status": "settlement", "order_number": "NO4TB20260812161503" } }
```

`status` — `pending` / `settlement` / `cancel` / `failed` / `expired`, apa adanya dari `payment_gateway` (Postgres, service `payment`). Kiosk yang mutusin UI-nya:

- `pending` → tetep nunggu, terus polling.
- `settlement` → pembayaran sukses, lanjut ke halaman sukses/struk.
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

1. **Idempotency guard duluan** — cek `tr_order.payment_number` udah keisi belum. Kalau udah, langsung balikin `settlement` **tanpa** ngecek ulang ke Midtrans atau manggil `SavePayment()` lagi (polling berkali-kali gak dobel proses).
2. Kalau belum, ambil attempt terbaru dari `tr_kiosk_payment_request` (`ORDER BY created_at DESC LIMIT 1`), live-check `GET {PAYMENT_GATEWAY_ENDPOINT}/payment-gateway/{order_id}`.
3. Status attempt di-update lokal (`tr_kiosk_payment_request.status`) sesuai hasil live-check.
4. Kalau `settlement` → panggil `PaymentServices::SavePayment()` (yang **sama** dipakai POS) — `payment_detail` di-construct dari `payment_method_id`/`amount` yang udah kesimpen di `tr_kiosk_payment_request` (bukan dari client). Ini yang generate `payment_number`, ubah `tr_order.status` jadi `paid`, insert `tr_order_payment`, dan (karena `order_source='kiosk'`) trigger print kitchen yang sengaja ditunda dari `save-order` (lihat [KIOSK SAVE ORDER.md](./KIOSK%20SAVE%20ORDER.md)).
5. **`tr_order_payment.payment_gateway_order_id`** di-backfill abis `SavePayment()` sukses — `SavePayment()` sendiri (shared logic sama POS) gak tau soal kolom ini, jadi diisi terpisah di sini, nyambungin balik ke attempt spesifik mana yang beneran kebayar.

## Tervalidasi live (2026-08-12)

Order test dibuat → `payment/request` → `check-status` pas masih `pending` → status `payment_gateway` di-update jadi `settlement` (simulasi hasil webhook, langsung di Postgres) → `check-status` dipanggil lagi:

- Balikin `status: settlement`.
- `tr_order.status` jadi `paid`, `payment_number` ke-generate.
- `tr_order_payment` ke-insert (`payment_method_id`/`payment_amount` bener, sesuai yang disimpen `tr_kiosk_payment_request` pas request dibuat) — **termasuk `payment_gateway_order_id`** yang ke-backfill bener (sempet kelewat di percobaan pertama, langsung ketauan & dibenerin).
- `tr_order_detail.done_print` jadi `1` (print kitchen ke-trigger, sebelumnya ketunda dari `save-order`).
- **Idempotency**: `check-status` dipanggil lagi abis `settlement` → tetap balikin `settlement`, `tr_order_payment` **gak nambah baris baru** (masih 1), gak manggil `SavePayment()` ulang.

Data test dibersihin semua abis verifikasi.
