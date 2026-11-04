# Order List

## By Table Section (New) — BARU, 2026-11-04

```
GET /api/orderlist/by-tablesection-new/{tablesection_id}
```

**Endpoint TERPISAH dari `By Table Section` di bawah — TIDAK menggantikan/mengubah apa pun di endpoint lama.** Dibuat karena endpoint lama gak punya konsep "status visual meja" (Available/Booked/Dining/Billed) dan info order-nya kurang (cuma 3 field flat buat dinein). Response SELALU dibungkus **object** (bukan array polos lagi) berisi info table section + `list`.

**Perbedaan kunci dari endpoint lama:**
- Response level atas object `{id, name, type, list}`, bukan array polos.
- `order_source = 'pos'` **SELALU difilter di KEDUANYA** (dinein maupun takeaway) — beda dari endpoint lama yang cabang dinein-nya gak ada filter ini sama sekali (lihat Ringkasan di bawah).
- Field order diperkaya: `terminal_name` (JOIN `mr_terminal`), `created_by_name` (JOIN `mr_user.fullname`), `member_name` (JOIN `mr_member.name`) — semua `LEFT JOIN`, nama jadi `null` kalau relasinya kosong/terhapus, baris tetap muncul.
- Dinein: tiap meja punya `status_table` (lowercase: `available`/`booked`/`dining`/`billed`) dan `order_detail` berupa **object tunggal atau `null`** (BUKAN array — 1 meja dipastikan maks 1 order aktif, ada guard "This table already has a pending order!" di `SaveOrder()`).

### A. `type === 'dinein'` — `list` = daftar MEJA

Tiap meja = **semua kolom `mr_table`** (`table_id`/`table_name` + `type`/`height`/`width`/`pos_x`/`pos_y`/`table_seat`/`minimum_billing`/`available_for_book`, field `type` di sini = bentuk meja "square"/"round", BEDA level dari `type` section) + `status_table` + `order_detail`.

**Tabel logic `status_table`:**

| `status_table` | Kondisi |
|---|---|
| `available` | `order_detail = null` — gak ada order aktif di meja itu |
| `booked` | ada order, `total_item == 0` — order dibuat, belum pilih menu |
| `dining` | ada order, `total_item > 0`, `print_ke == 0` — ada menu, bill belum/belum lagi di-print |
| `billed` | ada order, `total_item > 0`, `print_ke > 0` — bill udah pernah di-print, belum ada item baru susulan |

Contoh real (`table_section_id=27`, "Full Service", 2 meja — Meja I sempat di-test sampai status `billed` lalu dikembalikan `available` setelah verifikasi, Meja II kosong dari awal):
```json
{
  "code": 0,
  "data": {
    "id": 27,
    "name": "Full Service",
    "type": "dinein",
    "list": [
      {
        "table_id": 113,
        "table_name": "Meja I",
        "type": "square",
        "height": 80,
        "width": 80,
        "pos_x": 120,
        "pos_y": 389,
        "table_seat": 21,
        "minimum_billing": "1221212.00",
        "available_for_book": 1,
        "status_table": "available",
        "order_detail": null
      },
      {
        "table_id": 124,
        "table_name": "Meja II",
        "type": "round",
        "height": 79,
        "width": 79,
        "pos_x": 424,
        "pos_y": 221,
        "table_seat": 10,
        "minimum_billing": "100000.00",
        "available_for_book": 1,
        "status_table": "available",
        "order_detail": null
      }
    ]
  }
}
```

