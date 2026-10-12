# Cancel Item

```
POST /api/order/cancel-item
```

Batalkan **1 baris item** di dalam order (bukan seluruh order — lihat [`CANCEL ORDER.md`](CANCEL%20ORDER.md) untuk itu).

**Request:**
```json
{ "ulid": "01hx...", "notes": "Salah pesan" }
```

**Response:**
```json
{ "code": 0, "message": "cancel item success!" }
```

- `ulid` harus match baris `tr_order_detail` yang **belum** di-cancel (`cancel_at IS NULL`) — sudah di-cancel/tidak ketemu → `{ "code": 100, "message": "item not found / already cancelled!" }`.
- Set `cancel_at`/`cancel_notes`/`sync_at = null` pada baris itu — **baris tidak dihapus**, tetap ada di `listOrder[]` response [`VIEW ORDER.md`](VIEW%20ORDER.md) (FE yang menampilkan sebagai dicoret/disembunyikan).
- **Pengecualian penting dari pola umum**: sesudah cancel, server **otomatis menghitung ulang** `total_item`/`sub_total`/`total_tax`/`total_billing`/`total_discount` di `tr_order` (`RecalculateOrderTotals()`, dari SEMUA baris yang masih aktif, `cancel_at IS NULL`) — **satu-satunya tempat server melakukan kalkulasi ulang**, konsisten dengan [`SAVE ORDER.md`](SAVE%20ORDER.md) yang menegaskan "server tidak pernah hitung ulang" untuk kasus normal.
- Formula DPP yang dipakai `RecalculateOrderTotals()` **sama persis** dengan `netPrice()`/`hitungTotalFromItemQty()` di frontend: pajak dilepas dulu (`dpp`), diskon dipotong dari situ (`netDpp`), pajak final dihitung ulang dari `netDpp` — urutan standar PPN (lihat memory project: DPP tax formula).

## Alur Implementasi

Dipanggil dari tombol hapus item di cart/edit order — setelah sukses, FE **sebaiknya reload** [`VIEW ORDER.md`](VIEW%20ORDER.md) untuk dapat total terbaru (yang dihitung ulang server), bukan mengandalkan kalkulasi lokal FE yang sudah stale.

## Sumber

- `app/Http/Controllers/OrderController.php` — `CancelOrderDetail()`.
- `app/Services/OrderServices.php` — `CancelOrderDetail()`, `RecalculateOrderTotals()` (private).
- Frontend: `src/services/Order.js` (`CancelOrderDetail`).

## Status

Didokumentasikan 2026-10-12, berdasar pembacaan kode langsung (belum ada verifikasi ulang lewat HTTP request live di sesi ini).
