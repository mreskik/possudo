# Move Table & Move Item

## Move Table — pindahkan SELURUH order

```
POST /api/order/save-move-table
```

**Request:**
```json
{ "order_number": "NOSBE...", "tablesection_id": 2, "table_id": 20 }
```

**Response:** `{ "code": 0, "message": "Move Table Success!" }`

- Cuma order `pending`/`hold` yang bisa dipindah.
- `tablesection.type === 'dinein'` → `table_id` **wajib**, dan meja tujuan harus **kosong** (tidak ada order `pending`/`hold` lain di situ) → gagal: `"table must be selected!"` / `"Table still has an active order!"`.
- `tablesection.type === 'takeaway'` → `table_id` di-set `null` otomatis (takeaway tidak butuh meja fisik).
- Status order ikut menyesuaikan `tablesection.can_hold` tujuan (`hold` atau `pending`).

## Move Item — pindahkan SEBAGIAN/SELURUH item ke order lain

```
POST /api/order/save-move-item
```

**Request:**
```json
{
  "order_number": "NOSBE...",
  "visit_purpose_id": 7,
  "tablesection_id": 2,
  "table_id": 20,
  "list_item": [
    { "ulid": "01hx...", "moveQty": 1 }
  ]
}
```

**Response:** `{ "code": 0, "message": "..." }`

- **Jika meja tujuan KOSONG** — otomatis **membuat order baru** (memanggil `SaveOrder()` secara internal, dengan `listOrder` kosong dulu) sebelum memindahkan item ke situ.
- **Jika meja tujuan SUDAH ADA order aktif** — item dipindah langsung ke order yang sudah ada itu.
- **Pindah sebagian qty** (`moveQty < qty` baris asli) — baris asli **di-update** qty-nya dikurangi, baris **baru** dibuat (ulid baru) di order tujuan dengan qty = `moveQty`, termasuk **menyalin semua `menuPackageList` terkait** (sub-item package ikut ter-replicate).
- **Pindah semua qty** (`moveQty === qty` baris asli) — baris yang sama langsung di-`update` `order_number`-nya ke order tujuan (tidak membuat baris baru).
- Setelah selesai, kedua order (asal & tujuan) **di-recalculate total** (`RecalculateOrderTotals()`, lihat [`CANCEL ITEM.md`](CANCEL%20ITEM.md) untuk formulanya) — memindahkan baris tidak otomatis menjaga `sub_total`/`total_billing` tetap sinkron tanpa langkah ini.

## Alur Implementasi

Dipakai saat kasir perlu menggabung meja atau memecah order (misal rombongan pindah ke meja lebih besar, atau sebagian tamu pesan terpisah). Setelah sukses, FE **wajib** reload kedua order terkait (asal & tujuan) via [`VIEW ORDER.md`](VIEW%20ORDER.md) untuk dapat state terbaru — total sudah di-kalkulasi ulang server, cart lokal FE pasti stale setelah operasi ini.

## Sumber

- `app/Http/Controllers/OrderController.php` — `SaveMoveTable()`, `SaveMoveItem()`.
- `app/Services/OrderServices.php` — `SaveMoveTable()`, `SaveMoveItem()`, `RecalculateOrderTotals()` (private, reuse dari [`CANCEL ITEM.md`](CANCEL%20ITEM.md)).
- Frontend: `src/modules/dashboard/order/composables/useMoveTable.ts`, `src/modules/dashboard/order/composables/useMoveItem.js`.

## Status

Didokumentasikan 2026-10-12, berdasar pembacaan kode langsung (belum ada verifikasi ulang lewat HTTP request live di sesi ini).
