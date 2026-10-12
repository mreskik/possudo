# Save / Edit / View Payment

Pembayaran **manual** (CASH/kartu/EDC dkk, dicatat kasir sendiri) — beda dari [`PAYMENT GATEWAY.md`](PAYMENT%20GATEWAY.md) yang untuk QRIS/e-wallet via service pihak ketiga.

## Save Payment

```
POST /api/payment/save-payment
```

**Request:**
```json
{
  "order_number": "NOSBE...",
  "member_id": null,
  "payment_detail": [
    { "payment_method_id": 1, "payment_amount": "125000.00", "change_amount": "1877.00", "info": null }
  ]
}
```

**Response sukses:** `{ "code": 0, "data": { "paymentNumber": "PS1SBE2026101214350001" } }`

**Response gagal** (pola BEDA dari kebanyakan endpoint lain — `$response->success` di-cek manual di controller, bukan exception):
- Order sudah ada `payment_number` → `{ "code": 100, "message": "order {order_number} has paid!" }`
- Order tidak ketemu → `{ "code": 100, "message": "order {order_number} not found!" }`

- `payment_detail[]` — **support split payment** (array, bisa >1 baris metode berbeda).
- `change_amount` — kembalian tunai, cuma relevan untuk CASH, default `0` untuk metode lain.
- `info` — field bebas teks (pengganti `card_number`/`bank_name`/`account_name`/`verification_code` lama, migration 2026-09-30) — diisi kasir apa adanya untuk metode non-cash.
- **`mdr_amount` DIHITUNG SERVER-SIDE** (`calculateMdrAmount()`, dari `mr_payment_method.mdr`), **bukan** dikirim dari client — sengaja, supaya tidak bisa dimanipulasi FE. Ini pengecualian dari pola umum "server tidak pernah hitung ulang" (lihat [`../ORDER/SAVE ORDER.md`](../ORDER/SAVE%20ORDER.md)) — murni informasi, tidak ada jurnal akuntansi yang terpengaruh nilai ini.
- Sukses → `status` order jadi `paid`, `payment_number`/`payment_at`/`member_id` ter-update, **otomatis print struk** (`PrintServices::PrintPayment()`) — **kecuali** order dari Kiosk yang terminal-nya `flag_printer_frontend = true` (printing dilakukan di browser Kiosk, bukan server).
- **Khusus order dari Kiosk**: payment sukses juga trigger print kitchen (ditunda sejak `SaveOrder()`, baru sekarang karena Kiosk perlu kepastian bayar dulu) dan insert ke `tr_kiosk_order_notif` (trigger toast notifikasi order baru di POS, lihat [`../ORDER/ORDER NOTIF.md`](../ORDER/ORDER%20NOTIF.md)) — order Kiosk yang dibuat tapi tidak jadi dibayar **tidak pernah** muncul sebagai notifikasi.

## Edit Payment

```
POST /api/payment/edit-payment
```

**Request:** sama seperti Save Payment + `total_payment` (dicek sama persis `total_billing` order).

**Response:** `{ "code": 0, "message": "update payment success!" }`

- Order belum pernah dibayar → `"order number {order_number} has never been paid!"`.
- `total_payment` **wajib persis sama** dengan `total_billing` tersimpan (`!=`, bukan toleransi pembulatan) → tidak cocok → `"Payment amount does not match the order!"`.
- **Replace total**: semua baris `tr_order_payment` lama untuk order ini **dihapus**, lalu insert ulang dari `payment_detail[]` baru — bukan update per-baris.

## View Payment

```
GET /api/payment/view-payment/{order_number}
```

Response:
```json
{
  "code": 0,
  "data": {
    "order_number": "NOSBE...",
    "payment_number": "PS1SBE...",
    "total_payment": "123123.00",
    "item_voucher": 0,
    "total_amount_voucher": 0,
    "total_change": "1877.00",
    "payment_detail": [ { "payment_method_id": 1, "payment_amount": "...", "...": "..." } ],
    "member_id": null,
    "member_name": null
  }
}
```

- Order belum pernah dibayar → `"order number {order_number} has never been paid!"`.
- `item_voucher`/`total_amount_voucher` — **selalu `0`**, field placeholder yang tidak pernah diisi logic apa pun saat ini (fitur voucher belum diimplementasikan).
- `total_change` — jumlah `change_amount` semua baris payment (sama pola seperti [`../SALES/VIEW SALES.md`](../SALES/VIEW%20SALES.md)).

## Alur Implementasi

1. Kasir di halaman Payment (`paymentPage.vue`) — input metode+nominal per baris, `SavePayment()` dipanggil saat submit.
2. Kalau perlu ubah payment **setelah** sudah tersimpan (koreksi split payment, dll) — pakai `EditPayment()`, bukan `SavePayment()` lagi (yang akan ditolak karena order sudah punya `payment_number`).
3. `ViewPayment()` dipakai untuk menampilkan ulang detail pembayaran (misal reprint, atau buka kembali halaman payment untuk order yang sudah lunas).

## Sumber

- `app/Http/Controllers/PaymentController.php` — `savePayment()`, `editPayment()`, `viewPayment()`.
- `app/Services/PaymentServices.php` — `SavePayment()`, `EditPayment()`, `ViewPayment()`, `calculateMdrAmount()` (private).
- Frontend: `src/services/PaymentServices.ts`.

## Status

Didokumentasikan 2026-10-12, berdasar pembacaan kode langsung (belum ada verifikasi ulang lewat HTTP request live di sesi ini).