Contoh `order_detail` terisi (data test "Meja I" sebelum dibersihkan, status `dining` — `total_item=4`, `print_ke=0`):
```json
{
  "table_id": 113,
  "table_name": "Meja I",
  "type": "square",
  "height": 80,
  "width": 80,
  "pos_x": 120,
  "pos_y": 389,
  "table_seat": 21,
  "minimum_billing": "1221212.00",
  "available_for_book": 1,
  "status_table": "dining",
  "order_detail": {
    "order_number": "TESTDOCSTATUSTABLE001",
    "order_name": "Test Status Table",
    "order_type": "dinein",
    "status": "pending",
    "order_in": "2026-11-04 ...",
    "pax": 2,
    "total_item": 4,
    "total_billing": "0.00",
    "print_ke": 0,
    "terminal_id": 3,
    "terminal_name": null,
    "created_by": 1,
    "created_by_name": null,
    "member_id": null,
    "member_name": null
  }
}
```
(`terminal_name`/`created_by_name` `null` di contoh ini karena data test gak dikaitkan ke baris `mr_terminal`/`mr_user` yang valid — bukan bug, murni data test seadanya. Kalau relasinya valid, field itu bakal keisi nama beneran.)

### B. `type === 'takeaway'` — `list` = daftar ORDER langsung

Gak ada konsep meja/`status_table` — tiap baris SAMA field dengan `order_detail` di atas, flat (gak ada `table_id`/dll, karena takeaway emang gak wajib pilih meja).

Contoh real (`table_section_id=18`, "Quick Service", gak ada order pending saat ini):
```json
{ "code": 0, "data": { "id": 18, "name": "Quick Service", "type": "takeaway", "list": [] } }
```

### Perubahan existing yang MENYERTAI endpoint ini — `print_ke` sekarang di-reset saat SaveOrder()

**Supaya `status_table` akurat**, `OrderServices::SaveOrder()` (jalur "tambah pesanan ke order existing", bukan jalur bikin order baru) sekarang **reset `print_ke` ke `0`** kalau request itu nambah **item BARU** (elemen di `listOrder` yang belum punya `ulid`) — SEBELUM, `print_ke` cuma bisa naik, gak pernah balik 0 lagi walau order ditambah menu baru lagi setelah bill-nya pernah di-print, bikin `status_table` nyangkut `billed` padahal ada menu baru yang belum ke-bill.

- **Trigger HANYA kalau ada item baru** — request yang CUMA update item yang SUDAH ADA (ubah qty/promo/discount, `ulid` sudah ada) **TIDAK** mereset `print_ke` (bill lama masih dianggap valid secara substansi).
- **Konsekuensi yang disengaja**: label "COPY n" di re-print bill (`PrintServices.php`, TIDAK diubah) ikut mulai dari "COPY 1" lagi setelah reset — bukan bug, karena bill lama udah gak merepresentasikan isi order terbaru.
- Dikonfirmasi lewat baca kode (`PrintServices.php`, seluruh codebase) bahwa `print_ke` SEBELUM perubahan ini cuma dipakai buat label cetak "COPY n" — **tidak ada logic payment lain** yang membaca nilainya buat keputusan, jadi perubahan ini aman dari sisi itu.

### Sumber (endpoint baru + perubahan `print_ke`)

- `app/Http/Controllers/OrderlistController.php` — `getOrderlistNew()`.
- `app/Services/OrderlistServices.php` — `getOrderListNew()`, `resolveStatusTable()` (private helper).
- `app/Services/OrderServices.php` — `SaveOrder()`, variabel `$hasNewItem` (blok "tambah pesanan ke order existing").

### Status

**Tervalidasi live (2026-11-04)**: dites via `curl`+`php artisan tinker` ke POS Laravel lokal.
- `GET by-tablesection-new/27` (dinein) → shape object `{id,name,type,list}` benar, 2 meja, `status_table` awal `available` (gak ada order aktif).
- `GET by-tablesection-new/18` (takeaway) → `list: []`, gak ada field meja/`status_table` di struktur ini.
- Order test dibuat manual di Meja I (`total_item=0`) → `status_table` jadi `"booked"`. Diupdate `total_item=3, print_ke=0` → jadi `"dining"`. Diupdate `print_ke=1` → jadi `"billed"`.
- `OrderServices::SaveOrder()` dipanggil manual dengan `listOrder` berisi 1 item BARU (gak ada `ulid`) buat order yang `print_ke`-nya lagi `1` → setelah dipanggil, `print_ke` DB jadi `0`, dan `status_table` ikut balik jadi `"dining"` lagi (dikonfirmasi lewat endpoint, bukan cuma baca DB).
- `SaveOrder()` dipanggil manual LAGI, kali ini `listOrder` cuma 1 item **existing** (ada `ulid`, cuma ubah qty) tanpa item baru sama sekali, `print_ke` di-set manual ke `2` sebelumnya → setelah dipanggil, `print_ke` TETAP `2` (TIDAK direset) — konfirmasi trigger "cuma kalau ada item baru" bekerja benar.
- Data test (order `TESTDOCSTATUSTABLE001` + detail-nya) dihapus setelah verifikasi, Meja I dikonfirmasi balik `status_table: "available"`.

