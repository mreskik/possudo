# Kiosk Order Detail

```
GET /api/kiosk/order/{order_number}
```

Header order **+ list item** (`items[]`) by `order_number` (hasil dari [KIOSK SAVE ORDER.md](./KIOSK%20SAVE%20ORDER.md)). Dipakai dobel: nampilin ringkasan order abis `save-order` (sebelum bayar), **dan** detail pas order di-tap dari [KIOSK ORDER HISTORY.md](./KIOSK%20ORDER%20HISTORY.md).

**Header-nya sengaja disamain kolomnya sama [KIOSK ORDER HISTORY.md](./KIOSK%20ORDER%20HISTORY.md)** (`status`/`order_in`/`customer_phone_number`/`member_name`/`payment_method_id`/`payment_method`, pola join yang sama persis — lihat catatan `payment_method_id` dari attempt terakhir di situ), plus tambahan `sub_total`/`total_tax`/`total_discount` yang emang cuma relevan buat tampilan detail (bukan list).

Join `mr_item_conv` → `mr_item` (`trod.menu_id` itu FK ke `mr_item_conv`, bukan langsung ke `mr_item` — pola yang sama dipakai `OrderServices::viewOrder()` di POS) buat ambil `menu_name` yang bener.

## Response

Sukses (item biasa + item package):

```json
{
  "code": 0,
  "data": {
    "order_number": "NO4TB20260812174621",
    "payment_number": "PS4TB20260812181912",
    "status": "paid",
    "order_in": "2026-08-12 17:46:21",
    "order_name": "asd",
    "sub_total": "24000.00",
    "total_tax": "2640.00",
    "total_discount": "0.00",
    "total_billing": "26640.00",
    "total_item": 1,
    "customer_phone_number": null,
    "member_name": null,
    "visit_purpose_id": 2,
    "visit_purpose_name": "TAKEAWAY",
    "payment_method_id": 1,
    "payment_expired_at": "2026-08-13 13:25:45",
    "payment_method": "QRIS",
    "items": [
      {
        "menu_id": 72,
        "menu_name": "PAKET BUNDLING SPESIAL",
        "qty": 1,
        "notes": null,
        "price": "10000.00",
        "discount_value": "0.00",
        "tax_value": "1100.00",
        "total": "10000.00",
        "package": [
          {
            "menu_id": 68,
            "menu_name": "PECEL LELE NUSANTARA",
            "qty": 1,
            "notes": null,
            "price": "6000.00",
            "discount_value": "0.00",
            "tax_value": "660.00",
            "total": "6000.00"
          },
          {
            "menu_id": 69,
            "menu_name": "CARAME MACHIATO",
            "qty": 1,
            "notes": null,
            "price": "8000.00",
            "discount_value": "0.00",
            "tax_value": "880.00",
            "total": "8000.00"
          }
        ]
      }
    ]
  }
}
```

- `items[].package` — **selalu ada** (array, bisa kosong `[]`) — isi kalau item itu paket/punya varian (dari `tr_order_detail_package`), kosong kalau item biasa.
- Field duit (`price`, `discount_value`, `tax_value`, `total`, plus header `sub_total`/`total_tax`/`total_discount`/`total_billing`) balik sebagai **string** — hasil select langsung dari kolom `decimal` MySQL, konsisten sama standar string buat field duit di [KIOSK SAVE ORDER.md](./KIOSK%20SAVE%20ORDER.md).
- `qty`/`total_item` — integer.
- `status` — apa adanya dari `tr_order.status` (`pending`/`paid`/`cancel`/`expired`/dst, sama kayak [KIOSK ORDER HISTORY.md](./KIOSK%20ORDER%20HISTORY.md)).
- `customer_phone_number`/`member_name` — `null` kalau order gak diisi nomor HP / gak match member (sama kayak history).
- `visit_purpose_id`/`visit_purpose_name` — `tro.visit_purpose_id` itu FK **langsung** ke `mr_visit_purpose.id` (bukan ke `mr_branch_visit_purpose`, beda id-space — pola join yang sama dipakai `OrderServices::viewOrder()` di POS).
- `payment_method_id`/`payment_method` — dari attempt terakhir `tr_kiosk_payment_request` (bukan `tr_order_payment`), sama persis penjelasannya di [KIOSK ORDER HISTORY.md](./KIOSK%20ORDER%20HISTORY.md) — keisi meski order masih `pending`, buat retry.
- `payment_expired_at` — kapan QR attempt terakhir kadaluarsa (snapshot dari Midtrans pas `payment/request` sukses). `null` kalau belum pernah `payment/request`. Sama persis penjelasannya di [KIOSK ORDER HISTORY.md](./KIOSK%20ORDER%20HISTORY.md).

`order_number` gak ketemu:

```json
{ "code": 100, "message": "order tidak ditemukan" }
```

## Tervalidasi live (2026-08-10)

`order_number` gak ketemu → error. Order beneran dengan diskon (dari `save-order`) → 6 field balik bener, termasuk `total_discount`.

## Tervalidasi live (2026-08-13) — items & package

- Order dengan item paket (2 sub-item) → `items[0].package` keisi 2 baris bener (`menu_name`/`price`/`total` masing-masing sesuai).
- Order dengan item biasa yang punya 1 varian → `package` tetep keisi (bukan array kosong), sesuai struktur data aslinya (varian disimpen di tabel yang sama kayak paket).
- `order_number` gak ketemu → tetap `"order tidak ditemukan"`, gak ada regresi dari sebelum `items[]` ditambahin.

## Tervalidasi live (2026-08-13) — header disamain sama order history

Order yang udah `paid` → header keluar lengkap (`status: paid`, `payment_number` keisi, `payment_method_id`/`payment_method` sesuai attempt terakhirnya) — konsisten sama baris yang sama di [KIOSK ORDER HISTORY.md](./KIOSK%20ORDER%20HISTORY.md). `order_number` gak ketemu tetap error yang sama.

## Tervalidasi live (2026-08-13) — visit_purpose_id/visit_purpose_name

Order test dengan visit purpose `TAKEAWAY` (`visit_purpose_id: 2`) → balikin `visit_purpose_id`/`visit_purpose_name` sesuai (`2`/`"TAKEAWAY"`), konsisten sama baris yang sama di [KIOSK ORDER HISTORY.md](./KIOSK%20ORDER%20HISTORY.md).

## Tervalidasi live (2026-08-13) — payment_expired_at

Order test → `payment/request` → `order-detail` balikin `payment_expired_at` sesuai `expired_at` asli dari Midtrans (`"2026-08-13 13:25:45"`), konsisten sama baris yang sama di [KIOSK ORDER HISTORY.md](./KIOSK%20ORDER%20HISTORY.md).
