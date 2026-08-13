# Kiosk Payment Request

```
POST /api/kiosk/payment/request
```

Minta QR/pembayaran ke payment gateway (proxy ke service Go `payment` yang terpisah dari APIANDORDER, folder `dev/payment/`, port default `98` → Midtrans) buat 1 order. Logic di `App\Services\PaymentGatewayServices::RequestPayment()`.

> Service `payment` ini standalone (bukan bagian dari APIANDORDER lagi), pakai DB Postgres yang sama (`db_sudocore_dev`). Endpoint-nya diatur lewat env `PAYMENT_GATEWAY_ENDPOINT` (default `http://localhost:98`), terpisah dari `SERVER_ENDPOINT` (APIANDORDER, port `99`).

## Request

```json
{
  "order_number": "NO4TB20260811104824",
  "payment_method_id": 1
}
```

- `order_number` — dari [KIOSK SAVE ORDER.md](./KIOSK%20SAVE%20ORDER.md).
- `payment_method_id` — dari [KIOSK PAYMENT METHOD.md](./KIOSK%20PAYMENT%20METHOD.md) (`id`, bukan `code`).

## Alur validasi

1. `payment_method_id` dicek ke `mr_payment_method` — kalau gak ketemu → error.
2. **`payment_gateway_code` wajib keisi** (bukan `NULL`/string kosong `''`) — sama persis filter yang dipakai [KIOSK PAYMENT METHOD.md](./KIOSK%20PAYMENT%20METHOD.md), jadi harusnya gak akan gagal di sini kalau frontend ambil `payment_method_id` dari situ. Kalau gagal → `"payment method tidak didukung"`.
3. `order_number` dicek ke `tr_order` — kalau gak ketemu → error. **`amount` diambil dari `tr_order.total_billing` di server**, **bukan** dari client — biar gak bisa dimanipulasi dari frontend.
4. **Cek attempt lama** — kalau `order_number` ini masih punya baris `tr_kiosk_payment_request` berstatus `pending` (request payment sebelumnya buat order yang sama, mis. QR expired/customer minta ulang), attempt lama itu **di-cancel dulu** (lihat bagian "Retry & cancel" di bawah) sebelum lanjut bikin attempt baru.
5. **`order_id`** yang dikirim ke service `payment` — attempt pertama = `order_number` apa adanya, attempt ke-2/3/dst kena suffix (`{order_number}-2`, `{order_number}-3`, dst) karena `order_id` itu **primary key** di `payment_gateway` (Postgres), gak boleh dipakai ulang.
6. `branch_id` diambil dari `mr_branch` lokal (branch pertama) — **`company_id` gak dikirim lagi** (lihat bagian "company_id" di bawah).
7. Insert baris baru ke `tr_kiosk_payment_request` (`status: pending`) **sebelum** manggil service `payment` — kalau ternyata gagal, baris ini di-update jadi `status: failed` (bukan dibiarin nyangkut `pending` palsu).
8. Proxy `POST {PAYMENT_GATEWAY_ENDPOINT}/payment-gateway/qris` — response dari service `payment` diteruskan **apa adanya** ke client (udah `snake_case` dari sononya, gak perlu reshape). `order_id` di response itu `order_id` attempt ini (bisa beda dari `order_number` kalau ini retry).

Catatan: baru `MIDTRANS_QRIS` yang ada endpoint-nya di service `payment` (`/payment-gateway/qris`) — kalau nanti ada `payment_gateway_code` lain (provider/channel beda), butuh routing tambahan di `PaymentGatewayServices::RequestPayment()` buat manggil endpoint yang sesuai.

## Retry & cancel (tabel `tr_kiosk_payment_request`)

Tabel lokal baru (`tr_kiosk_payment_request`, PK `order_id`) nyatet tiap **attempt** request pembayaran per `order_number` — perlu karena pembayaran gak otomatis nyatet ke `tr_order_payment` (nunggu konfirmasi settlement dulu lewat [KIOSK PAYMENT CHECK STATUS.md](./KIOSK%20PAYMENT%20CHECK%20STATUS.md)), dan `order_id` gak boleh dipakai ulang di `payment_gateway`.

