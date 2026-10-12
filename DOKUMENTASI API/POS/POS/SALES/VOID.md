# Void

```
POST /api/sales/void
```

Batalkan order yang **sudah dibayar** (`status = 'paid'`) — berbeda dari [`../ORDER/CANCEL ORDER.md`](../ORDER/CANCEL%20ORDER.md) yang untuk order `pending`/`hold` (belum bayar).

**Request:**
```json
{ "order_number": "NOSBE...", "notes": "Customer komplain" }
```

**Response:** `{ "code": 0, "message": "void NOSBE... success" }`

- Cuma order `status === 'paid'` yang bisa di-void — selain itu → `{ "code": 100, "message": "only paid orders can be voided!" }`.
- Order tidak ketemu → `"order data not found!"` — **catatan bug**: pengecekan `!$data_order` dilakukan **SETELAH** `$data_order->status` diakses di baris sebelumnya, jadi kalau order benar-benar tidak ada, error PHP (akses property dari `null`) akan terjadi duluan sebelum sempat sampai ke pesan "order data not found!" yang didesain.
- Set `status = 'void'`, `void_notes`, `void_at`, `void_print_ke = 0`, `sync_at = null`, `order_out = now()`.
- **Efek samping**: setelah void, otomatis panggil `PrintServices::PrintPayment()` — struk pembatalan (versi payment) ter-print otomatis.
- **Stok TIDAK dikembalikan otomatis** (sama seperti [`../ORDER/CANCEL ORDER.md`](../ORDER/CANCEL%20ORDER.md)).

## Alur Implementasi

Dipanggil dari tombol "Void" di halaman Sales (`SendVoid()`, `SalesServices.ts`) — biasanya perlu akses khusus/approval (void beda dari cancel biasa karena uang sudah diterima), walau tidak ada barrier role/permission eksplisit yang terlihat di kode backend ini.

## Sumber

- `app/Http/Controllers/SalesController.php` — `Void()`.
- `app/Services/SalesServices.php` — `Void()`.
- Frontend: `src/services/SalesServices.ts` (`SendVoid`).

## Status

Didokumentasikan 2026-10-12, berdasar pembacaan kode langsung (belum ada verifikasi ulang lewat HTTP request live di sesi ini).
