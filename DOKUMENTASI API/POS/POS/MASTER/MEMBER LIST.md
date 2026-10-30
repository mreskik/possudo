# Member List & Member Type List

## Member List

```
GET /api/master/member-list
```

Response:
```json
{
  "code": 0,
  "data": [
    {
      "id": 101,
      "member_type_id": 2,
      "member_type_name": "Silver",
      "code": "MBR001",
      "name": "Budi Santoso",
      "contact_name": "Budi",
      "email": "budi@example.com",
      "phone_number": "081234567890",
      "is_active": true
    }
  ]
}
```

- Semua member (lintas tipe, aktif maupun tidak) — `LEFT JOIN mr_member_type`, urut `name ASC`. FE filter sendiri kalau cuma mau yang aktif.

## Member Detail (BARU, 2026-10-30 — ONLINE, butuh koneksi ke APIANDORDER)

```
GET /api/master/member-detail/{id}
```

**Beda total dari Member List di atas** — endpoint ini TIDAK baca `mr_member` lokal. Setiap kali dipanggil, POS mengirim request live ke APIANDORDER (lewat `MemberServices::GetDetail`, token branch disisipkan sama seperti `CheckByPhone`), yang lalu query langsung ke Postgres pusat (sudocore2) — bukan proxy REST ke sudocore2, APIANDORDER sudah konek ke DB yang sama.

Response:
```json
{
  "code": 0,
  "data": {
    "id": 101,
    "code": "MBR001",
    "name": "Budi Santoso",
    "contact_name": "Budi",
    "email": "budi@example.com",
    "phone_number": "081234567890",
    "birth_date": "1990-05-12",
    "join_date": "2024-01-20",
    "note": "Alergi kacang",
    "point": "1500",
    "tier": {
      "level": 2,
      "name": "Silver"
    },
    "last_order": {
      "order_number": "ORD-20261028-0007",
      "branch_id": 3,
      "branch_name": "Cabang Bekasi",
      "order_out": "2026-10-28T19:40:00Z",
      "status": "paid",
      "order_type": "dine_in",
      "order_source": "pos",
      "sub_total": "80000.00",
      "total_discount": "5000.00",
      "total_tax": "10000.00",
      "total_billing": "85000.00",
      "item_list": [
        {
          "menu_name": "Cappuccino",
          "promo_name": null,
          "qty": "2",
          "dpp": "28000.00",
          "tax_rate": "10.00",
          "discount_amount": "0.00",
          "total": "56000.00",
          "notes": "less sugar",
          "packages": [
            { "menu_name": "Add Espresso Shot", "promo_name": null, "qty": "1", "dpp": "7000.00", "tax_rate": "10.00", "discount_amount": "0.00", "total": "7000.00", "notes": null }
          ]
        },
        {
          "menu_name": "Croissant",
          "promo_name": "Promo Pagi",
          "qty": "1",
          "dpp": "24000.00",
          "tax_rate": "10.00",
          "discount_amount": "5000.00",
          "total": "22000.00",
          "notes": null,
          "packages": []
        }
      ],
      "payment_method_list": [
        { "payment_method_name": "Cash", "payment_amount": "60000.00" },
        { "payment_method_name": "QRIS", "payment_amount": "25000.00" }
      ]
    },
    "best_seller": [
      { "item_name": "Cappuccino", "category": "Beverage", "sub_category": "Barista Coffee", "qty": "42", "last_purchased_at": "2026-10-28T19:40:00Z" },
      { "item_name": "Caramel Macchiato", "category": "Beverage", "sub_category": "Barista Coffee", "qty": "30", "last_purchased_at": "2026-10-25T14:10:00Z" },
      { "item_name": "Add Espresso Shot", "category": "Addon", "sub_category": "Addon Shot", "qty": "18", "last_purchased_at": "2026-10-20T10:05:00Z" },
      { "item_name": "Croissant", "category": "Food", "sub_category": "Pastry", "qty": "12", "last_purchased_at": "2026-10-18T09:30:00Z" },
      { "item_name": "Americano", "category": "Beverage", "sub_category": "Barista Coffee", "qty": "9", "last_purchased_at": "2026-10-11T16:20:00Z" }
    ]
  }
}
```

