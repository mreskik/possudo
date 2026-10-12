# Cancel Order

```
POST /api/order/cancel-order
```

Batalkan **seluruh order** (bukan 1 item — lihat [`CANCEL ITEM.md`](CANCEL%20ITEM.md) untuk itu).

**Request:**
```json
{ "order_number": "NOSBE2026101209001234", "notes": "Customer batal" }
```

**Response:**
```json
{ "code": 0, "message": "cancel order success!" }
```

- Cuma order `pending`/`hold` yang bisa dibatalkan — sudah `paid`/`cancel` → `{ "code": 100, "message": "not a pending / hold order!" }`.
- Set `status = 'cancel'`, `cancel_at = now()`, `cancel_notes`, `order_out = now()`, `sync_at = null` (trigger re-sync ke ERP).
- **Efek samping**: setelah update status, controller otomatis memanggil `PrintServices::PrintBill($order_number)` — order yang dibatalkan tetap ter-print (struk pembatalan), bukan senyap.
- **Stok TIDAK dikembalikan otomatis** — tidak ada logic restore `stok_qty` di fungsi ini (beda dari ekspektasi umum "cancel = rollback stok").

## Alur Implementasi

Dipanggil dari tombol "Cancel Order" di halaman Order/List Table — biasanya perlu konfirmasi dialog dulu (notes alasan cancel opsional diisi kasir).

## Sumber

- `app/Http/Controllers/OrderController.php` — `cancelOrder()`.
- `app/Services/OrderServices.php` — `CancelOrder()`.
- Frontend: `src/services/Order.js` (`CancelOrder`).

## Status

Didokumentasikan 2026-10-12, berdasar pembacaan kode langsung (belum ada verifikasi ulang lewat HTTP request live di sesi ini).
