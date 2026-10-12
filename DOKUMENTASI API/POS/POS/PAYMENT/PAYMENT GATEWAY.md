# Payment Gateway

Base path: `/api/payment-gateway`

Jalur **kasir (POS)** untuk payment method QRIS/gateway lain — **reuse service yang sama persis** dengan Kiosk (`PaymentGatewayServices`), dipisah controller cuma supaya penamaan endpoint jelas ini jalur kasir, bukan Kiosk self-service. **Beda penting dari Kiosk**: di sini `amount` **wajib** dikirim client (support split-payment — kasir bisa minta QRIS cuma untuk sisa outstanding, bukan total tagihan penuh).

## Request

```
POST /api/payment-gateway/request
```

**Request:**
```json
{ "order_number": "NOSBE...", "payment_method_id": 5, "amount": 50000 }
```

**Response:**
```json
{ "code": 0, "data": { "order_id": "NOSBE...", "qr_string": "000201...", "qr_url": "https://...", "expired_at": "2026-10-12T14:50:00+07:00" } }
```

- `order_number`/`payment_method_id`/`amount` **semua wajib** — kosong salah satu → `{ "code": 100, "message": "order_number, payment_method_id, and amount are required" }`.
- `amount` **wajib** eksplisit dari client (beda dari Kiosk yang fallback ke `total_billing` kalau tidak dikirim) — validasi: `amount > 0` dan `amount <= total_billing` (**tidak** dicek terhadap sisa outstanding real, backend tidak punya cara independen tahu "sudah kebayar berapa" saat split payment — level trust sama seperti `payment_amount` cash).
- Order `status === 'cancel'` → ditolak (`"order has already been cancelled, cannot request payment again"`) — order `expired` tetap boleh (akan di-reset ke `pending` otomatis saat request baru berhasil).
- Ada attempt `pending` sebelumnya untuk order ini → **otomatis di-cancel** dulu (supaya tidak ada 2 QR aktif bersamaan untuk 1 order).
- Retry (attempt ke-2/3/dst) — `order_id` yang dikirim ke service payment dapat suffix (`{order_number}-2`, dst) — `order_id` adalah PK di sisi service payment, tidak boleh dipakai ulang.
- Gagal minta QR dari provider (Midtrans) → attempt lokal ditandai `failed` (bukan nyangkut sebagai `pending` palsu), error dari provider diteruskan apa adanya.

## Check Status

```
GET /api/payment-gateway/check-status/{order_number}
```

Response: `{ "code": 0, "data": { "status": "pending", "order_number": "NOSBE..." } }`

- Status yang dibalikin: `pending`/`paid`/`cancel`/`failed`/`expired` — **`"settlement"` (istilah internal Midtrans) di-remap jadi `"paid"`** di response, tapi kolom `tr_kiosk_payment_request.status` tetap simpan `"settlement"` apa adanya (audit trail).
- Order sudah punya `payment_number` (dari polling/attempt sebelumnya) → langsung balas `paid` tanpa cek ulang ke provider (idempotency guard — mencegah `SavePayment()` terpanggil dobel).
- **Status `settlement` dari provider → `CheckStatus` OTOMATIS memanggil `PaymentServices::SavePayment()` secara internal** (`confirmPayment()`, `payment_detail` di-construct dari data `tr_kiosk_payment_request` yang tersimpan saat request dibuat, bukan dari client) — **efek samping besar**: memanggil endpoint ini bisa langsung mengubah status order jadi `paid` dan trigger print struk, bukan cuma "cek status" pasif.
- **Fallback expired**: kalau `expired_at` snapshot lokal sudah lewat tapi provider masih bilang `pending` (webhook Midtrans tidak pernah sampai) — QR di-cancel aktif ke provider, status lokal di-set `expired`.
- **Auto-expire order khusus Kiosk**: kalau attempt expired dan `order_source === 'kiosk'`, `tr_order.status` ikut di-set `expired` (customer Kiosk sudah pergi, order dianggap mati). **Order dari POS SENGAJA TIDAK ikut** — kasir masih berdiri di situ, bisa langsung retry, order harus tetap `pending`/`hold` supaya tidak hilang dari tampilan meja.

## Cancel

```
POST /api/payment-gateway/cancel
```

**Request:** `{ "order_number": "NOSBE..." }`

**Response:** `{ "code": 0, "data": {...} }`

- Guard pakai `payment_number !== null` (bukan `status === 'pending'` seperti versi Kiosk) — invariant yang sama dipakai `PaymentServices::SavePayment()` untuk cek "sudah dibayar apa belum", lebih sesuai semantik order POS (order POS tidak selalu `pending` sebelum dibayar, beda dari Kiosk).
- Sudah dibayar → `{ "code": 100, "message": "order has already been paid, cannot cancel payment request" }`.
- Order tidak ketemu → `{ "code": 100, "message": "order not found" }`.