- `id` yang tidak ketemu di ERP pusat → `data: null`, bukan error (konsisten pola `CheckByPhone`).
- `birth_date`/`tier.name` bisa `null` (member belum isi tanggal lahir / `tier.level` tidak match setting tier manapun).
- `join_date` **bukan kolom tersendiri** di database manapun — diambil dari `created_at` member (tanggal pertama kali didaftarkan).
- `tier` dan `last_order` **dibungkus nested object tersendiri** (bukan field flat di level atas member) — supaya gak campur dengan field dasar member. Di dalam `last_order` sendiri, field header order (`order_number`, `branch_id`, `branch_name`, `order_out`, `status`, dst) **langsung flat** (TANPA sub-wrapper lagi) — cuma `item_list[]` dan `payment_method_list[]` yang nested array.
- `last_order` = order **TERAKHIR** member yang `status = 'paid'` lintas SEMUA branch (bukan cuma branch ini), **isi LENGKAP** (bukan cuma info kunjungan) — field header (nomor order, branch, waktu, status, tipe/sumber order, sub total/diskon/pajak/total akhir), `item_list[]` (per menu yang dibeli: qty, dpp, tax rate, diskon, total, notes) dengan nested `packages[]` per item (sub-item varian/addon, kalau ada), dan `payment_method_list[]` (metode bayar yang dipakai — bisa lebih dari 1 baris kalau split payment). Member belum pernah order `paid` sama sekali → semua field header `null`, `item_list: []`, `payment_method_list: []`.
- Tidak ada tabel/endpoint "visit" terpisah untuk member — "kunjungan terakhir" cukup dibaca dari `last_order.branch_name`/`order_out`.
- `best_seller` — maksimal **5 item**, terurut `qty` terbanyak (menu UTAMA saja, sub-item package tidak diikutkan), dihitung dari SEMUA order `paid` member sepanjang waktu (tidak ada filter tanggal).
- **Error koneksi (APIANDORDER/jaringan tidak terjangkau)** → `{"code": 100, "message": "POS sedang offline, tidak bisa mengambil data member terkini"}` — pesan ini SENGAJA dibedakan dari error logis APIANDORDER (yang passthrough pesan asli dari server), lewat deteksi `\Illuminate\Http\Client\ConnectionException` khusus di `MemberServices::GetDetail` (beda dari method lain di file itu yang belum punya penanganan ini).
- Timeout eksplisit `connectTimeout(5)`+`timeout(15)` detik — method lain (`CheckByPhone` dkk) di `MemberServices.php` belum punya timeout sama sekali, method ini sengaja lebih defensif karena akan dipanggil interaktif dari halaman Member Detail.

## Member Type List

```
GET /api/master/member-type-list
```

Response:
```json
{ "code": 0, "data": [ { "id": 2, "name": "Silver", "is_active": true } ] }
```

Cuma member type yang `is_active = true`.

## Alur Implementasi

Dipanggil bersamaan (`useMember.js`) di halaman Member dan saat memilih member pada proses pembayaran — `member-type-list` dipakai untuk filter/dropdown tipe, `member-list` untuk pencarian member (biasanya difilter further di FE by nama/kode/nomor HP, bukan lewat parameter search di backend — endpoint ini tidak menerima query filter).

## Sumber

- `app/Http/Controllers/MasterController.php` — `GetMemberList()`, `GetMemberDetail()`, `GetMemberTypeList()`.
- `app/Services/MemberServices.php` — `GetDetail()` (online, APIANDORDER), `BranchAuthHeaderService::build()`.
- APIANDORDER: `backend/modules/apipos/member/member_handler.go` (`GetDetail`), `member_service.go`, `member_detail_dto.go`. Route: `GET /pos/member/:branch_id/detail/:member_id` (`backend/modules/apipos/apipos_router.go`).
- Frontend: `src/composables/useMember.js`.

## Status

Didokumentasikan 2026-10-12, berdasar pembacaan kode langsung (belum ada verifikasi ulang lewat HTTP request live di sesi ini).
