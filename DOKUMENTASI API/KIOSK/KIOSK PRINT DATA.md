# Kiosk Print Data

```
GET /api/kiosk/print-data/{order_number}
```

Data struk **terstruktur (JSON)**, buat kiosk yang terminal-nya `flag_printer_frontend = true` (lihat [KIOSK TERMINAL DETAIL.md](./KIOSK%20TERMINAL%20DETAIL.md)) — server **skip** `PrintServices::PrintPayment()` (print ESC/POS ke printer fisik) buat order kayak gini, [KIOSK PAYMENT CHECK STATUS.md](./KIOSK%20PAYMENT%20CHECK%20STATUS.md) poin 4), jadi Kiosk-nya sendiri yang harus render+print struk lewat browser.

## Kapan dipanggil

Kiosk **udah polling** [KIOSK PAYMENT CHECK STATUS.md](./KIOSK%20PAYMENT%20CHECK%20STATUS.md) sampe dapet `status: paid` — momen itu **udah jadi sinyal alami** "sekarang saatnya print", gak butuh sinyal tambahan dari backend. Alurnya:

```
polling check-status -> dapet status: paid (pertama kali)
  -> kalau terminal.flag_printer_frontend == true:
       panggil GET print-data/{order_number}
       -> render HTML/canvas + trigger print browser (di luar scope backend)
  -> kalau enggak, gak perlu manggil ini sama sekali (server udah print sendiri)
```

## Response

Sukses:

```json
{
  "code": 0,
  "data": {
    "branch": {
      "logo_header_src": "/img/branch/....png",
      "printing_header": "HEADER",
      "printing_footer": "FOOTER",
      "image_footer_src": null
    },
    "order_number": "NO4TB20260813151848",
    "payment_number": "PS4TB20260813151859",
    "order_in": "2026-08-13 15:18:48",
    "order_name": "PrintDataPackageTest",
    "member_name": null,
    "pax": 1,
    "status": "paid",
    "total_item": 1,
    "sub_total": "10000.00",
    "total_discount": "0.00",
    "total_tax": "0.00",
    "total_billing": "10000.00",
    "is_inclusive_tax": true,
    "tax_breakdown": [],
    "items": [
      {
        "qty": 1,
        "total": "10000.00",
        "discount_amount": "0.00",
        "notes": null,
        "menu_name": "PAKET BUNDLING SPESIAL",
        "promo_name": null,
        "package": [
          { "qty": 1, "total": "6000.00", "notes": null, "menu_name": "PECEL LELE NUSANTARA" },
          { "qty": 1, "total": "8000.00", "notes": null, "menu_name": "CARAME MACHIATO" }
        ]
      }
    ],
    "payment_detail": [
      { "payment_amount": "10000.00", "payment_method_name": "QRIS" }
    ]
  }
}
```

`order_number` gak ketemu:

```json
{ "code": 100, "message": "order tidak ditemukan" }
```

Order belum `paid`:

```json
{ "code": 100, "message": "order belum dibayar, belum ada struk buat ini" }
```

## Catatan implementasi

- **Reuse query yang sama persis** dipakai `PrintServices::PrintPayment()` (versi print server-side) — termasuk `PrintServices::ResolveFlagInclusiveTax()`/`GetTaxBreakdownByType()` (diubah dari `private` jadi `public static` biar bisa dipanggil dari sini). Ini biar breakdown pajak/subtotal di struk browser **konsisten** sama versi cetak server, gak diitung ulang pakai logic terpisah yang bisa nyimpang.
- **`tax_breakdown`** — cuma keisi kalau order-nya **exclusive tax** (`is_inclusive_tax === false`). Order `inclusive` (kayak contoh di atas) balikin array kosong `[]` — pajaknya udah "nempel" di harga item, bukan breakdown terpisah (sama perilaku kayak versi print server).
- **Sengaja gak include** dari `PrintPayment()`: `cashier`, `print_ke`/logic "COPY n" — itu konsep reprint fisik POS (nyatet berapa kali struk yang sama di-print ulang), Kiosk gak punya alur reprint struk yang sama.
- **`items[].package`** — selalu ada (array, bisa kosong `[]`), isi kalau item itu paket (sama pola kayak [KIOSK ORDER DETAIL.md](./KIOSK%20ORDER%20DETAIL.md)).
- **⚠️ Perubahan field (2026-08-20)**: `discount_value`→`discount_amount` (rename doang). `total` per-item **nama sama tapi artinya berubah** — sekarang udah termasuk diskon, bukan gross lagi. Sama perubahan kayak [KIOSK ORDER DETAIL.md](./KIOSK%20ORDER%20DETAIL.md), detail lengkap: [PERHITUNGAN PAJAK INCLUSIVE & DISKON.md](../../../posv1-vue/DOKUMENTASI/PERHITUNGAN%20PAJAK%20INCLUSIVE%20%26%20DISKON.md) (`posv1-vue`).

## Tervalidasi live (2026-08-13)

- Order `paid` biasa (1 item, gak ada paket) → semua field balik bener, `is_inclusive_tax: true`, `tax_breakdown: []` (sesuai — inclusive gak ada breakdown).
- Order `paid` dengan item paket (2 sub-item) → `items[0].package` keisi 2 baris bener (`menu_name`/`total` masing-masing sesuai).
- `order_number` gak ketemu → `"order tidak ditemukan"`.
- Order masih `pending` (belum bayar) → `"order belum dibayar, belum ada struk buat ini"`.
