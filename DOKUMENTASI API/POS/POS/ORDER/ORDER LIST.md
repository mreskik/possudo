# Order List

## By Table Section

```
ANY /api/orderlist/by-tablesection/{tablesection_id}
```

Response (struktur beda tergantung `tablesection.type`):

**Takeaway**:
```json
{ "code": 0, "data": [ { "order_number": "NOSBE...", "status": "pending", "...": "tr_order lengkap" } ] }
```

**Dine-in**:
```json
{ "code": 0, "data": [ { "id": 10, "name": "Meja 1", "order_number": "NOSBE...", "order_in": "...", "status": "pending" } ] }
```

- `type === 'takeaway'` — balikin **baris `tr_order`** langsung (order_source `pos`, status `pending`/`hold`), tidak ada konsep "meja".
- `type === 'dinein'` — balikin **baris meja** (`mr_table`) dengan info order yang sedang menempatinya (mirip [`LIST TABLE BY SECTION.md`](LIST%20TABLE%20BY%20SECTION.md), tapi scope beda — lihat catatan di bawah).

## All Pending

```
GET /api/orderlist/all-pending
```

**SEMUA** order `pending`/`hold` dari POS (lintas table section), diurut `created_at DESC`.

Response: `{ "code": 0, "data": [ { "order_number": "...", "status": "pending", "...": "tr_order lengkap" } ] }`

- `WHERE order_source = 'pos'` — order dari mobile/kiosk **tidak ikut**.
- Dipakai `LockscreenPage.vue` — kasir bisa lihat sekilas order yang masih nyangkut **tanpa perlu login/pilih table section dulu**.

## Alur Implementasi

`by-tablesection` dipakai di dalam konteks 1 table section spesifik (biasanya dari List Table page, saat drill-down ke 1 section) — **mirip tapi tidak identik** dengan [`LIST TABLE BY SECTION.md`](LIST%20TABLE%20BY%20SECTION.md)/`order/list-table-by-section`: keduanya balikin bentuk serupa untuk dine-in, tapi `orderlist/by-tablesection` juga menangani kasus takeaway secara eksplisit (beda query). `all-pending` dipakai di luar konteks table section sama sekali, murni ringkasan global.

## Sumber

- `app/Http/Controllers/OrderlistController.php` — `getOrderlist()`, `getAllPendingOrders()`.
- `app/Services/OrderlistServices.php` — `getOrderList()`, `getAllPendingOrders()`.
- Frontend: `src/services/OrderListService.ts`.

## Status

Didokumentasikan 2026-10-12, berdasar pembacaan kode langsung (belum ada verifikasi ulang lewat HTTP request live di sesi ini).