| Kolom | Isi |
| --- | --- |
| `order_id` (PK) | ID yang dikirim ke service `payment` |
| `order_number` | order yang mana punya attempt ini |
| `payment_method_id` | disimpen di sini, dipakai lagi nanti pas confirm ke `PaymentServices::SavePayment()` |
| `amount` | snapshot nominal pas request dibuat |
| `status` | `pending` / `settlement` / `cancel` / `failed` / `expired` |
| `expired_at` | snapshot `expired_at` dari Midtrans pas request sukses — dipakai buat `payment_expired_at` di [KIOSK ORDER HISTORY.md](./KIOSK%20ORDER%20HISTORY.md)/[KIOSK ORDER DETAIL.md](./KIOSK%20ORDER%20DETAIL.md) |

**Alur retry**: kalau ada request baru masuk buat `order_number` yang masih punya attempt `pending`:
1. `PaymentGatewayServices::cancelAttempt()` — `POST {PAYMENT_GATEWAY_ENDPOINT}/payment-gateway/{order_id_lama}/cancel` (endpoint baru di service `payment`, wrapper `coreapi.Client.CancelTransaction()` Midtrans), baris lama di-update `status: cancel`.
2. Gagal cancel ke Midtrans (mis. race sama settlement, network error) **sengaja gak dianggap fatal** — tetap lanjut bikin attempt baru, biar customer gak keblokir. Kalau attempt lama itu ternyata beneran udah `settlement` pas mau di-cancel, itu ketahuan/ditangani belakangan di [KIOSK PAYMENT CHECK STATUS.md](./KIOSK%20PAYMENT%20CHECK%20STATUS.md), bukan di titik ini.
3. Attempt baru dibuat dengan `order_id` baru (suffix `-2`, `-3`, dst).

## `company_id` di-resolve server-side (bukan dikirim dari POS)

Sebelumnya Laravel ngirim `company_id` (dari `mr_branch` lokal) ke service `payment`, dan service-nya validasi `company_id == 0` berarti "belum diisi". Ini keliru buat company yang ID aslinya emang `0`, dan lagian `company_id` lokal POS bisa aja basi. **Dibenerin**: Laravel sekarang cuma kirim `branch_id`, service `payment` (`CreateQrisPayment`) resolve `company_id` sendiri lewat `SELECT company_id FROM master_branch WHERE id = ?` (connect langsung ke Postgres yang sama kayak sudocore2). Validasi wajibnya pindah ke `branch_id` (gak ada branch_id valid yang nilainya `0`, jadi aman dijadiin penentu "diisi/enggak").

`vendor_qr_url` (link gambar QR dari Midtrans, ada border/branding mereka) dan `vendor_qr_string` (raw EMV QRIS payload, buat digambar sendiri kalau perlu QR polos) diteruskan apa adanya — Kiosk yang milih mau pakai yang mana buat ditampilin. **Sengaja gak ada gambar base64 di-generate di server** buat sekarang (dicoba sekali, dibalikin lagi — biar minimal dulu).

## Response

Sukses:

```json
{
  "code": 0,
  "data": {
    "order_id": "NO4TB20260811104824",
    "payment_gateway_code": "MIDTRANS_QRIS",
    "provider": "midtrans",
    "channel": "qris",
    "amount": "14500.00",
    "status": "pending",
    "vendor_qr_string": "00020101021226620014COM.GO-JEK.WWW...",
    "vendor_qr_url": "https://merchants-app.sbx.midtrans.com/v4/qris/gopay/.../qr-code",
    "vendor_va": null,
    "expired_at": "2026-08-11T11:03:33+07:00",
    "settlement_at": null
  }
}
```

`order_number`/`payment_method_id` gak dikirim:

```json
{ "code": 100, "message": "order_number dan payment_method_id wajib diisi" }
```

Payment method gak punya gateway code:

```json
{ "code": 100, "message": "payment method tidak didukung" }
```

Order gak ketemu:

```json
{ "code": 100, "message": "order tidak ditemukan" }
```