**Ringkasan beda behavior 2 endpoint di bawah (dikonfirmasi baca kode langsung, 2026-11-04)** —
**TIDAK sekadar "versi scoped vs versi semua"**, ada 3 perbedaan nyata:
1. **Shape response beda total buat dine-in** — `by-tablesection` (dine-in) balikin **baris meja**
   (`mr_table`), `all-pending` SELALU balikin **baris order** (`tr_order` lengkap), gak peduli
   section asalnya dine-in atau takeaway.
2. **Percabangan logic by `type` HANYA ada di `by-tablesection`** — `all-pending` cuma 1 bentuk
   query, gak ada percabangan takeaway/dinein.
3. **Filter `order_source = 'pos'` TIDAK KONSISTEN** — `all-pending` SELALU filter `pos` (order
   kiosk/mobile gak ikut). `by-tablesection` filter `pos` **HANYA di cabang takeaway**; cabang
   **dinein TIDAK ADA filter `order_source` sama sekali** (`OrderlistServices.php` baris 24-34) —
   kalau ada order dari kiosk/mobile yang nempel ke meja dine-in, itu AKAN ikut muncul di
   `by-tablesection`, padahal TIDAK AKAN muncul di `all-pending`. Ini gap behavior nyata, bukan
   bug yang sudah diperbaiki — kalau lagi debug data "kok beda antara 2 endpoint ini", cek dulu
   `order_source` order yang dicurigai.
4. **Error handling bermasalah di KEDUA method** (`OrderlistServices.php`, `catch (\Throwable $e)
   { return $e; }`) — kalau exception, fungsi balikin **objek Exception itu sendiri** sebagai
   `$orderList`/return value, BUKAN pesan error yang di-handle rapi. Response API jadi coba
   serialize object Exception ke JSON (hasilnya kemungkinan aneh/kosong), bukan
   `{"code":100,"message":"..."}` yang biasa. Belum diverifikasi persis bentuk JSON-nya kalau
   kejadian (belum sengaja dipicu error saat dokumentasi ini ditulis).

## By Table Section

```
ANY /api/orderlist/by-tablesection/{tablesection_id}
```

Response (struktur beda tergantung `tablesection.type`):

**Takeaway** — balikin **baris `tr_order`** langsung (`order_source='pos'`, status `pending`/`hold`), tidak ada konsep "meja". Query-nya **TIDAK join ke `mr_table`/`table_id` sama sekali** (cuma filter `order_source`+`table_section_id`+`status`) — jadi order takeaway yang `table_id`-nya `NULL` (wajar, takeaway gak wajib pilih meja) **tetap muncul normal**, `table_id` gak relevan di cabang ini. Section takeaway tanpa order pending apa pun → `data: []` (array kosong, bukan error).

Contoh real **tanpa order** (`table_section_id=18`, "Quick Service", kondisi normal saat dokumentasi ini ditulis):
```json
{ "code": 0, "data": [] }
```

