# Kiosk Order History

```
GET /api/kiosk/order-history
```

List **header** order kiosk (`order_source = 'kiosk'`), filter by tanggal (`order_date`). Query langsung ke `tr_order` + `LEFT JOIN` ke `mr_member`/`tr_order_payment`/`mr_payment_method`.

**Belum termasuk list item** per-order — baru header, sama kayak [KIOSK ORDER DETAIL.md](./KIOSK%20ORDER%20DETAIL.md). Detail per-order nyusul endpoint terpisah kalau dibutuhin.

## Request

Query param (semua optional):

```
GET /api/kiosk/order-history?date_from=2026-08-01&date_to=2026-08-12&terminal_id=4
```

- `date_from` — format `Y-m-d`. Default: **hari ini** kalau gak dikirim.
- `date_to` — format `Y-m-d`. Default: **hari ini** kalau gak dikirim.
- `terminal_id` — optional, gak difilter sama sekali kalau gak dikirim (semua terminal di branch ini).

Filter-nya ke kolom `tr_order.order_date` (tanggal transaksi), **bukan** `created_at`.

## Response

```json
{
  "code": 0,
  "data": [
    {
      "order_number": "NO4TB20260812161227",
      "payment_number": null,
      "status": "pending",
      "order_date": "2026-08-12",
      "order_name": "asasd",
      "total_billing": "36630.00",
      "total_item": 1,
      "customer_phone_number": "08121314423",
      "member_name": "bagus",
      "payment_method": null
    }
  ]
}
```

Gak ada order yang match → `data: []` (bukan error).

- `payment_number` — `null` selama order belum `paid` (belum ada `SavePayment()` yang jalan, lihat [KIOSK PAYMENT CHECK STATUS.md](./KIOSK%20PAYMENT%20CHECK%20STATUS.md)).
- `status` — apa adanya dari `tr_order.status` (`pending`/`paid`/`cancel`/`void`/`not_paid`, dst).
- `total_billing` — string (hasil select langsung kolom `decimal` MySQL, konsisten sama konvensi field duit lain).
- `total_item` — integer.
- `customer_phone_number`/`member_name` — `null` kalau order gak diisi nomor HP / nomor HP-nya gak match member manapun (lihat [KIOSK SAVE ORDER.md](./KIOSK%20SAVE%20ORDER.md), phone number itu best-effort, gak pernah nge-block save-order).
- `payment_method` — `null` selama belum ada baris `tr_order_payment` (order belum `paid`). Di-join lewat `payment_number` (bukan `order_number` — kolom itu gak ada di `tr_order_payment`).

Urutan: `order_date DESC, created_at DESC` (terbaru duluan).

## Catatan implementasi

- Join ke `mr_member`/`tr_order_payment`/`mr_payment_method` semuanya **`LEFT JOIN`** (bukan inner) — order yang belum `paid` gak punya baris `tr_order_payment`, order tanpa `member_id` gak match `mr_member`. Kalau pakai inner join, order `pending`/`cancel` bakal ke-drop dari list.
- Join `tr_order_payment` aman gak gandain baris order selama kiosk cuma pernah punya 1 `payment_method` per order (gak ada split payment di kanal kiosk). Kalau nanti kiosk bisa split payment, join ini perlu direvisi (subquery ambil 1 baris aja per order).

## Tervalidasi live (2026-08-12)

Tanpa `date_from`/`date_to` → balikin order kiosk hari ini (8 order, campuran ada/gak ada `customer_phone_number`/`member_name`). Range tanggal lampau tanpa order → `data: []`. Range tanggal masa depan → `data: []`. Gak ada error di kedua kasus kosong.

`terminal_id` yang gak dipakai order manapun hari ini (`terminal_id=1`) → `data: []`. `terminal_id` yang beneran dipakai (`terminal_id=4`, semua 8 order test hari ini emang dari terminal ini) → 8 order balik sesuai.
