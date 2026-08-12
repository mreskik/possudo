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
4. `order_id` yang dikirim ke service `payment` = `order_number` **apa adanya** (gak digenerate ulang) — biar gampang di-lacak balik.
5. `branch_id`/`company_id` diambil dari `mr_branch` lokal (branch pertama).
6. Proxy `POST {PAYMENT_GATEWAY_ENDPOINT}/payment-gateway/qris` — response dari service `payment` diteruskan **apa adanya** ke client (udah `snake_case` dari sononya, gak perlu reshape).

Catatan: baru `MIDTRANS_QRIS` yang ada endpoint-nya di service `payment` (`/payment-gateway/qris`) — kalau nanti ada `payment_gateway_code` lain (provider/channel beda), butuh routing tambahan di `PaymentGatewayServices::RequestPayment()` buat manggil endpoint yang sesuai.

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

## Cara dapetin status pembayaran (belum ada endpoint-nya)

Setelah `vendor_qr_url`/`vendor_qr_string` ditampilin ke customer, Kiosk perlu tau kapan pembayaran sukses — ini butuh endpoint **polling status** terpisah (belum dibikin) yang proxy ke `GET {PAYMENT_GATEWAY_ENDPOINT}/payment-gateway/{order_id}` di service `payment`, dan kalau statusnya `settlement`, langsung manggil `PaymentServices::SavePayment()` yang udah ada (nyatet `tr_order_payment`, ubah status order jadi `paid`). **Ini yang bikin alur Kiosk belum bisa dipakai beneran** — tanpa ini, print kitchen (yang sengaja ditunda sampai payment sukses, lihat [KIOSK SAVE ORDER.md](./KIOSK%20SAVE%20ORDER.md)) gak akan pernah jalan walau customer udah bayar. Prioritas berikutnya.

## Tervalidasi live (2026-08-11)

End-to-end pakai order beneran + Midtrans sandbox asli, lewat service `payment` yang baru dipisah dari APIANDORDER: payment method tanpa gateway code → ditolak. Order dibuat via `save-order` → `payment/request` sukses, QR asli ke-generate, `order_id` = `order_number`, `amount` cocok sama `total_billing` order, data kesimpen bener di `payment_gateway` (Postgres) dengan `branch_id`/`company_id` yang bener. Data test udah dibersihin.

### Riwayat: ekstraksi jadi service terpisah (2026-08-11)

Modul payment gateway awalnya nempel di APIANDORDER, dipindah jadi Go service standalone sendiri di `dev/payment/` (module `payment`, port `98`) — struktur project (`backend/config`, `backend/helpers`, `backend/pg_provider/midtrans`, `backend/modules/paymentgateway`) di-mirror dari APIANDORDER, DB Postgres tetap sama (`db_sudocore_dev`). APIANDORDER udah gak punya route `/payment-gateway/*` lagi (dicek: 404). Laravel manggil lewat `PAYMENT_GATEWAY_ENDPOINT` (env baru), terpisah dari `SERVER_ENDPOINT` yang tetap dipakai buat sync push/pull data lain ke APIANDORDER.

Sekalian ada rename kolom di tabel `payment_gateway`/`payment_gateway_webhook_logs`: `reference_number`→`order_id`, `gateway_code`→`payment_gateway_code`, `paid_at`→`settlement_at`, status value `paid`→`settlement`, tambah kolom `branch_id` (nullable).

### Riwayat (sempat dicoba, di-revert)

Sempat ditambahin field `qr_image_base64` (generate QR polos lokal pakai `endroid/qr-code`, dari `vendor_qr_string`) — **di-revert lagi**, keputusan buat minimal dulu, cukup `vendor_qr_string`/`vendor_qr_url` doang. Package `endroid/qr-code` udah di-`composer remove` lagi. Kalau nanti dibutuhin lagi: 2 hal yang perlu diinget dari percobaan ini —
1. `vendor_qr_url` Midtrans itu ada border/branding-nya, bukan QR polos — makanya sempat generate sendiri dari `vendor_qr_string`.
2. Kalau generate sendiri, **margin/quiet zone jangan di-set 0** — sempat salah set `margin: 0`, padahal `ISO/IEC 18004` mewajibkan quiet zone minimal 4 module di sekeliling QR biar reliable ke-scan. `margin: 0` cuma buang border/branding Midtrans yang gak perlu, bukan berarti quiet zone-nya juga boleh dihilangin.
