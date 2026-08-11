# Kiosk Order Detail

```
GET /api/kiosk/order/{order_number}
```

Header order doang — `order_name`, `sub_total`, `total_tax`, `total_discount`, `total_billing` — by `order_number` (hasil dari [KIOSK SAVE ORDER.md](./KIOSK%20SAVE%20ORDER.md)). Query langsung ke `tr_order`, gak ada join. Dipakai buat nampilin ringkasan order abis save-order, sebelum bayar.

**Belum termasuk list item** (`list_order[]`/detail per menu) — baru header. Nyusul kalau dibutuhin.

## Response

Sukses:

```json
{
  "code": 0,
  "data": {
    "order_number": "NO4TB20260810163639",
    "order_name": "Test Total Discount",
    "sub_total": "14500.00",
    "total_tax": "0.00",
    "total_discount": "1500.00",
    "total_billing": "13000.00"
  }
}
```

`order_number` gak ketemu:

```json
{ "code": 100, "message": "order tidak ditemukan" }
```

Field duit (`sub_total`, `total_tax`, `total_discount`, `total_billing`) balik sebagai **string** — hasil select langsung dari kolom `decimal` MySQL, konsisten sama standar string buat field duit di [KIOSK SAVE ORDER.md](./KIOSK%20SAVE%20ORDER.md).

## Tervalidasi live (2026-08-10)

`order_number` gak ketemu → error. Order beneran dengan diskon (dari `save-order`) → 6 field balik bener, termasuk `total_discount`.