Contoh real **dengan 1 order pending, `table_id: null`** (data test dibuat khusus buat dokumentasi ini, section sama `id=18`, sudah dihapus setelah verifikasi):
```json
{
  "code": 0,
  "data": [
    {
      "order_number": "TESTDOCTAKEAWAY001",
      "payment_number": null,
      "dayshift_ulid": "TESTDOC",
      "branch_id": 27,
      "terminal_id": 3,
      "order_name": "Test Takeaway Doc",
      "customer_phone_number": null,
      "order_source": "pos",
      "order_type": "takeaway",
      "table_section_id": 18,
      "table_id": null,
      "total_batch": 1,
      "order_date": "2026-11-04",
      "order_queue": 1,
      "order_in": "2026-11-04 11:04:32",
      "order_out": null,
      "member_id": null,
      "visit_purpose_id": 0,
      "pax": 1,
      "status": "pending",
      "waiter_name": "Test Kasir",
      "sender_name": "Test",
      "chasier_name": "Test Kasir",
      "delivery_cost": "0.00",
      "order_fee": "0.00",
      "service_charge": "0.00",
      "platform_fee": "0.00",
      "total_item": 1,
      "sub_total": "25000.00",
      "total_discount": "0.00",
      "total_tax": "2500.00",
      "total_billing": "27500.00",
      "rounding_amount": "0.00",
      "flag_inclusive_tax": false,
      "cancel_notes": "",
      "cancel_at": null,
      "cancel_by": null,
      "cancel_print_ke": 0,
      "void_notes": "",
      "void_at": null,
      "void_by": null,
      "void_print_ke": 0,
      "payment_at": null,
      "payment_by": null,
      "print_ke": 0,
      "payment_notes": "",
      "moved_at": null,
      "moved_by": null,
      "created_at": "2026-11-04T04:04:32.000000Z",
      "updated_at": "2026-11-04T04:04:32.000000Z",
      "created_by": null,
      "updated_by": null,
      "sync_at": null
    }
  ]
}
```

**Dine-in** — balikin **baris meja** (`mr_table.*` LENGKAP) + 3 field order hasil `LEFT JOIN` (`order_number`, `order_in`, `status`). Meja yang **belum ada order pending** TETAP MUNCUL (bukan di-exclude), cuma 3 field order itu `null`. Contoh real (`table_section_id=27`):
```json
{
  "code": 0,
  "data": [
    {
      "id": 113,
      "table_section_id": 27,
      "name": "Meja I",
      "type": "square",
      "height": 80,
      "width": 80,
      "pos_x": 120,
      "pos_y": 389,
      "table_seat": 21,
      "minimum_billing": "1221212.00",
      "available_for_book": 1,
      "order_number": "NO312320261102171046",
      "order_in": "2026-11-02 17:10:46",
      "status": "hold"
    },
    {
      "id": 124,
      "table_section_id": 27,
      "name": "Meja II",
      "type": "round",
      "height": 79,
      "width": 79,
      "pos_x": 424,
      "pos_y": 221,
      "table_seat": 10,
      "minimum_billing": "100000.00",
      "available_for_book": 1,
      "order_number": null,
      "order_in": null,
      "status": null
    }
  ]
}
```

- `type === 'takeaway'` — filter `order_source = 'pos'` EKSPLISIT (order kiosk/mobile tidak ikut).
- `type === 'dinein'` — **TIDAK ADA filter `order_source`** (lihat catatan Ringkasan di atas) — order dari channel apa pun (pos/kiosk/mobile) yang nempel ke meja section itu ikut muncul.

## All Pending

```
GET /api/orderlist/all-pending
```

