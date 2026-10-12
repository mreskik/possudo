# Sales List

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
