# Remove Item Before Save

```
POST /api/order/remove-item-before-save
```

**Audit trail murni** — mencatat item yang dihapus kasir dari cart lokal **sebelum** order pernah tersimpan (beda dari [`CANCEL ITEM.md`](CANCEL%20ITEM.md) yang untuk item yang **sudah** tersimpan/punya `ulid`).

**Request:**
```json
{
  "item_conv_id": 120,
  "qty": 2,
  "order_number": null,
  "packages": []
}
```

- `order_number` **opsional** — `null` kalau order belum pernah tersimpan sama sekali, terisi kalau item ini dihapus dari order `pending` yang sudah ada nomornya (kasir sedang edit order, nambah menu baru lalu batal sebelum re-save).

**Response:** **SELALU** `{ "code": 0, "message": "recorded" }` — tidak pernah gagal ke client, meski penyimpanan di backend sebenarnya gagal (ditangani `OrderServices::RecordRemoveItemBeforeSave()`, errornya di-swallow). Ini **disengaja** — murni pencatatan audit, bukan jalur kritikal, tidak boleh mengganggu alur kerja kasir kalau gagal.

## Alur Implementasi

Dipanggil **fire-and-forget** (tanpa `await`) dari `removeItemOrder()` di `orderPage.vue` — `splice()` cart langsung terjadi di UI tanpa menunggu hasil request ini. Dipakai berpasangan dengan `data-remove-item-before-save(-package)` di [`../../INTERNAL/PUSH DATA.md`](../../INTERNAL/PUSH%20DATA.md) — data yang tercatat di sini di-push ke ERP sebagai bagian audit trail terpisah, tidak terkait jurnal/dayshift.

## Sumber

- `app/Http/Controllers/OrderController.php` — `RemoveItemBeforeSave()`.
- `app/Services/OrderServices.php` — `RecordRemoveItemBeforeSave()`.
- Frontend: `src/services/Order.js` (`RemoveItemBeforeSave`).

## Status

Didokumentasikan 2026-10-12, berdasar pembacaan kode langsung (belum ada verifikasi ulang lewat HTTP request live di sesi ini).
