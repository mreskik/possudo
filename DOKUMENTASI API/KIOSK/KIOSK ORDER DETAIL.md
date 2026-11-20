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
    "total_service_charge": "0.00",
    "order_fee": "0.00",
    "delivery_cost": "0.00",
    "grand_total": "26640.00",
    "rounding_amount": "0.00",
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
        "net_price": "9090.00",
        "discount_amount": "0.00",
        "tax_id": 100,
        "tax_type": "vat",
        "tax_rate": "11.00",
        "tax_amount": "1100.00",
        "service_charge_id": 9,
        "service_charge_rate": "5.00",
        "service_charge_amount": "454.50",
        "promo_id": null,
        "is_free_item_promo": false,
        "total": "10000.00",
        "package": [
          {
            "menu_id": 68,
            "menu_name": "PECEL LELE NUSANTARA",
            "qty": 1,
            "notes": null,
            "price": "6000.00",
            "net_price": "5454.00",
            "discount_amount": "0.00",
            "tax_id": 100,
            "tax_type": "vat",
            "tax_rate": "11.00",
            "tax_amount": "660.00",
            "service_charge_id": 9,
            "service_charge_rate": "5.00",
            "service_charge_amount": "272.70",
            "promo_id": null,
            "total": "6000.00"
          },
          {
            "menu_id": 69,
            "menu_name": "CARAME MACHIATO",
            "qty": 1,
            "notes": null,
            "price": "8000.00",
            "net_price": "7272.00",
            "discount_amount": "0.00",
            "tax_id": 100,
            "tax_type": "vat",
            "tax_rate": "11.00",
            "tax_amount": "880.00",
            "service_charge_id": 9,
            "service_charge_rate": "5.00",
            "service_charge_amount": "363.60",
            "promo_id": null,
            "total": "8000.00"
          }
        ]
      }
    ]
  }
}
```

- `items[].package` — **selalu ada** (array, bisa kosong `[]`) — isi kalau item itu paket/punya varian (dari `tr_order_detail_package`), kosong kalau item biasa.
- Field duit (`price`, `net_price`, `discount_amount`, `tax_amount`, `service_charge_amount`, `total`, plus header `sub_total`/`total_tax`/`total_discount`/`total_service_charge`/`order_fee`/`delivery_cost`/`grand_total`/`rounding_amount`/`total_billing`) balik sebagai **string** — hasil select langsung dari kolom `decimal` MySQL, konsisten sama standar string buat field duit di [KIOSK SAVE ORDER.md](./KIOSK%20SAVE%20ORDER.md).
- **`grand_total`/`rounding_amount` (2026-11-20, BARU)** — 2 field HEADER baru, sama pola `total_service_charge`/`order_fee`/`delivery_cost` di bawah. `grand_total` = nilai SEBELUM dibulatkan (ini yang SEBELUMNYA namanya `total_billing`), `rounding_amount` = selisih pembulatan. Lihat [`SAVE ORDER.md`](../POS/ORDER/SAVE%20ORDER.md#formula-rounding-2026-11-20) buat formula lengkap.
- **`net_price` (2026-11-18, BARU)** — harga per-unit SEBELUM diskon tapi SUDAH dilepas pajak+service charge kalau `flagInclusiveTax` (sama kolom `tr_order_detail.net_price`/`tr_order_detail_package.net_price`, lihat [KIOSK SAVE ORDER.md](./KIOSK%20SAVE%20ORDER.md)) — dulu gak pernah ikut di-expose di endpoint ini, cuma internal kolom DB.
- **`tax_id`/`tax_type`/`tax_rate`, `service_charge_id`/`service_charge_rate`/`service_charge_amount`, `promo_id` (2026-11-18, BARU, di level `items[]` & `items[].package[]`)** — sebelumnya cuma `tax_amount` yang ke-expose (hasil kalkulasi), komponen id/tipe/rate-nya sendiri gak pernah ikut, demikian juga `promo_id` (dulu dibaca pas `save-order` tapi gak pernah dikembalikan di endpoint detail). Sekarang semua ikut ditampilkan, konsisten pasangan `tax_*`/`service_charge_*`. `is_free_item_promo` (BARU, cuma level `items[]`, **TIDAK ADA** di `package[]` — kolomnya emang gak ada di `tr_order_detail_package`, lihat [SAVE ORDER.md](../POS/POS/ORDER/SAVE%20ORDER.md)) juga ditambahkan di titik yang sama — flag item gratis dari promo.
- **`total_service_charge`/`order_fee`/`delivery_cost` (2026-11-18, BARU)** — 3 field HEADER yang sebelumnya **gak ada sama sekali** di response ini (`tr_order.total_service_charge`/`order_fee`/`delivery_cost` sudah ada & aktif dipakai kalkulasi `total_billing`, tapi gak pernah ikut di-SELECT di `GetOrderDetail()`). `order_fee`/`delivery_cost` FE-trusted (manual input, lihat [KIOSK SAVE ORDER.md](./KIOSK%20SAVE%20ORDER.md)), `total_service_charge` hasil `Σ qty × serviceChargeAmount` semua baris item.
- **⚠️ Perubahan field (2026-08-20)**: `discount_value`→`discount_amount`, `tax_value`→`tax_amount` (rename doang, arti sama). `total` per-item **nama sama tapi artinya berubah** — sekarang udah termasuk diskon (`qty × (net_dpp + tax_amount)`), sebelumnya gross (`qty × price`, sebelum diskon). Detail lengkap: [PERHITUNGAN PAJAK INCLUSIVE & DISKON.md](../../../posv1-vue/DOKUMENTASI/PERHITUNGAN%20PAJAK%20INCLUSIVE%20%26%20DISKON.md) (`posv1-vue`).
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

## Tervalidasi live (2026-11-18) — audit lengkap field yang hilang di GetOrderDetail(), semua ditambahkan

Audit dipicu laporan user bertahap ("net_price kok gak ada" → "service charge amount juga gak ada" → "taxid tax rate, service charge id, rate juga belum ada" → "promo id juga gak ada" → "tax type juga gak ada") — ternyata `GetOrderDetail()` dari awal cuma SELECT subset kecil kolom (`price`/`discount_amount`/`tax_amount`/`total`), padahal `tr_order_detail`/`tr_order_detail_package` (dan `tr_order` di level header) sudah punya banyak kolom lain yang aktif dipakai kalkulasi tapi gak pernah ikut di-expose. Ditambahkan ke SELECT (level `items[]`/`items[].package[]`, kecuali disebutkan lain): `net_price`, `tax_id`, `tax_type`, `tax_rate`, `service_charge_id`, `service_charge_rate`, `service_charge_amount`, `promo_id`, `is_free_item_promo` (**cuma level item**, `tr_order_detail_package` gak punya kolom ini). Level header (`data`): `total_service_charge`, `order_fee`, `delivery_cost`.

**Tervalidasi live**, satu per satu via `curl` ke order `paid` real (`NO112320261027165250`, CAPPUCCINO x2 inclusive tax 10% + 2 sub-item package) — nilai asli order ini banyak yang `0`/`NULL` (dibuat sebelum fitur-fitur ini ada), jadi tiap field di-set sementara ke nilai non-default dulu biar kebuktian field BENERAN ke-expose (bukan kebetulan default yang ambigu sama "field gak ada"), lalu direvert abis dikonfirmasi:
- `net_price`: `27273.00` (`30000/1.1`, benar) per item.
- `service_charge_amount`: di-set `333.33`/`11.11` (item/package) → keluar persis.
- `tax_id`/`tax_rate`: nilai asli `10`/`10.00` (gak perlu di-set, sudah ada dari awal) → keluar persis.
- `service_charge_id`/`service_charge_rate`: nilai asli `NULL`/`0.00` (order dibuat sebelum fitur service charge ada) → keluar `null`/`"0.00"`, bukan bug, memang belum pernah diisi.
- `is_free_item_promo`/`promo_id`: di-set `1`/`1` di 1 item+package → keluar persis, item lain tetap `0`/`null`.
- `tax_type`: nilai asli `"pb1"` → keluar persis.
- `total_service_charge`/`order_fee`/`delivery_cost`: di-set `1234.00`/`2500.00`/`7500.00` → keluar persis, posisi di antara `total_discount` dan `total_billing`.

Semua data test (order_detail/order_detail_package test values) direvert ke kondisi semula setelah setiap putaran verifikasi. `php -l` bersih di setiap putaran edit.
