# Reprint

```
GET /api/sales/reprint/{order_number}
```

Cetak ulang struk — **routing print-nya tergantung status order**, bukan jenis struk yang diminta client.

**Response:** `{ "code": 0, "data": "done brow!" }` (pesan literal, bukan placeholder — perhatikan nada informalnya kalau mau diseragamkan saat migrasi).

- `status === 'cancel'` → `PrintServices::PrintBill()` (struk bill biasa).
- `status === 'paid'` atau `'void'` → `PrintServices::PrintPayment()` (struk pembayaran, termasuk breakdown payment method).
- `status` lain (`pending`/`hold`) — **tidak ada percabangan yang match**, tidak terjadi print apa pun, tapi tetap balikin `code: 0` seolah sukses.

## Alur Implementasi

Dipanggil dari tombol "Reprint" di [`VIEW SALES.md`](VIEW%20SALES.md)/[`SALES LIST.md`](SALES%20LIST.md) — karena response tidak memberi sinyal print benar-benar terjadi atau tidak (lihat kasus `pending`/`hold` di atas), FE tidak bisa mengandalkan response untuk validasi hasil cetak.

## Sumber

- `app/Http/Controllers/SalesController.php` — `Reprint()`.
- `app/Services/PrintServices.php` — `PrintBill()`, `PrintPayment()`.
- Frontend: `src/services/SalesServices.ts` (`Reprint`).

## Status

Didokumentasikan 2026-10-12, berdasar pembacaan kode langsung (belum ada verifikasi ulang lewat HTTP request live di sesi ini).
