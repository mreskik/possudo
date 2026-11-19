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
              "type_id": 1,
              "mdr": "0.00",
              "open_cash_drawer": false,
              "group_payment_id": 10,
              "color_theme": "ewaf",
              "use_authorization": false,
              "fixed_amount": "0.00",
              "payment_gateway_code": null,
              "flag_mandatory_note": true,
              "type_name": "CASH"
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
              "type_id": 2,
              "mdr": "0.70",
              "open_cash_drawer": false,
              "group_payment_id": 1,
              "color_theme": "feaw",
              "use_authorization": false,
              "fixed_amount": "0.00",
              "payment_gateway_code": "MIDTRANS_QRIS",
              "flag_mandatory_note": false,
              "type_name": "CARD"
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
              "type_id": 2,
              "mdr": "1.00",
              "open_cash_drawer": false,
              "group_payment_id": 16,
              "color_theme": "#ffffff",
              "use_authorization": false,
              "fixed_amount": "0.00",
              "payment_gateway_code": null,
              "flag_mandatory_note": false,
              "type_name": "CARD"
            }
          ]
        }
      ]
    }
  ]
}
```

### Mapping Kolom `payment_methods[]`

**(2026-11-17, direvisi)** SELECT eksplisit (BUKAN lagi `mpm.*`) — 4 kolom tabel `mr_payment_method` SENGAJA dikeluarkan dari response endpoint ini (keputusan user): `coa_account_id`, `printer_count`, `verification_code_mandatory`, `card_number_code_mandatory`. Kolom-kolomnya **tetap ada di tabel DB** dan tetap dipakai modul lain (mis. `coa_account_id` buat resolve jurnal), cuma tidak dikirim lewat endpoint Payment Method ini. `flag_all_visit_purpose` juga tidak dikirim di response, TAPI beda dari 4 kolom di atas — flag ini **DIBACA sebagai filter** (lihat bagian "Filter visit_purpose_id" di bawah), bukan sekadar dibuang.

**⚠️ 2 bug ditemukan & diperbaiki (2026-11-19)**, lihat section "Catatan Bug Fix" di bawah untuk detail:
1. `open_cash_drawer`/`use_authorization`/`flag_mandatory_note` sebelumnya keluar sebagai `0`/`1` (integer), bukan `true`/`false` (boolean JSON) — sudah diperbaiki, sekarang boolean murni.
2. Field type di dalam `payment_methods[]` sebelumnya **hilang total** (bug duplikasi alias SELECT) — sudah diperbaiki, sekaligus **disingkat** (keputusan user, 2026-11-19) jadi `type_id`/`type_name` (BUKAN lagi `payment_method_type_id`/`payment_method_type_name` penuh, biar gak redundan sama header `Type` 1 tingkat di atasnya) dan dipindah ke akhir object.

Field yang tersisa di response:

| Field | Tipe | Keterangan |
|---|---|---|
| `id` | int | PK payment method |
| `name` | string | Nama tampil |
| `code` | string | Kode internal |
| `type_id` | int | FK ke type (1=CASH, 2=CARD, dst — sama sumber `payment_method_type_id` yang dipakai jadi header `Type` di tree, cuma nama field disingkat di sini) |
| `mdr` | string (numeric) | Merchant Discount Rate (persen), dipakai hitung `mdr_amount` saat payment dibuat |
| `open_cash_drawer` | bool | Buka cash drawer otomatis saat payment ini dipilih |
| `group_payment_id` | int, nullable | FK ke group (sumber header `Group` di tree) — `null` buat payment method tanpa group (mis. CASH) |
| `color_theme` | string, nullable | Warna tombol di UI kasir |
| `use_authorization` | bool | Perlu otorisasi tambahan (mis. supervisor) sebelum dipakai |
| `fixed_amount` | string (numeric) | Biaya tetap tambahan per transaksi (beda dari `mdr` yang persentase) |
| `payment_gateway_code` | string, nullable | Kode referensi ke payment gateway pihak ketiga (mis. `"MIDTRANS_QRIS"`), `null`/kosong kalau payment method ini tidak lewat gateway (Cash, kartu manual, dst) |
| `flag_mandatory_note` | bool (baru, 2026-11-17; **non-nullable sejak 2026-11-19**) | Cermin dari `master_payment_method.flag_mandatory_note` sudocore2 — kalau `true`, kasir wajib isi catatan saat memilih payment method ini. Kolom sumbernya di DB nullable, tapi **NULL dinormalisasi jadi `false`** di sini (keputusan user, 2026-11-19) — FE cukup cek `true`/`false`, gak perlu handle `null` sebagai kemungkinan ketiga. Sama pola normalisasi yang sudah terjadi otomatis di sync pull APIANDORDER→sudocore2 (`bun`/`pgx` auto-scan `NULL` jadi `false` buat kolom `bool` biasa). **Belum ada validasi/pemakaian apapun di POS** (sisi sudocore2 juga mendelegasikan validasi ini ke "sisi yang memproses transaksi", yang di POS belum diimplementasikan) — field ini baru sebatas data yang ikut ke-sync |
| `type_name` | string (baru, 2026-11-19) | Nama type (mis. `"CASH"`, `"CARD"`) — field bantu dari JOIN `mr_payment_method_type`, sebelumnya cuma ada di header tree, sekarang sengaja ikut dobel di tiap method juga (keputusan user) |

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

## Catatan Bug Fix (2026-11-19)

Ditemukan saat user tanya kenapa `flag_mandatory_note` selalu `null` di contoh dokumentasi — ternyata ada 2 bug aktif, keduanya di `MasterController::GetPaymentMethod()`/`GroupPaymentMethodTree()`:

**Bug 1 — `open_cash_drawer`/`use_authorization`/`flag_mandatory_note` keluar sebagai `0`/`1`, bukan boolean.** `DB::select()` raw query ke MySQL (koneksi POS = `DB_CONNECTION=mysql`) **TIDAK** auto-cast kolom `tinyint(1)`/`boolean` ke tipe `bool` PHP (beda dari Eloquent yang auto-cast lewat `$casts`) — sama pola bug yang sebelumnya sudah ketemu & diperbaiki di `GetStationList()` (`auto_cut`/`cash_drawer`). Dibuktikan via `php artisan tinker` langsung ke query sungguhan: `open_cash_drawer` keluar `int(0)`, bukan `bool(false)`. **Fix**: cast manual `(bool)` untuk ketiga field (`open_cash_drawer`/`use_authorization`/`flag_mandatory_note`).

**Penyesuaian tambahan (2026-11-19, keputusan user, terpisah dari bug fix di atas)** — `flag_mandatory_note` awalnya sempat dipertahankan nullable (cuma di-cast kalau DB-nya gak `NULL`), TAPI user memutuskan supaya `NULL` juga dinormalisasi jadi `false` (sama pola normalisasi yang kebetulan sudah otomatis terjadi di sync pull APIANDORDER→sudocore2, `bun`/`pgx` auto-scan `NULL` jadi `false` buat kolom `bool` biasa — dicek lewat reproduction script Go langsung ke row `flag_mandatory_note IS NULL` di DB, gak error, hasilnya `false`). Alasannya: field ini semantiknya "wajib isi catatan atau enggak" — `NULL` (belum pernah di-set) dan `false` (eksplisit gak wajib) sama-sama berarti "gak wajib", FE gak perlu handle 3 kemungkinan. **Field ini sekarang selalu boolean, TIDAK PERNAH `null` lagi** — cast-nya gak dikondisikan null-check lagi, langsung `(bool)` tanpa syarat, sama persis `open_cash_drawer`/`use_authorization`.

**Bug 2 — field type hilang total dari tiap `payment_methods[]`.** Root cause: query SELECT kolom type-nya **2 kali** dengan alias SAMA — `mpm.payment_method_type_id` (field asli milik payment method) DAN `mpmt.id as payment_method_type_id` (buat header `Type` di tree, dari JOIN `mr_payment_method_type`). Di PHP, `DB::select()` balikin `stdClass` per baris — SELECT kedua dengan nama kolom sama **menimpa** SELECT pertama (key duplikat, bukan 2 field terpisah). Lalu `GroupPaymentMethodTree()` meng-`unset($row->payment_method_type_id, ...)` dengan asumsi field itu cuma "kolom bantu" yang sudah dipindah ke header tree — padahal (karena ketimpa duplikat SELECT di atas) itu **satu-satunya** instance kolom itu yang ada, jadi ikut kehapus dari `payment_methods[]` juga. **Fix + penyesuaian field (keputusan user, 2026-11-19)**: alias `mpmt.id as payment_method_type_id` diganti jadi `mpm.payment_method_type_id as type_id` (nama disingkat, gak lagi bentrok sama header tree), `mpmt.name as payment_method_type_name` diganti jadi `mpmt.name as type_name` — dan KEDUANYA (`type_id`/`type_name`) sengaja **TIDAK** di-`unset()` lagi di `GroupPaymentMethodTree()` (field bantu group — `payment_method_group_id`/`payment_method_group_name` — tetap di-`unset()` seperti semula, cuma type yang sekarang sengaja dobel: ada di header tree DAN di tiap method).

**Tervalidasi live (2026-11-19)** via `php artisan tinker`, panggil `MasterController::GetPaymentMethod()` langsung ke data DB dev asli:
- Sebelum fix: `{"open_cash_drawer":0,"use_authorization":0,"flag_mandatory_note":null}`, dan field type tidak ada sama sekali di object `payment_methods[]`.
- Sesudah fix boolean+field type: `{"open_cash_drawer":false,"use_authorization":false,"flag_mandatory_note":null}`, `"type_id":1,...,"type_name":"CASH"` muncul benar di tiap `payment_methods[]` (sesuai type-nya masing-masing), header tree (`payment_method_type_id`/`payment_method_type_name` 1 tingkat di atas) tidak berubah.
- Set manual `flag_mandatory_note=1` dan `use_authorization=1` di DB (payment method id=2, "CASH") → response balik `"flag_mandatory_note":true`, `"use_authorization":true` — payment method lain yang gak diubah (id=63, "MANDIRI CARD") tetap `false`/`null` apa adanya, konfirmasi cast-nya gak nge-mix antar baris.
- **Sesudah normalisasi NULL→false**: set `flag_mandatory_note=NULL` di DB (payment method id=2, "CASH") → response balik `"flag_mandatory_note":false` (bukan `null` lagi). Data test dibiarkan `NULL` setelah verifikasi (itu kondisi default/awal, bukan sisa data test yang perlu di-revert).
- `php -l` bersih di semua tahap.

**Dampak FE**: field type sekarang ada di tiap `payment_methods[]` dengan nama **`type_id`/`type_name`** (BUKAN `payment_method_type_id`/`payment_method_type_name` — nama itu tetap dipakai tapi cuma di header tree, 1 tingkat di atas `payment_methods[]`). Sebelumnya field type di dalam method selalu `undefined`/hilang total, jadi ini breaking addition (field baru muncul), bukan rename dari field yang sebelumnya sudah dipakai FE. `open_cash_drawer`/`use_authorization`/`flag_mandatory_note` sekarang boolean JSON murni (`true`/`false`, **TANPA `null` sama sekali**) — kalau ada FE yang sebelumnya baca sebagai angka (`=== 1`/`=== 0`) harus diganti ke `=== true`/`=== false`, dan kalau ada FE yang sempat nulis handling khusus buat `flag_mandatory_note === null`, itu sekarang gak akan pernah kejadian lagi (aman dihapus).

## Alur Implementasi

Dipanggil saat halaman Payment dibuka, `visit_purpose_id` dari order yang sedang diproses. UI biasanya render sebagai tab (Type) → sub-tab/dropdown (Group) → tombol pilihan (Method).

## Sumber

- `app/Http/Controllers/MasterController.php` — `GetPaymentMethod()`, `GroupPaymentMethodTree()` (private).
- Frontend: `src/services/MasterServices.ts` (`getPaymentMethodList`).

## Status

Didokumentasikan 2026-10-12, berdasar pembacaan kode langsung. **2026-11-19**: 2 bug ditemukan & diperbaiki (lihat "Catatan Bug Fix" di atas) — tervalidasi live via `php artisan tinker` langsung manggil controller ke data DB dev asli (bukan HTTP request penuh, tapi behavior-nya identik karena cuma manggil method yang sama).