**SEMUA** order `pending`/`hold` dari POS (lintas table section), diurut `created_at DESC`. SELALU balikin **baris `tr_order` lengkap** (TIDAK ada percabangan by tipe section, beda dari `by-tablesection` di atas). Contoh real:
```json
{
  "code": 0,
  "data": [
    {
      "order_number": "NO312320261102171046",
      "payment_number": null,
      "dayshift_ulid": "POS12320261027140122",
      "branch_id": 27,
      "terminal_id": 3,
      "order_name": "Customer Order",
      "customer_phone_number": null,
      "order_source": "pos",
      "order_type": "dinein",
      "table_section_id": 27,
      "table_id": 113,
      "total_batch": 4,
      "order_date": "2026-11-02",
      "order_queue": 2,
      "order_in": "2026-11-02 17:10:46",
      "order_out": null,
      "member_id": null,
      "visit_purpose_id": 1,
      "pax": 21,
      "status": "hold",
      "waiter_name": "Muhammad Al Fathan ",
      "sender_name": "JUSE",
      "chasier_name": "Muhammad Al Fathan ",
      "delivery_cost": "0.00",
      "order_fee": "0.00",
      "service_charge": "0.00",
      "platform_fee": "0.00",
      "total_item": 0,
      "sub_total": "0.00",
      "total_discount": "0.00",
      "total_tax": "0.00",
      "total_billing": "0.00",
      "rounding_amount": "0.00",
      "flag_inclusive_tax": true,
      "cancel_notes": "",
      "cancel_at": null,
      "cancel_by": null,
      "cancel_print_ke": 0,
      "void_notes": "",
      "void_at": null,
      "void_by": null,
      "void_print_ke": 0,
      "payment_at": null,
      "payment_by": null,
      "print_ke": 0,
      "payment_notes": "",
      "moved_at": null,
      "moved_by": null,
      "created_at": "2026-11-02T10:10:46.000000Z",
      "updated_at": "2026-11-04T03:30:28.000000Z",
      "created_by": null,
      "updated_by": null,
      "sync_at": "2026-11-04 10:30:29"
    }
  ]
}
```

- `WHERE order_source = 'pos'` — order dari mobile/kiosk **tidak ikut**. **SELALU di-filter** (beda dari `by-tablesection` dine-in yang gak ada filter ini sama sekali, lihat Ringkasan di atas).
- Dipakai `LockscreenPage.vue` — kasir bisa lihat sekilas order yang masih nyangkut **tanpa perlu login/pilih table section dulu**.

## By Table Section (New) — BARU, 2026-11-04

```
GET /api/orderlist/by-tablesection-new/{tablesection_id}
```

**Endpoint TERPISAH dari `By Table Section` di atas — TIDAK menggantikan/mengubah apa pun di endpoint lama.** Dibuat karena endpoint lama gak punya konsep "status visual meja" (Available/Booked/Dining/Billed) dan info order-nya kurang (cuma 3 field flat buat dinein). Response SELALU dibungkus **object** (bukan array polos lagi) berisi info table section + `list`.

**Perbedaan kunci dari endpoint lama:**
- Response level atas object `{id, name, type, list}`, bukan array polos.
- `order_source = 'pos'` **SELALU difilter di KEDUANYA** (dinein maupun takeaway) — beda dari endpoint lama yang cabang dinein-nya gak ada filter ini sama sekali (lihat Ringkasan di atas).
- Field order diperkaya: `terminal_name` (JOIN `mr_terminal`), `created_by_name` (JOIN `mr_user.fullname`), `member_name` (JOIN `mr_member.name`) — semua `LEFT JOIN`, nama jadi `null` kalau relasinya kosong/terhapus, baris tetap muncul.
- Dinein: tiap meja punya `status_table` (lowercase: `available`/`booked`/`dining`/`billed`) dan `order_detail` berupa **object tunggal atau `null`** (BUKAN array — 1 meja dipastikan maks 1 order aktif, ada guard "This table already has a pending order!" di `SaveOrder()`).

### A. `type === 'dinein'` — `list` = daftar MEJA

Tiap meja = **semua kolom `mr_table`** (`table_id`/`table_name` + `type`/`height`/`width`/`pos_x`/`pos_y`/`table_seat`/`minimum_billing`/`available_for_book`, field `type` di sini = bentuk meja "square"/"round", BEDA level dari `type` section di atas) + `status_table` + `order_detail`.

**Tabel logic `status_table`:**

| `status_table` | Kondisi |
|---|---|
| `available` | `order_detail = null` — gak ada order aktif di meja itu |
| `booked` | ada order, `total_item == 0` — order dibuat, belum pilih menu |
| `dining` | ada order, `total_item > 0`, `print_ke == 0` — ada menu, bill belum/belum lagi di-print |
| `billed` | ada order, `total_item > 0`, `print_ke > 0` — bill udah pernah di-print, belum ada item baru susulan |

