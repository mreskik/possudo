# List Table By Section

## Kosong saja

```
GET /api/order/list-table-by-section/{tablesection_id}
```

Meja yang **KOSONG saja** di 1 table section (tidak ada order `pending`/`hold` aktif).

Response:
```json
{
  "code": 0,
  "data": [
    { "id": 10, "table_section_id": 1, "name": "Meja 1", "order_number": null, "order_in": null, "status": null }
  ]
}
```

- `WHERE tro.order_number IS NULL` — meja yang sedang dipakai **tidak muncul** di list ini sama sekali.

## Semua meja (terisi + kosong)

```
GET /api/order/list-table-by-section-all/{tablesection_id}
```

Semua meja di section itu, **termasuk yang sedang ada order**.

Response:
```json
{
  "code": 0,
  "data": [
    { "id": 10, "table_section_id": 1, "name": "Meja 1", "order_number": null, "order_in": null, "status": null },
    { "id": 11, "table_section_id": 1, "name": "Meja 2", "order_number": "NOSBE...", "order_in": "2026-10-12T14:00:00+07:00", "status": "pending" }
  ]
}
```

- `order_number`/`order_in`/`status` **terisi** kalau meja itu sedang dipakai, `null` kalau kosong — FE bisa langsung tentukan tampilan (misal warna hijau/merah) dari field ini tanpa query tambahan.

## Alur Implementasi

Endpoint **`-all`** yang dipakai render peta meja utama (List Table page) — perlu tahu meja mana yang terisi untuk ditampilkan beda warna/status. Endpoint tanpa `-all` dipakai di konteks yang murni butuh meja kosong saja (mis. dropdown pilih meja saat pindah/buat order baru), supaya tidak perlu filter manual di FE.

## Sumber

- `app/Http/Controllers/OrderController.php` — `ListTableBySection()`, `ListTableBySectionAll()`.
- `app/Services/OrderServices.php` — `listTableBySection()`, `listTableBySectionAll()`.
- Frontend: `src/modules/dashboard/order/orderServices.ts`.

## Status

Didokumentasikan 2026-10-12, berdasar pembacaan kode langsung (belum ada verifikasi ulang lewat HTTP request live di sesi ini).