## Cara dapetin status pembayaran

Endpoint polling status **dipisah sendiri**, lihat [KIOSK PAYMENT CHECK STATUS.md](./KIOSK%20PAYMENT%20CHECK%20STATUS.md) (`GET /api/kiosk/payment/check-status/{order_number}`) — di situ juga yang trigger `PaymentServices::SavePayment()` kalau statusnya `settlement`.

## Tervalidasi live (2026-08-11)

End-to-end pakai order beneran + Midtrans sandbox asli, lewat service `payment` yang baru dipisah dari APIANDORDER: payment method tanpa gateway code → ditolak. Order dibuat via `save-order` → `payment/request` sukses, QR asli ke-generate, `order_id` = `order_number`, `amount` cocok sama `total_billing` order, data kesimpen bener di `payment_gateway` (Postgres) dengan `branch_id`/`company_id` yang bener. Data test udah dibersihin.

## Tervalidasi live (2026-08-12) — retry & cancel

Order test dibuat, `payment/request` dipanggil 3x berturut-turut buat `order_number` yang sama:
- Attempt 1 sempat gagal (nemuin & langsung dibenerin bug `company_id` di atas) → `tr_kiosk_payment_request` kesimpen `status: failed`, gak nyangkut `pending`.
- Attempt 2 sukses, `order_id = {order_number}-2` (bukan `order_number` polos, karena attempt 1 udah kepake meski gagal).
- Attempt 3 sukses, `order_id = {order_number}-3` — dan attempt 2 kekonfirmasi **beneran ke-cancel** (dicek langsung `GET /payment-gateway/{order_id}` ke service `payment`, `status: cancel`, bukan cuma lokal yang bilang gitu).

Data test (Postgres `payment_gateway`/`payment_gateway_webhook_logs`, MySQL `tr_kiosk_payment_request`/`tr_order`/`tr_order_detail`, `mr_payment_method.payment_gateway_code`) udah dibersihin semua abis verifikasi.

### Riwayat: ekstraksi jadi service terpisah (2026-08-11)

Modul payment gateway awalnya nempel di APIANDORDER, dipindah jadi Go service standalone sendiri di `dev/payment/` (module `payment`, port `98`) — struktur project (`backend/config`, `backend/helpers`, `backend/pg_provider/midtrans`, `backend/modules/paymentgateway`) di-mirror dari APIANDORDER, DB Postgres tetap sama (`db_sudocore_dev`). APIANDORDER udah gak punya route `/payment-gateway/*` lagi (dicek: 404). Laravel manggil lewat `PAYMENT_GATEWAY_ENDPOINT` (env baru), terpisah dari `SERVER_ENDPOINT` yang tetap dipakai buat sync push/pull data lain ke APIANDORDER.

Sekalian ada rename kolom di tabel `payment_gateway`/`payment_gateway_webhook_logs`: `reference_number`→`order_id`, `gateway_code`→`payment_gateway_code`, `paid_at`→`settlement_at`, status value `paid`→`settlement`, tambah kolom `branch_id` (nullable).

### Riwayat (sempat dicoba, di-revert)

Sempat ditambahin field `qr_image_base64` (generate QR polos lokal pakai `endroid/qr-code`, dari `vendor_qr_string`) — **di-revert lagi**, keputusan buat minimal dulu, cukup `vendor_qr_string`/`vendor_qr_url` doang. Package `endroid/qr-code` udah di-`composer remove` lagi. Kalau nanti dibutuhin lagi: 2 hal yang perlu diinget dari percobaan ini —
1. `vendor_qr_url` Midtrans itu ada border/branding-nya, bukan QR polos — makanya sempat generate sendiri dari `vendor_qr_string`.
2. Kalau generate sendiri, **margin/quiet zone jangan di-set 0** — sempat salah set `margin: 0`, padahal `ISO/IEC 18004` mewajibkan quiet zone minimal 4 module di sekeliling QR biar reliable ke-scan. `margin: 0` cuma buang border/branding Midtrans yang gak perlu, bukan berarti quiet zone-nya juga boleh dihilangin.