Contoh real (`table_section_id=27`, "Full Service", 2 meja — Meja I sempat di-test sampai status `billed` lalu dikembalikan `available` setelah verifikasi, Meja II kosong dari awal):
```json
{
  "code": 0,
  "data": {
    "id": 27,
    "name": "Full Service",
    "type": "dinein",
    "list": [
      {
        "table_id": 113,
        "table_name": "Meja I",
        "type": "square",
        "height": 80,
        "width": 80,
        "pos_x": 120,
        "pos_y": 389,
        "table_seat": 21,
        "minimum_billing": "1221212.00",
        "available_for_book": 1,
        "status_table": "available",
        "order_detail": null
      },
      {
        "table_id": 124,
        "table_name": "Meja II",
        "type": "round",
        "height": 79,
        "width": 79,
        "pos_x": 424,
        "pos_y": 221,
        "table_seat": 10,
        "minimum_billing": "100000.00",
        "available_for_book": 1,
        "status_table": "available",
        "order_detail": null
      }
    ]
  }
}
```

Contoh `order_detail` terisi (data test "Meja I" sebelum dibersihkan, status `dining` — `total_item=4`, `print_ke=0`):
```json
{
  "table_id": 113,
  "table_name": "Meja I",
  "type": "square",
  "height": 80,
  "width": 80,
  "pos_x": 120,
  "pos_y": 389,
  "table_seat": 21,
  "minimum_billing": "1221212.00",
  "available_for_book": 1,
  "status_table": "dining",
  "order_detail": {
    "order_number": "TESTDOCSTATUSTABLE001",
    "order_name": "Test Status Table",
    "order_type": "dinein",
    "status": "pending",
    "order_in": "2026-11-04 ...",
    "pax": 2,
    "total_item": 4,
    "total_billing": "0.00",
    "print_ke": 0,
    "terminal_id": 3,
    "terminal_name": null,
    "created_by": 1,
    "created_by_name": null,
    "member_id": null,
    "member_name": null
  }
}
```
(`terminal_name`/`created_by_name` `null` di contoh ini karena data test gak dikaitkan ke baris `mr_terminal`/`mr_user` yang valid — bukan bug, murni data test seadanya. Kalau relasinya valid, field itu bakal keisi nama beneran.)

### B. `type === 'takeaway'` — `list` = daftar ORDER langsung

Gak ada konsep meja/`status_table` — tiap baris SAMA field dengan `order_detail` di atas, flat (gak ada `table_id`/dll, karena takeaway emang gak wajib pilih meja).

Contoh real (`table_section_id=18`, "Quick Service", gak ada order pending saat ini):
```json
{ "code": 0, "data": { "id": 18, "name": "Quick Service", "type": "takeaway", "list": [] } }
```

### Perubahan existing yang MENYERTAI endpoint ini — `print_ke` sekarang di-reset saat SaveOrder()

**Supaya `status_table` akurat**, `OrderServices::SaveOrder()` (jalur "tambah pesanan ke order existing", bukan jalur bikin order baru) sekarang **reset `print_ke` ke `0`** kalau request itu nambah **item BARU** (elemen di `listOrder` yang belum punya `ulid`) — SEBELUM, `print_ke` cuma bisa naik, gak pernah balik 0 lagi walau order ditambah menu baru lagi setelah bill-nya pernah di-print, bikin `status_table` nyangkut `billed` padahal ada menu baru yang belum ke-bill.

- **Trigger HANYA kalau ada item baru** — request yang CUMA update item yang SUDAH ADA (ubah qty/promo/discount, `ulid` sudah ada) **TIDAK** mereset `print_ke` (bill lama masih dianggap valid secara substansi).
- **Konsekuensi yang disengaja**: label "COPY n" di re-print bill (`PrintServices.php`, TIDAK diubah) ikut mulai dari "COPY 1" lagi setelah reset — bukan bug, karena bill lama udah gak merepresentasikan isi order terbaru.
- Dikonfirmasi lewat baca kode (`PrintServices.php`, seluruh codebase) bahwa `print_ke` SEBELUM perubahan ini cuma dipakai buat label cetak "COPY n" — **tidak ada logic payment lain** yang membaca nilainya buat keputusan, jadi perubahan ini aman dari sisi itu.

