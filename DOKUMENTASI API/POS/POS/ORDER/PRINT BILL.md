# Print Bill

```
GET /api/order/print-bill
```

Trigger cetak struk/bill ke printer fisik — **bukan** endpoint JSON biasa, memanggil `PrintServices::PrintBill()` langsung dan selalu balikin response kosong (`''`).

**Request:** `order` (query param, order_number).

**Response:** string kosong — tidak ada `code`/`data`, FE tidak bisa membedakan sukses/gagal dari response ini saja.

- Dipanggil juga **otomatis** sebagai efek samping [`CANCEL ORDER.md`](CANCEL%20ORDER.md) (order yang dibatalkan tetap di-print sebagai bukti pembatalan).
- Kalau `table_section`/`order` kosong — ada baris komentar (`if ($table_section == null || $order == null) return 'error';`) yang **tidak aktif** (dikomentari), jadi saat ini **tidak ada validasi** parameter kosong sama sekali — kemungkinan error PHP kalau `order` tidak dikirim.

## Alur Implementasi

Dipanggil manual dari tombol "Print Bill"/cetak ulang struk di halaman Order — karena response-nya tidak memberi sinyal sukses/gagal, FE biasanya tidak menunggu konfirmasi, cuma trigger-and-forget (mirip pola [`REMOVE ITEM BEFORE SAVE.md`](REMOVE%20ITEM%20BEFORE%20SAVE.md)).

## Sumber

- `app/Http/Controllers/OrderController.php` — `PrintBill()`.
- `app/Services/PrintServices.php` — `PrintBill()`.
- Frontend: dipanggil dari `src/modules/dashboard/order/orderPage.vue`.

## Status

Didokumentasikan 2026-10-12, berdasar pembacaan kode langsung (belum ada verifikasi ulang lewat HTTP request live di sesi ini). Logic cetak fisik (`PrintServices::PrintBill()`) tidak ditelusuri detail — di luar scope dokumentasi API (urusan driver printer/ESC-POS, bukan kontrak HTTP).