## Alur Implementasi

1. Kasir pilih payment method QRIS di halaman Payment, input `amount` (bisa kurang dari total kalau split payment) → `RequestPayment()` → `POST /payment-gateway/request` → tampilkan QR (`qr_string`/`qr_url`) ke customer.
2. **Polling** `GET /payment-gateway/check-status/{order_number}` secara berkala — **efek samping penting**: kalau sudah `settlement` di provider, panggilan ini **langsung finalize payment** (bukan cuma informasi), jadi FE harus siap handle transisi status di response ini sebagai "pembayaran benar-benar selesai", bukan sekadar polling pasif.
3. Kasir batalkan QR manual (customer berubah pikiran/ganti metode) → `CancelPayment()` → `POST /payment-gateway/cancel`.

## Arsitektur state frontend (penting untuk migrasi — bukan cuma soal endpoint)

State polling QRIS **sengaja TIDAK** disimpan sebagai local state komponen `paymentPage.vue` — dipindah ke Pinia store **global** `gatewayPayment.ts` (`useGatewayPaymentStore`), dengan alasan dan konsekuensi yang harus diperhatikan FE baru:

- **Survive pindah halaman**: polling (`setInterval` tiap 3 detik) jalan di dalam store, bukan di komponen — kasir bisa pindah ke halaman lain (List Table, Order baru) SAMBIL QRIS masih pending, muncul **widget mengambang** (`App.vue`, fixed di pojok kiri bawah, badge warna beda per status) yang bisa diklik untuk restore dialog penuh. Dialog unmount **tidak** menghentikan polling.
- **Survive refresh browser (F5)**: store pakai `persist: { paths: [...] }` (localStorage) — field data (`order_number`, `qr_url`, `status`, dst) selamat lintas refresh, **TAPI** `pollTimer` (id `setInterval`) sengaja **tidak** ikut di-persist (tidak ada artinya di proses browser baru). `App.vue` di `onMounted()` cek `if (gatewayStore.status === 'pending') gatewayStore.startPolling()` — restart polling manual sekali saat app pertama kali dibuka, supaya QRIS yang masih pending sebelum refresh tetap kepoll setelah refresh.
- **Dibatasi 1 transaksi QRIS aktif per terminal** — `open()` menolak request baru kalau `isBusy` (status `requesting`/`pending`) dan `order_number` berbeda dari yang sedang aktif. Ini state per-browser/terminal (Pinia+localStorage lokal), **bukan** cross-tab seperti `OrderListStore` di bawah.
- **Push ke Customer Display**: begitu `requestPayment()` sukses, QR juga didorong ke `OrderListStore` (store Pinia terpisah, persist cross-tab via `localStorage` + event `storage` browser) — `DisplayCustomerPage.vue` (tab/window terpisah) membaca QR dari situ, **bukan** API call baru di sisi Display.
- **Jaring pengaman server-side**: kalau browser benar-benar ditutup (polling frontend mati total), status tetap ter-update lewat background job `kiosk:check-pending-payment` (lihat [`../../INTERNAL/SYSTEM.md`](../../INTERNAL/SYSTEM.md)) — polling frontend ini murni untuk UX real-time selagi app terbuka, bukan satu-satunya mekanisme yang menyelesaikan pembayaran.
- **Cancel dengan race-guard**: `cancel()` tetap panggil `POST /payment-gateway/cancel` ke backend (yang live-check ke provider dulu) — kalau ternyata provider sudah `settled` tepat saat kasir klik Cancel (race), backend sudah `confirmPayment()` duluan, response punya `data.settled === true` dan FE **wajib** treat sebagai `paid`, bukan `cancel`.

## Sumber

- `app/Http/Controllers/PaymentGatewayController.php` — `RequestPayment()`, `CheckStatus()`, `CancelPayment()`.
- `app/Services/PaymentGatewayServices.php` — `RequestPayment()`, `CheckStatus()`, `CancelPendingAttempt()`, `confirmPayment()` (private, reuse `PaymentServices::SavePayment()`).
- Frontend: `src/services/PaymentGatewayServices.ts`, `src/stores/gatewayPayment.ts` (state global+persist+polling), `src/stores/display_customer/orderlist.ts` (push cross-tab ke Display), `src/App.vue` (resume polling on mount, widget mengambang).

## Status

Didokumentasikan 2026-10-12, berdasar pembacaan kode langsung (belum ada verifikasi ulang lewat HTTP request live di sesi ini).
