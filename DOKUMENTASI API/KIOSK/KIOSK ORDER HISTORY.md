# Kiosk Order History

```
GET /api/kiosk/order-history
```

List **header** order kiosk (`order_source = 'kiosk'`), filter by tanggal (`order_in`). Query langsung ke `tr_order` + `LEFT JOIN` ke `mr_member`/`tr_kiosk_payment_request`/`mr_payment_method`.

**Belum termasuk list item** per-order — baru header, sama kayak [KIOSK ORDER DETAIL.md](./KIOSK%20ORDER%20DETAIL.md). Detail per-order nyusul endpoint terpisah kalau dibutuhin.

## Request

Query param (semua optional):

```
GET /api/kiosk/order-history?date_from=2026-08-01&date_to=2026-08-12&terminal_id=4
```

- `date_from` — format `Y-m-d`. Default: **hari ini** kalau gak dikirim.
- `date_to` — format `Y-m-d`, **inclusive** (dibandingin sampai `< date_to + 1 hari`). Default: **hari ini** kalau gak dikirim.
- `terminal_id` — optional, gak difilter sama sekali kalau gak dikirim (semua terminal di branch ini).

Filter-nya ke kolom `tr_order.order_in` (timestamp order dibuat, ada jamnya) — **bukan** `order_date` (cuma tanggal doang, dulu dipakai, sekarang diganti) atau `created_at`.

## Response

```json
{
  "code": 0,
  "data": [
    {
      "order_number": "NO4TB20260812161227",
      "payment_number": null,
      "status": "pending",
      "order_in": "2026-08-12 16:12:27",
      "order_name": "asasd",
      "total_billing": "36630.00",
      "total_item": 1,
      "customer_phone_number": "08121314423",
      "member_name": "bagus",
      "visit_purpose_id": 2,
      "visit_purpose_name": "TAKEAWAY",
      "payment_method_id": 1,
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
- `visit_purpose_id`/`visit_purpose_name` — `tro.visit_purpose_id` itu FK **langsung** ke `mr_visit_purpose.id` (bukan ke `mr_branch_visit_purpose`, beda id-space — pola join yang sama dipakai `OrderServices::viewOrder()` di POS).
- `payment_method_id`/`payment_method` — diambil dari **attempt terakhir** di `tr_kiosk_payment_request` (bukan dari `tr_order_payment`) — sengaja, biar tetap keisi meski order masih `pending` (belum kebayar). Kiosk pakai `payment_method_id` ini buat **retry langsung dari list history**: order `pending` di-tap → panggil ulang [KIOSK PAYMENT REQUEST.md](./KIOSK%20PAYMENT%20REQUEST.md) pakai `payment_method_id` yang sama, customer gak perlu milih payment method dari awal lagi. `null` kalau order ini belum pernah manggil `payment/request` sama sekali.

Urutan: `order_in DESC` (terbaru duluan).

## Catatan implementasi

- Join ke `mr_member`/`mr_payment_method` pakai **`LEFT JOIN`** (bukan inner) — order tanpa `member_id` gak match `mr_member`, order yang belum pernah `payment/request` gak punya attempt sama sekali. Kalau pakai inner join, order-order itu bakal ke-drop dari list.
- `payment_method_id`/`payment_method` **gak** di-join ke `tr_order_payment` (beda dari sebelumnya) — dipilih dari attempt terakhir `tr_kiosk_payment_request` per `order_number` (subquery `MAX(created_at)`), biar tetap ada buat order `pending` yang mau di-retry. Untuk order yang udah `paid`, hasilnya tetap konsisten sama `tr_order_payment` karena `payment_method_id` di situ memang di-set dari attempt yang sama (lihat [KIOSK PAYMENT CHECK STATUS.md](./KIOSK%20PAYMENT%20CHECK%20STATUS.md)).

## Tervalidasi live (2026-08-12)

Tanpa `date_from`/`date_to` → balikin order kiosk hari ini (8 order, campuran ada/gak ada `customer_phone_number`/`member_name`). Range tanggal lampau tanpa order → `data: []`. Range tanggal masa depan → `data: []`. Gak ada error di kedua kasus kosong.

`terminal_id` yang gak dipakai order manapun hari ini (`terminal_id=1`) → `data: []`. `terminal_id` yang beneran dipakai (`terminal_id=4`, semua 8 order test hari ini emang dari terminal ini) → 8 order balik sesuai.

## Tervalidasi live (2026-08-12) — payment_method_id buat retry

Order yang udah pernah manggil `payment/request` (termasuk yang udah di-cancel via [KIOSK CANCEL ORDER.md](./KIOSK%20CANCEL%20ORDER.md)) → `payment_method_id`/`payment_method` keisi bener (`1`/`QRIS`), diambil dari attempt terakhirnya. Order yang belum pernah manggil `payment/request` sama sekali → keduanya `null`.

## Tervalidasi live (2026-08-13) — filter pindah ke order_in

Field & filter diganti dari `order_date` (tanggal doang) ke `order_in` (timestamp, ada jamnya) — sama query `date_from=2026-08-12&date_to=2026-08-12` yang sebelumnya jalan pakai `order_date`, hasilnya tetap sama order-nya (termasuk order yang statusnya udah `paid`/`expired` dari sesi test sebelumnya), cuma field-nya sekarang nunjukin jam (`"order_in": "2026-08-12 17:48:34"`). Range tanggal masa depan tetap `data: []`.

## Tervalidasi live (2026-08-13) — visit_purpose_id/visit_purpose_name

Order test dengan visit purpose `TAKEAWAY` (`visit_purpose_id: 2`) → balikin `visit_purpose_id`/`visit_purpose_name` sesuai (`2`/`"TAKEAWAY"`), konsisten sama baris yang sama di [KIOSK ORDER DETAIL.md](./KIOSK%20ORDER%20DETAIL.md).
