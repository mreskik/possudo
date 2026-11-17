# Payment Method

```
GET /api/master/payment-method/{visit_purpose_id}
```

Payment method yang valid buat 1 visit purpose, **di-nest 3 level**: Type (CASH/CARD/VOUCHER/OTHER) → Group (nama bank buat CARD, "OTHER" generic buat lainnya) → Method.

### Referensi `payment_method_type_id` (`mr_payment_method_type`)

| `id` | `name` |
|---|---|
| 1 | CASH |
| 2 | CARD |
| 3 | VOUCHER |
| 4 | OTHER |
| 5 | WALLET_PAYMENT |

Tabel master lokal (`mr_payment_method_type`), ke-sync lewat `SetupServices::getMasterPaymentMethodType()`. Cermin dari data `master_payment_method_type` sudocore2.

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
          "payment_method_group_id": 10,
          "payment_method_group_name": "CASH GROUP",
          "payment_methods": [
            {
              "id": 2,
              "name": "CASH",
              "code": "CASH",
              "payment_method_type_id": 1,
              "mdr": "0.00",
              "open_cash_drawer": false,
              "group_payment_id": 10,
              "color_theme": "ewaf",
              "use_authorization": false,
              "fixed_amount": "0.00",
              "payment_gateway_code": null,
              "flag_mandatory_note": null
            }
          ]
        }
      ]
    },
    {
      "payment_method_type_id": 2,
      "payment_method_type_name": "CARD",
      "groups": [
        {
          "payment_method_group_id": 1,
          "payment_method_group_name": "QRIS GROUP",
          "payment_methods": [
            {
              "id": 1,
              "name": "QRIS",
              "code": "QRA",
              "payment_method_type_id": 2,
              "mdr": "0.70",
              "open_cash_drawer": false,
              "group_payment_id": 1,
              "color_theme": "feaw",
              "use_authorization": false,
              "fixed_amount": "0.00",
              "payment_gateway_code": "MIDTRANS_QRIS",
              "flag_mandatory_note": null
            }
          ]
        },
        {
          "payment_method_group_id": 16,
          "payment_method_group_name": "MANDIRI GROUP",
          "payment_methods": [
            {
              "id": 63,
              "name": "MANDIRI CARD",
              "code": "MAN-002",
              "payment_method_type_id": 2,
              "mdr": "1.00",
              "open_cash_drawer": false,
              "group_payment_id": 16,
              "color_theme": "#ffffff",
              "use_authorization": false,
              "fixed_amount": "0.00",
              "payment_gateway_code": null,
              "flag_mandatory_note": null
            }
          ]
        }
      ]
    }
  ]
}
```

### Mapping Kolom `payment_methods[]`

**(2026-11-17, direvisi)** SELECT eksplisit (BUKAN lagi `mpm.*`) — 4 kolom tabel `mr_payment_method` SENGAJA dikeluarkan dari response endpoint ini (keputusan user): `coa_account_id`, `printer_count`, `verification_code_mandatory`, `card_number_code_mandatory`. Kolom-kolomnya **tetap ada di tabel DB** dan tetap dipakai modul lain (mis. `coa_account_id` buat resolve jurnal), cuma tidak dikirim lewat endpoint Payment Method ini. `flag_all_visit_purpose` juga tidak dikirim di response, TAPI beda dari 4 kolom di atas — flag ini **DIBACA sebagai filter** (lihat bagian "Filter visit_purpose_id" di bawah), bukan sekadar dibuang. Field yang tersisa di response:

| Field | Tipe | Keterangan |
|---|---|---|
| `id` | int | PK payment method |
| `name` | string | Nama tampil |
| `code` | string | Kode internal |
| `payment_method_type_id` | int | FK ke type (1=CASH, 2=CARD, dst — sumber `payment_method_type_id` yang sama dipakai jadi header `Type` di tree) |
| `mdr` | string (numeric) | Merchant Discount Rate (persen), dipakai hitung `mdr_amount` saat payment dibuat |
| `open_cash_drawer` | bool | Buka cash drawer otomatis saat payment ini dipilih |
| `group_payment_id` | int, nullable | FK ke group (sumber header `Group` di tree) — `null` buat payment method tanpa group (mis. CASH) |
| `color_theme` | string, nullable | Warna tombol di UI kasir |
| `use_authorization` | bool | Perlu otorisasi tambahan (mis. supervisor) sebelum dipakai |
| `fixed_amount` | string (numeric) | Biaya tetap tambahan per transaksi (beda dari `mdr` yang persentase) |
| `payment_gateway_code` | string, nullable | Kode referensi ke payment gateway pihak ketiga (mis. `"MIDTRANS_QRIS"`), `null`/kosong kalau payment method ini tidak lewat gateway (Cash, kartu manual, dst) |
| `flag_mandatory_note` | bool, nullable (baru, 2026-11-17) | Cermin dari `master_payment_method.flag_mandatory_note` sudocore2 — kalau `true`, kasir wajib isi catatan saat memilih payment method ini. **Belum ada validasi/pemakaian apapun di POS** (sisi sudocore2 juga mendelegasikan validasi ini ke "sisi yang memproses transaksi", yang di POS belum diimplementasikan) — field ini baru sebatas data yang ikut ke-sync |

- **`Type` dan `Group` adalah 2 FK independen** (`mpm.payment_method_type_id`/`mpm.group_payment_id`), **bukan** group merujuk ke type dalam hierarki DB — nesting 3-level ini dibentuk manual di PHP (`GroupPaymentMethodTree()`) setelah fetch flat, bukan dari struktur relasi bertingkat.
- Query di-`ORDER BY` type → group → method, jadi grouping cukup 1 kali loop (bukan perlu sorting ulang di FE).
- `payment_method_group_id`/`name` bisa `null` (payment method tanpa group, mis. CASH) — tetap ada 1 object `groups[]` dengan `null`, bukan di-skip.
- Visit purpose tanpa payment method apa pun → `data: []`.

### Filter `visit_purpose_id`

**(2026-11-17, logic baru)** Payment method muncul buat 1 visit purpose kalau **salah satu** dari 2 kondisi ini terpenuhi:
1. **Terdaftar eksplisit** di `mr_payment_method_visit_purposes` (`payment_method_id` + `visit_purpose_id` yang diminta), ATAU
2. **`flag_all_visit_purpose = true`** — payment method ini berlaku di SEMUA visit purpose, gak peduli terdaftar di tabel pivot apa gak.

Implementasi: `JOIN` ke `mr_payment_method_visit_purposes` diubah dari `INNER` jadi `LEFT` (kondisi `visit_purpose_id` dipindah ke `ON`, bukan `WHERE`), lalu `WHERE (mpm.flag_all_visit_purpose = true OR mpmvp.id IS NOT NULL)` — pola sama persis `flag_all_branch` di `apiandorder` (`master_service.go`, `LEFT JOIN master_payment_method_branches` + `WHERE flag_all_branch = true OR mpmd.id IS NOT NULL`).

Sebelum 2026-11-17, kolom `flag_all_visit_purpose` belum ada di POS sama sekali, jadi filter-nya murni lewat tabel pivot (sama seperti `flag_mandatory_note` sekarang — ada di tabel, ikut ke-sync, tapi belum dibaca logic apapun di POS).

**Tervalidasi live (2026-11-17)**: payment method `QRIS` (id=1) diset `flag_all_visit_purpose = true` tanpa baris pivot untuk `visit_purpose_id=999` (fiktif, gak ada relasi apapun) — tetap muncul di hasil query. Payment method lain (`CASH`, `MANDIRI CARD`, `flag_all_visit_purpose` tetap `null`) cuma muncul untuk `visit_purpose_id` yang memang terdaftar di pivot-nya. Data test dikembalikan ke `null` setelah verifikasi.

## Alur Implementasi

Dipanggil saat halaman Payment dibuka, `visit_purpose_id` dari order yang sedang diproses. UI biasanya render sebagai tab (Type) → sub-tab/dropdown (Group) → tombol pilihan (Method).

## Sumber

- `app/Http/Controllers/MasterController.php` — `GetPaymentMethod()`, `GroupPaymentMethodTree()` (private).
- Frontend: `src/services/MasterServices.ts` (`getPaymentMethodList`).

## Status

Didokumentasikan 2026-10-12, berdasar pembacaan kode langsung (belum ada verifikasi ulang lewat HTTP request live di sesi ini).
