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

Sukses (contoh **exclusive tax**, campur Tax PB1+VAT dan Service Charge 5%, plus Delivery Cost & Order Fee):

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
    "order_number": "ORD-20261119-0012",
    "payment_number": "PAY-20261119-0012",
    "order_in": "2026-11-19 12:30:00",
    "order_name": "Meja 5",
    "member_name": null,
    "pax": 2,
    "status": "paid",
    "total_item": 3,
    "sub_total": "124300.00",
    "total_discount": "7000.00",
    "total_tax": "14500.00",
    "total_billing": "108000.00",
    "delivery_cost": "5000.00",
    "order_fee": "1500.00",
    "is_inclusive_tax": false,
    "tax_breakdown": { "PB1": 10000, "VAT": 4500 },
    "service_charge_breakdown": { "Service Charge (5%)": 8500 },
    "items": [
      {
        "qty": 2,
        "total": "50000.00",
        "discount_amount": "5000.00",
        "notes": "pedas sedang",
        "menu_name": "Nasi Goreng Spesial",
        "promo_name": "Promo Makan Siang",
        "display_price": 55000,
        "package": []
      },
      {
        "qty": 1,
        "total": "33000.00",
        "discount_amount": "2000.00",
        "notes": null,
        "menu_name": "Paket Combo Ayam",
        "promo_name": null,
        "display_price": 38500,
        "package": [
          { "qty": 1, "total": "20000.00", "notes": null, "menu_name": "Ayam Goreng", "display_price": 22000 },
          { "qty": 1, "total": "8000.00", "notes": null, "menu_name": "Es Teh Manis", "display_price": 8800 }
        ]
      }
    ],
    "payment_detail": [
      { "payment_amount": "108000.00", "payment_method_name": "Cash" }
    ]
  }
}
```

Contoh **inclusive tax** (`is_inclusive_tax: true`) — `tax_breakdown` & `service_charge_breakdown` TETAP keisi (bukan array/object kosong), Kiosk sendiri yang mutuskan ditaruh di mana (lihat Catatan implementasi):

```json
{
  "is_inclusive_tax": true,
  "tax_breakdown": { "PB1": 10000, "VAT": 4500 },
  "service_charge_breakdown": { "Service Charge (5%)": 8500 },
  "total_tax": "14500.00"
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

- **Reuse query & fungsi yang sama persis** dipakai `PrintServices::PrintPayment()` (versi print server-side) — `PrintServices::ResolveFlagInclusiveTax()`/`GetTaxBreakdownByType()`/`GetServiceChargeBreakdown()`, semua `public static` biar bisa dipanggil dari sini. Ini biar breakdown pajak/service charge/subtotal di struk browser **konsisten** sama versi cetak server, gak diitung ulang pakai logic terpisah yang bisa nyimpang.
- **`tax_breakdown` & `service_charge_breakdown`** — SELALU dikirim apa adanya (object `{label: amount}`, bisa kosong `{}` kalau emang gak ada tax_type/service_charge_rate aktif di order itu), **TIDAK dikosongin otomatis berdasarkan `is_inclusive_tax`**. Backend sengaja gak mutusin "ditaruh di mana" — itu keputusan rendering di sisi Kiosk, ngikutin pola yang sama kayak `PrintPayment()` (2026-11-19): kalau **exclusive**, breakdown Tax (dulu) lalu Service Charge (di bawahnya) ditampilkan terpisah DI ATAS Delivery Cost/Order Fee/Grand Total; kalau **inclusive**, KEDUANYA (Tax dan Service Charge) gak ditampilkan di atas Grand Total, cuma jadi catatan "Price Inclusive of ..." (urutan sama: Tax dulu, lalu Service Charge disingkat "SC") — soalnya `flag_inclusive_tax` ngebungkus `tax_rate` **dan** `service_charge_rate` SEKALIGUS (lihat `OrderServices::CalculateLineItem()`), bukan cuma pajak.
- **`delivery_cost` & `order_fee`** (BARU, 2026-11-19) — header-level (1 nilai per order dari `tr_order`, bukan breakdown per-item), selalu dikirim (bisa `"0.00"`). Posisi tampil di struk: SELALU menempel langsung di atas Grand Total, apapun status `is_inclusive_tax` (bukan bagian dari bungkusan inclusive).
- **Sengaja gak include** dari `PrintPayment()`: `cashier`, `print_ke`/logic "COPY n" — itu konsep reprint fisik POS (nyatet berapa kali struk yang sama di-print ulang), Kiosk gak punya alur reprint struk yang sama.
- **`items[].package`** — selalu ada (array, bisa kosong `[]`), isi kalau item itu paket (sama pola kayak [KIOSK ORDER DETAIL.md](./KIOSK%20ORDER%20DETAIL.md)).
- **`items[].display_price` & `items[].package[].display_price`** (BARU, 2026-11-19) — harga per-baris SEBELUM diskon, net-of-tax, sudah di-gross-up pajak: `qty * net_price * (1 + tax_rate/100)` (persis rumus `$displayItemPrice`/`$displayPackagePrice` di `PrintPayment()`). **BUKAN** `total` (yang udah net-of-discount) — dipakai buat baris harga utama di struk, sementara `total`/`discount_amount` dipakai buat baris "% Promo" di bawahnya (biar diskon gak keitung dobel secara visual). Field mentah `net_price`/`tax_rate` **TIDAK** ikut dikirim (cuma dipakai internal buat hitung `display_price`).
- **⚠️ Perubahan field (2026-08-20)**: `discount_value`→`discount_amount` (rename doang). `total` per-item **nama sama tapi artinya berubah** — sekarang udah termasuk diskon, bukan gross lagi. Sama perubahan kayak [KIOSK ORDER DETAIL.md](./KIOSK%20ORDER%20DETAIL.md), detail lengkap: [PERHITUNGAN PAJAK INCLUSIVE & DISKON.md](../../../posv1-vue/DOKUMENTASI/PERHITUNGAN%20PAJAK%20INCLUSIVE%20%26%20DISKON.md) (`posv1-vue`).
- **⚠️ Perubahan rename kolom (2026-11-18)**: `dpp`/`net_dpp` di-swap jadi `net_price`/`dpp` (lihat [[project_pos_dpp_tax_formula]]/catatan rename sesi) — endpoint ini sekarang pakai `net_price` buat `display_price` (makna "sebelum diskon", bukan makna lama `dpp`).

## Tervalidasi live (2026-08-13, SEBAGIAN SUDAH USANG)

- Order `paid` biasa (1 item, gak ada paket) → semua field balik bener, `is_inclusive_tax: true`, `tax_breakdown: []` (sesuai — inclusive gak ada breakdown, PERILAKU INI SUDAH BERUBAH, lihat Catatan implementasi di atas).
- Order `paid` dengan item paket (2 sub-item) → `items[0].package` keisi 2 baris bener (`menu_name`/`total` masing-masing sesuai).
- `order_number` gak ketemu → `"order tidak ditemukan"`.
- Order masih `pending` (belum bayar) → `"order belum dibayar, belum ada struk buat ini"`.

**⚠️ BELUM di-live-test ulang (2026-11-19)** setelah perubahan `display_price`/`service_charge_breakdown`/`delivery_cost`/`order_fee` — baru `php -l` syntax check, belum dicoba ke order sungguhan. Perlu live test sebelum dianggap tuntas.
