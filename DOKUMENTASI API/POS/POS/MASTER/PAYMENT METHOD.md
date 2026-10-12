# Payment Method

```
GET /api/master/payment-method/{visit_purpose_id}
```

Payment method yang valid buat 1 visit purpose, **di-nest 3 level**: Type (CASH/CARD/VOUCHER/OTHER) → Group (nama bank buat CARD, "OTHER" generic buat lainnya) → Method.

Response:
```json
{
  "code": 0,
  "data": [
    {
      "payment_method_type_id": 1,
      "payment_method_type_name": "CASH",
      "groups": [
        {
          "payment_method_group_id": null,
          "payment_method_group_name": null,
          "payment_methods": [
            { "id": 1, "name": "Cash", "payment_method_type_id": 1, "group_payment_id": null }
          ]
        }
      ]
    },
    {
      "payment_method_type_id": 2,
      "payment_method_type_name": "CARD",
      "groups": [
        {
          "payment_method_group_id": 5,
          "payment_method_group_name": "BCA",
          "payment_methods": [
            { "id": 10, "name": "BCA Debit", "...": "..." }
          ]
        }
      ]
    }
  ]
}
```

- **`Type` dan `Group` adalah 2 FK independen** (`mpm.type_id`/`mpm.group_payment_id`), **bukan** group merujuk ke type dalam hierarki DB — nesting 3-level ini dibentuk manual di PHP (`GroupPaymentMethodTree()`) setelah fetch flat, bukan dari struktur relasi bertingkat.
- Query di-`ORDER BY` type → group → method, jadi grouping cukup 1 kali loop (bukan perlu sorting ulang di FE).
- `payment_method_group_id`/`name` bisa `null` (payment method tanpa group, mis. CASH) — tetap ada 1 object `groups[]` dengan `null`, bukan di-skip.
- Visit purpose tanpa payment method apa pun → `data: []`.

## Alur Implementasi

Dipanggil saat halaman Payment dibuka, `visit_purpose_id` dari order yang sedang diproses. UI biasanya render sebagai tab (Type) → sub-tab/dropdown (Group) → tombol pilihan (Method).

## Sumber

- `app/Http/Controllers/MasterController.php` — `GetPaymentMethod()`, `GroupPaymentMethodTree()` (private).
- Frontend: `src/services/MasterServices.ts` (`getPaymentMethodList`).

## Status

Didokumentasikan 2026-10-12, berdasar pembacaan kode langsung (belum ada verifikasi ulang lewat HTTP request live di sesi ini).
