# View Sales

```
GET /api/sales/view/{order_number}
```

Detail transaksi lengkap — header + daftar payment + breakdown item/package, dipakai untuk detail/struk/dialog cashier di halaman Sales.

Response (struktur, snake_case — konsisten dengan `tr_order` apa adanya, **beda konvensi** dari [`../ORDER/VIEW ORDER.md`](../ORDER/VIEW%20ORDER.md) yang camelCase):
```json
{
  "code": 0,
  "data": {
    "order_number": "NOSBE...",
    "status": "paid",
    "total_billing": "123123.00",
    "payment_by": "Kasir 1",
    "member_name": null,
    "member_phone_number": null,
    "payment_detail": [
      {
        "payment_method_id": 1,
        "payment_method_name": "Cash",
        "type": "CASH",
        "payment_amount": "123123.00",
        "change_amount": "1877.00"
      }
    ],
    "total_change": 1877,
    "order_detail": [
      {
        "ulid": "01hx...",
        "menu_name": "PAKET MERCON",
        "menu_shortname": "Mercon",
        "qty": 1,
        "...": "kolom tr_order_detail lengkap",
        "detail_package": [
          { "ulid": "01hx...", "menu_name": "NASI PUTIH", "...": "..." }
        ]
      }
    ]
  }
}
```

- `payment_by` — alias `chasier_name` (**ditambahkan khusus di sini**, berbeda dari `TrOrderModel::first()` default yang tidak otomatis punya field ini — perlu di-resolve manual, bug lama yang pernah bikin dialog Cashier salah bind ke `waiter_name`).
- `member_name`/`member_phone_number` — resolve manual (query terpisah ke `mr_member`), karena `TrOrderModel::first()` generik **tidak** JOIN otomatis (beda dari `OrderServices::ViewOrder()` yang pakai raw SQL + LEFT JOIN). Dipakai fitur Send WA untuk auto-isi nomor tujuan.
- `total_change` — jumlah `change_amount` dari **semua** baris payment (bukan cuma 1), jaga-jaga kalau ada >1 baris CASH dalam split payment.
- `order_detail[].detail_package` — breakdown sub-item, resolve per-baris (bukan 1 query gabungan) — N+1 query kalau item banyak, tapi scope-nya 1 order spesifik jadi dampaknya kecil.

## Alur Implementasi

Dipanggil saat kasir klik 1 baris di [`SALES LIST.md`](SALES%20LIST.md) untuk lihat detail/cetak ulang/void.

## Sumber

- `app/Http/Controllers/SalesController.php` — `ViewSales()`.
- `app/Services/SalesServices.php` — `viewSales()`.
- Frontend: `src/services/SalesServices.ts` (`ViewSales`).

## Status

Didokumentasikan 2026-10-12, berdasar pembacaan kode langsung (belum ada verifikasi ulang lewat HTTP request live di sesi ini).
