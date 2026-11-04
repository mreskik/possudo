# View Sales

```
GET /api/sales/view/{order_number}
```

Detail transaksi lengkap — header + daftar payment + breakdown item/package, dipakai untuk detail/struk/dialog cashier di halaman Sales.

Response (struktur, snake_case — konsisten dengan `tr_order` apa adanya, **beda konvensi** dari [`../ORDER/VIEW ORDER.md`](../ORDER/VIEW%20ORDER.md) yang camelCase):

Contoh REAL (`GET /api/sales/view/NO112320261027165250`, order takeaway `paid`, 2 baris `order_detail` — CAPPUCCINO x2, masing-masing dengan 1 sub-item package):
```json
{
  "code": 0,
  "data": {
    "order_number": "NO112320261027165250",
    "payment_number": "PS112320261102172127",
    "dayshift_ulid": "POS12320261027140122",
    "branch_id": 27,
    "terminal_id": 1,
    "order_name": "ik",
    "customer_phone_number": null,
    "order_source": "pos",
    "order_type": "takeaway",
    "table_section_id": 18,
    "table_id": null,
    "total_batch": 5,
    "order_date": "2026-10-27",
    "order_queue": 1,
    "order_in": "2026-10-27 16:52:50",
    "order_out": "2026-11-02 17:21:27",
    "member_id": null,
    "visit_purpose_id": 2,
    "pax": 1,
    "status": "paid",
    "waiter_name": "Reski Kurniawan",
    "sender_name": "JUSE",
    "chasier_name": "Reski Kurniawan",
    "delivery_cost": "0.00",
    "order_fee": "0.00",
    "service_charge": "0.00",
    "platform_fee": "0.00",
    "total_item": 2,
    "sub_total": "59091.00",
    "total_discount": "0.00",
    "total_tax": "5909.00",
    "total_billing": "65000.00",
    "rounding_amount": "0.00",
    "flag_inclusive_tax": null,
    "cancel_notes": "",
    "cancel_at": null,
    "cancel_by": null,
    "cancel_print_ke": 0,
    "void_notes": "",
    "void_at": null,
    "void_by": null,
    "void_print_ke": 0,
    "payment_at": "2026-11-02 17:21:27",
    "payment_by": "Reski Kurniawan",
    "print_ke": 0,
    "payment_notes": "",
    "moved_at": null,
    "moved_by": null,
    "created_at": "2026-10-27T09:52:50.000000Z",
    "updated_at": "2026-11-02T10:22:00.000000Z",
    "created_by": null,
    "updated_by": null,
    "sync_at": "2026-11-02 17:22:00",
    "member_name": null,
    "member_phone_number": null,
    "payment_detail": [
      {
        "ulid": "01M6DWEE5YHGN1NNGCH9FG1VSM",
        "payment_number": "PS112320261102172127",
        "payment_method_id": 2,
        "payment_gateway_order_id": null,
        "payment_amount": "65000.00",
        "change_amount": "0.00",
        "voucher_code": null,
        "created_at": null,
        "updated_at": "2026-11-02 17:22:00",
        "created_by": null,
        "updated_by": null,
        "sync_at": "2026-11-02 17:22:00",
        "info": null,
        "deduct_member_id": null,
        "mdr_amount": "0.00",
        "payment_method_name": "CASH",
        "type": "CASH"
      }
    ],
    "total_change": 0,
    "order_detail": [
      {
        "ulid": "01M65ZKGSCPC7CE308172SJ88J",
        "order_number": "NO112320261027165250",
        "pricelist_detail_id": 95,
        "menu_id": 124,
        "category_id": null,
        "subcategory_id": null,
        "qty": 1,
        "flag_inclusive_tax": 1,
        "tax_id": 10,
        "tax_type": "pb1",
        "price_pos": "30000.00",
        "dpp": "27273.00",
        "discount_percent": "0.00",
        "discount_amount": "0.00",
        "net_dpp": "27273.00",
        "tax_rate": "10.00",
        "tax_amount": "2727.00",
        "total": "30000.00",
        "promo_id": null,
        "is_free_item_promo": 0,
        "notes": null,
        "batch": 3,
        "done_print": 1,
        "print_ke": 1,
        "cancel_notes": "",
        "cancel_at": null,
        "created_at": null,
        "updated_at": "2026-10-30 16:07:16",
        "created_by": null,
        "updated_by": null,
        "sync_at": "2026-10-30 16:07:17",
        "menu_name": "CAPPUCCINO",
        "menu_shortname": "CAPPUCCINO",
        "detail_package": [
          {
            "ulid": "01M65ZKGSCPC7CE308172SJ88K",
            "tr_order_detail_ulid": "01M65ZKGSCPC7CE308172SJ88J",
            "menu_package_id": 101,
            "menu_id": 127,
            "category_id": null,
            "subcategory_id": null,
            "qty": 1,
            "flag_inclusive_tax": 1,
            "tax_id": 10,
            "tax_type": "pb1",
            "price_pos": "5000.00",
            "dpp": "4545.00",
            "discount_percent": "0.00",
            "discount_amount": "0.00",
            "net_dpp": "4545.00",
            "tax_rate": "10.00",
            "tax_amount": "455.00",
            "total": "5000.00",
            "promo_id": null,
            "notes": null,
            "sync_at": "2026-10-30 15:43:16",
            "menu_name": "ICE R CAPPUCCINO",
            "menu_shortname": "ICE R CAPPUCCINO"
          }
        ]
      },
      {
        "ulid": "01M65ZR8Z08R3E5EB7GQGV2X76",
        "order_number": "NO112320261027165250",
        "pricelist_detail_id": 95,
        "menu_id": 124,
        "category_id": null,
        "subcategory_id": null,
        "qty": 1,
        "flag_inclusive_tax": 1,
        "tax_id": 10,
        "tax_type": "pb1",
        "price_pos": "30000.00",
        "dpp": "27273.00",
        "discount_percent": "0.00",
        "discount_amount": "0.00",
        "net_dpp": "27273.00",
        "tax_rate": "10.00",
        "tax_amount": "2727.00",
        "total": "30000.00",
        "promo_id": null,
        "is_free_item_promo": 0,
        "notes": null,
        "batch": 4,
        "done_print": 1,
        "print_ke": 1,
        "cancel_notes": "",
        "cancel_at": null,
        "created_at": null,
        "updated_at": "2026-10-30 16:07:16",
        "created_by": null,
        "updated_by": null,
        "sync_at": "2026-10-30 16:07:17",
        "menu_name": "CAPPUCCINO",
        "menu_shortname": "CAPPUCCINO",
        "detail_package": [
          {
            "ulid": "01M65ZR8Z08R3E5EB7GQGV2X77",
            "tr_order_detail_ulid": "01M65ZR8Z08R3E5EB7GQGV2X76",
            "menu_package_id": 102,
            "menu_id": 126,
            "category_id": null,
            "subcategory_id": null,
            "qty": 1,
            "flag_inclusive_tax": 1,
            "tax_id": 10,
            "tax_type": "pb1",
            "price_pos": "0.00",
            "dpp": "0.00",
            "discount_percent": "0.00",
            "discount_amount": "0.00",
            "net_dpp": "0.00",
            "tax_rate": "10.00",
            "tax_amount": "0.00",
            "total": "0.00",
            "promo_id": null,
            "notes": null,
            "sync_at": "2026-10-30 15:47:16",
            "menu_name": "ICE S CAPPUCCINO",
            "menu_shortname": "ICE S CAPPUCCINO"
          }
        ]
      }
    ]
  }
}
```

(`category_id`/`subcategory_id` di tiap `order_detail`/`detail_package` baris ini `null` murni karena data test-nya gak diisi, bukan field yang selalu kosong.)

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

**Tervalidasi live (2026-11-04)**: dites via `curl` ke POS Laravel lokal (`php artisan serve --port=8098`), `GET /api/sales/view/NO112320261027165250` (order takeaway `paid` real) — response cocok sama struktur yang didokumentasikan, JSON real sudah dimasukkan di atas.
