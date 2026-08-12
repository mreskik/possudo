# Kiosk Cancel Order

```
POST /api/kiosk/cancel-order
```

Batalin order kiosk **sebelum bayar**. Logic di `KioskController::CancelOrder()`, reuse `App\Services\OrderServices::CancelOrder()` yang sama dipakai POS.

## Request

```json
{
  "order_number": "NO4TB20260812164317",
  "notes": "customer batal"
}
```

- `order_number` — wajib.
- `notes` — optional, disimpen ke `tr_order.cancel_notes`. Default string kosong kalau gak dikirim.

## Alur

1. **Cancel attempt payment pending (kalau ada)** — kalau order ini masih punya baris `tr_kiosk_payment_request` berstatus `pending` (customer sempat minta QR lewat [KIOSK PAYMENT REQUEST.md](./KIOSK%20PAYMENT%20REQUEST.md) tapi belum scan/bayar), attempt itu di-cancel dulu ke Midtrans (`PaymentGatewayServices::CancelPendingAttempt()`, reuse logic yang sama kayak retry — lihat "Retry & cancel" di situ). Gak ada attempt pending → no-op, lanjut aja.
2. **`OrderServices::CancelOrder()`** — cuma jalan kalau `tr_order.status` masih `pending` atau `hold`. Order yang udah `paid` (settlement lewat [KIOSK PAYMENT CHECK STATUS.md](./KIOSK%20PAYMENT%20CHECK%20STATUS.md)) otomatis **ketolak** di titik ini — endpoint ini bukan buat refund/void order yang udah kebayar.
3. Sukses → `tr_order.status = 'cancel'`, `cancel_at`/`cancel_notes` keisi.

## Response

Sukses:

```json
{ "code": 0, "message": "cancel order success!" }
```

`order_number` gak dikirim:

```json
{ "code": 100, "message": "order_number wajib diisi" }
```

Order udah bukan `pending`/`hold` (udah `paid`/udah pernah di-cancel/dll):

```json
{ "code": 100, "message": "bukan order pending / hold!" }
```

## Tervalidasi live (2026-08-12)

- Order `pending` tanpa attempt payment → cancel sukses, `tr_order.status` jadi `cancel`, `cancel_notes` kesimpen bener.
- Cancel order yang sama 2x (kedua kalinya) → ketolak `"bukan order pending / hold!"`, gak dobel proses.
- `order_number` gak ketemu → ketolak error yang sama (guard status jalan duluan sebelum cek exist, tapi hasil akhirnya konsisten aman).
- `order_number` gak dikirim → `"order_number wajib diisi"`.
- **Order dengan attempt payment `pending`**: `payment/request` dipanggil dulu (`order_id` attempt = `{order_number}-2`) → `cancel-order` dipanggil → dicek `GET /payment-gateway/{order_id}` langsung ke service `payment` (bukan cuma status lokal), hasilnya beneran `status: cancel` di Midtrans. `tr_kiosk_payment_request` juga ke-update `status: cancel` buat attempt itu.
