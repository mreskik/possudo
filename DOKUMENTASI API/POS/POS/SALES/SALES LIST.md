# Sales List

## Sales List (New) — BARU, 2026-11-04

```
GET /api/sales/get-sales-list-new?date_start=2026-11-01&date_end=2026-11-04
```

Endpoint BARU terpisah, **TIDAK** menggantikan `get-sales-list` di bawah — behavior/fungsi SAMA (exclude status `pending`/`hold`, JOIN table_section/visit_purpose/payment_method, `GROUP BY payment_number, order_number`), cuma 2 beda:

- **Filter waktu**: `date_start`/`date_end` (`tro.order_date BETWEEN ? AND ?`) — **BUKAN** `dayin_time` dayshift aktif. Artinya endpoint ini bisa lihat histori **lintas** dayshift/hari, beda dari `get-sales-list` yang selalu scoped ke dayshift yang sedang berjalan saat ini saja.
- **Sort**: `ORDER BY tro.created_at DESC` (bukan `order_date DESC`).

Response (shape SAMA dengan `get-sales-list`):

Contoh REAL (`date_start=2020-01-01&date_end=2030-01-01`, 3 order lintas tanggal — 2 `cancel` + 1 `paid`, diurutkan `created_at DESC`):
```json
{
  "code": 0,
  "data": [
    {
      "order_number": "NO312320261103152024",
      "payment_number": null,
      "order_date": "2026-11-03",
      "order_name": "Customer Order",
      "order_source": "pos",
      "table_name": "Quick Service",
      "visit_purpose_name": "DINE IN",
      "total_billing": "187000.00",
      "status": "cancel",
      "payment_method": null,
      "payment_time": null,
      "payment_by": "Reski Kurniawan"
    },
    {
      "order_number": "NO312320261102171046",
      "payment_number": null,
      "order_date": "2026-11-02",
      "order_name": "Customer Order",
      "order_source": "pos",
      "table_name": "Full Service",
      "visit_purpose_name": "DINE IN",
      "total_billing": "0.00",
      "status": "cancel",
      "payment_method": null,
      "payment_time": null,
      "payment_by": "Muhammad Al Fathan "
    },
    {
      "order_number": "NO112320261027165250",
      "payment_number": "PS112320261102172127",
      "order_date": "2026-10-27",
      "order_name": "ik",
      "order_source": "pos",
      "table_name": "Quick Service",
      "visit_purpose_name": "TAKEAWAY",
      "total_billing": "65000.00",
      "status": "paid",
      "payment_method": "CASH",
      "payment_time": "17:21:27",
      "payment_by": "Reski Kurniawan"
    }
  ]
}
```

- Order `cancel` wajar punya `payment_number: null`/`payment_method: null`/`payment_time: null` — order yang dibatalkan sebelum sempat bayar.
- Rentang tanggal tanpa order yang cocok → `"data": []` (bukan error), dikonfirmasi lewat test live.
- Query inti (filter+sort yang beda ditandai):
  ```sql
  SELECT
    tro.order_number, tro.payment_number, tro.order_date, tro.order_name, tro.order_source,
    mrts.name as table_name, mvp.name as visit_purpose_name, tro.total_billing, tro.status,
    GROUP_CONCAT(mpm.name SEPARATOR ', ') as payment_method,
    SUBSTRING_INDEX(tro.payment_at,' ',-1) as payment_time,
    tro.chasier_name as payment_by
  FROM tr_order tro
  LEFT JOIN mr_table_section mrts on mrts.id = tro.table_section_id
  LEFT JOIN mr_visit_purpose mvp on mvp.id = tro.visit_purpose_id
  LEFT JOIN tr_order_payment trd on trd.payment_number = tro.payment_number
  LEFT JOIN mr_payment_method mpm on mpm.id = trd.payment_method_id
  WHERE tro.status != 'pending' AND tro.status != 'hold'
    AND tro.order_date BETWEEN ? AND ?          -- BEDA dari get-sales-list (dayin_time)
  GROUP BY payment_number, order_number
  ORDER BY tro.created_at DESC                   -- BEDA dari get-sales-list (order_date DESC)
  ```

### Sumber (endpoint baru)

- `app/Http/Controllers/SalesController.php` — `GetSalesListNew()`.
- `app/Services/SalesServices.php` — `GetSalesListNew()`.

### Status

**Tervalidasi live (2026-11-04)**: dites via `curl` ke POS Laravel lokal (`php artisan serve --port=8098`).
- `GET get-sales-list-new?date_start=2020-01-01&date_end=2030-01-01` → 3 order real muncul, urutan `created_at DESC` benar, status pending/hold tidak ikut.
- `GET get-sales-list-new?date_start=2099-01-01&date_end=2099-01-02` (rentang tanpa order) → `"data": []`, tidak error.
- `php -l` bersih di semua file yang diedit/ditambah.

```
GET /api/sales/get-sales-list
```

Riwayat transaksi **dayshift yang sedang aktif saat ini** (bukan histori lintas hari/shift) — order yang sudah bukan `pending`/`hold` lagi (`paid`/`cancel`/`void`), sejak `dayin_time` dayshift aktif.

Response:
```json
{
  "code": 0,
  "data": [
    {
      "order_number": "NOSBE...",
      "payment_number": "PAY...",
      "order_date": "2026-10-12",
      "order_name": "Budi",
      "order_source": "pos",
      "table_name": "Indoor",
      "visit_purpose_name": "Dine In",
      "total_billing": "123123.00",
      "status": "paid",
      "payment_method": "Cash, QRIS",
      "payment_time": "14:35:22",
      "payment_by": "Kasir 1"
    }
  ]
}
```

- `payment_method` — **digabung** (`GROUP_CONCAT`) kalau split payment, dipisah koma.
- `payment_time` — diambil dari **bagian terakhir** string `tr_order.payment_at` (`SUBSTRING_INDEX(..., ' ', -1)`) — cuma jam, bukan timestamp lengkap.
- **Tidak ada dayshift aktif** → fungsi error (query `$dayshift->dayin_time` pada `null` object) — kemungkinan error PHP, bukan `{ "code": 100 }` yang jelas.

## Sumber

- `app/Http/Controllers/SalesController.php` — `GetSalesList()`.
- `app/Services/SalesServices.php` — `GetSalesList()`.
- Frontend: `src/services/SalesServices.ts` (`getSalesList`).

## Status

Didokumentasikan 2026-10-12, berdasar pembacaan kode langsung (belum ada verifikasi ulang lewat HTTP request live di sesi ini).