### Sumber (endpoint baru + perubahan `print_ke`)

- `app/Http/Controllers/OrderlistController.php` — `getOrderlistNew()`.
- `app/Services/OrderlistServices.php` — `getOrderListNew()`, `resolveStatusTable()` (private helper).
- `app/Services/OrderServices.php` — `SaveOrder()`, variabel `$hasNewItem` (blok "tambah pesanan ke order existing").

### Status

**Tervalidasi live (2026-11-04)**: dites via `curl`+`php artisan tinker` ke POS Laravel lokal.
- `GET by-tablesection-new/27` (dinein) → shape object `{id,name,type,list}` benar, 2 meja, `status_table` awal `available` (gak ada order aktif).
- `GET by-tablesection-new/18` (takeaway) → `list: []`, gak ada field meja/`status_table` di struktur ini.
- Order test dibuat manual di Meja I (`total_item=0`) → `status_table` jadi `"booked"`. Diupdate `total_item=3, print_ke=0` → jadi `"dining"`. Diupdate `print_ke=1` → jadi `"billed"`.
- `OrderServices::SaveOrder()` dipanggil manual dengan `listOrder` berisi 1 item BARU (gak ada `ulid`) buat order yang `print_ke`-nya lagi `1` → setelah dipanggil, `print_ke` DB jadi `0`, dan `status_table` ikut balik jadi `"dining"` lagi (dikonfirmasi lewat endpoint, bukan cuma baca DB).
- `SaveOrder()` dipanggil manual LAGI, kali ini `listOrder` cuma 1 item **existing** (ada `ulid`, cuma ubah qty) tanpa item baru sama sekali, `print_ke` di-set manual ke `2` sebelumnya → setelah dipanggil, `print_ke` TETAP `2` (TIDAK direset) — konfirmasi trigger "cuma kalau ada item baru" bekerja benar.
- Data test (order `TESTDOCSTATUSTABLE001` + detail-nya) dihapus setelah verifikasi, Meja I dikonfirmasi balik `status_table: "available"`.

## Alur Implementasi

`by-tablesection` dipakai di dalam konteks 1 table section spesifik (biasanya dari List Table page, saat drill-down ke 1 section) — **mirip tapi tidak identik** dengan [`LIST TABLE BY SECTION.md`](LIST%20TABLE%20BY%20SECTION.md)/`order/list-table-by-section`: keduanya balikin bentuk serupa untuk dine-in, tapi `orderlist/by-tablesection` juga menangani kasus takeaway secara eksplisit (beda query). `all-pending` dipakai di luar konteks table section sama sekali, murni ringkasan global.

## Sumber

- `app/Http/Controllers/OrderlistController.php` — `getOrderlist()`, `getAllPendingOrders()`.
- `app/Services/OrderlistServices.php` — `getOrderList()`, `getAllPendingOrders()`.
- Frontend: `src/services/OrderListService.ts`.

## Status

Didokumentasikan 2026-10-12, berdasar pembacaan kode langsung (belum ada verifikasi ulang lewat HTTP request live di sesi ini).

**Update 2026-11-04 — tervalidasi live**: dites via `curl` ke POS Laravel lokal (`php artisan serve`). `all-pending` → balikin baris `tr_order` lengkap (contoh real di atas, `order_number: NO312320261102171046`, `status: hold`, `order_source: pos`). `by-tablesection/27` (dine-in) → balikin 2 baris `mr_table` (Meja I dengan order nempel, Meja II tanpa order/`null`), **shape beda total** dari `all-pending` seperti dijelaskan di bagian Ringkasan. `by-tablesection/18` (takeaway, "Quick Service") → `data: []` (gak ada order pending saat test, tapi bentuk array kosong terkonfirmasi, bukan error). 3 perbedaan behavior di bagian Ringkasan (shape, percabangan logic, filter `order_source`) dikonfirmasi langsung dari baca `OrderlistServices.php` baris 11-60, bukan cuma observasi response.
