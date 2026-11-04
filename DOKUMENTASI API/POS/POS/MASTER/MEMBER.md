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

## Create Member (BARU, 2026-11-04 — ONLINE, butuh koneksi ke APIANDORDER)

```
POST /api/master/member
Content-Type: application/json
```

**SELALU online, SAMA prinsip dengan Member Detail di atas** — dipanggil kasir buat daftarin member baru langsung dari POS, langsung INSERT ke `master_member` ERP (lewat APIANDORDER, yang konek ke Postgres yang sama — bukan proxy HTTP ke endpoint REST sudocore2). **TIDAK ADA fallback simpan ke `mr_member` lokal dulu** — kalau APIANDORDER/jaringan tidak terjangkau, request ini **harus gagal jelas**, bukan "sukses" tapi datanya cuma numpuk lokal nunggu sync (karena `mr_member` cuma cache hasil sync, bukan sumber kebenaran).

Body:
```json
{
  "code": "MBR099",
  "name": "Siti Aminah",
  "gender": "female",
  "phone_number": "081234567899",
  "birth_date": "1998-07-15",
  "description": "Daftar langsung di kasir"
}
```

- `code`, `name`, `phone_number` **WAJIB diisi** (divalidasi di 2 tempat: POS Laravel sebelum dikirim, dan APIANDORDER lagi sebelum insert — defense in depth) — kosong/tidak dikirim ditolak `{"code":100,"message":"code is required"}` (atau `name`/`phone_number` sesuai yang kosong).
- `gender` opsional, cuma terima `"male"`/`"female"` — divalidasi **CHECK constraint di DB** (`master_member_gender_check`, SAMA constraint yang dipakai Create Member manual di sudocore2 `MASTER MEMBER.md`), nilai lain ditolak `{"code":100,"message":"gender must be male or female"}` (APIANDORDER translate pesan constraint-nya, bukan bocorin error Postgres mentah).
- `birth_date` opsional, format **`"YYYY-MM-DD"`** — format salah ditolak `{"code":100,"message":"invalid date format, must be YYYY-MM-DD"}` (divalidasi app-level di APIANDORDER SEBELUM insert, biar error-nya jelas).
- `description` opsional, bebas teks.
- `member_type_id` **DEFAULT ke Member Type "Customer" (`id=3`, HARDCODE — bukan lookup by nama)** — member yang didaftarkan kasir langsung dari POS secara natural adalah customer baru. Sengaja hardcode by `id` (bukan `WHERE name = 'Customer'`): "Customer" adalah data default bawaan sistem yang `id`-nya tidak akan berubah, sementara NAMA-nya berisiko diganti ke depan (mis. jadi "Cust") — lookup by nama malah rawan salah assign kalau itu terjadi. Lihat konstanta `defaultMemberTypeIDCustomer` di `member_handler.go`.
- Field lain di `master_member` (`contact_name`, `email`, `profile_photo_src`, `shift_id`, `attendance_branch_setting`, `pin`) **TIDAK diisi lewat endpoint ini** — di luar scope 6 field yang dibutuhkan kasir, dibiarkan default (`is_active=true`, `tier_level=1`, sisanya `NULL`). Member yang dibuat lewat sini bisa dilengkapi field itu belakangan lewat Update manual di sudocore2.

**Pesan response dalam Bahasa Inggris (2026-11-04)** — konsisten konvensi proyek (chat/komentar kode Indonesia, tapi string response API customer-facing pakai English, lihat juga `MASTER COA.md`/dokumen lain di sudocore2) — berlaku untuk SEMUA pesan yang baru ditulis di endpoint ini (POS maupun APIANDORDER), TIDAK menyentuh pesan di endpoint Member LAMA (Member Detail/List/Type List masih Bahasa Indonesia, scope-nya di luar perubahan ini).

Response sukses:
```json
{ "code": 0, "message": "Member created successfully", "data": { "id": 45 } }
```

**Error koneksi (APIANDORDER/jaringan tidak terjangkau)** → `{"code": 100, "message": "POS is currently offline, unable to create a new member"}` — pola SAMA PERSIS `GetDetail()` (lihat catatan error koneksi di section Member Detail di atas), lewat `MemberServices::Create()` yang juga pakai `connectTimeout(5)`+`timeout(15)` detik + catch `\Illuminate\Http\Client\ConnectionException` khusus.

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

- `app/Http/Controllers/MasterController.php` — `GetMemberList()`, `GetMemberDetail()`, `CreateMember()`, `GetMemberTypeList()`.
- `app/Services/MemberServices.php` — `GetDetail()`/`Create()` (online, APIANDORDER), `BranchAuthHeaderService::build()`.
- APIANDORDER: `backend/modules/apipos/member/member_handler.go` (`GetDetail`, `CreateMember`), `member_service.go`, `member_detail_dto.go`. Route: `GET /pos/member/:branch_id/detail/:member_id`, `POST /pos/member/:branch_id` (`backend/modules/apipos/apipos_router.go`).
- Frontend: `src/composables/useMember.js`.

## Status

**Tervalidasi live** 2026-10-30 — dites langsung via `curl` ke POS Laravel lokal (`GET /api/master/member-detail/{id}`), lintas beberapa member:
- Member dengan order `paid` histori (id=23) → `point`, `tier`, `last_order` (header flat + `item_list[]` + `payment_method_list[]`), `best_seller[]` (5 item terurut qty DESC) semua terisi benar.
- Member belum pernah order (id=6, 16, 32, 1, 5, 11) → `last_order` semua field `null`/`[]`, `best_seller: []`, bukan error.
- `member_id` tidak ketemu (999999) → `{"code":0,"data":null}`, bukan error.
- Frontend (`posv1-vue`, dialog Detail di `MemberPage.vue`) sudah diimplementasikan dan di-build, siap dites manual di browser.

**Create Member — tervalidasi live (2026-11-04)**, dites 2 tahap:
1. **Langsung ke APIANDORDER** (`POST /pos/member/27`, token branch valid): `code`/`name`/`phone_number` kosong masing-masing ditolak jelas. `gender: "other"` → ditolak `"gender harus male atau female"` (bukan error Postgres mentah). `birth_date: "20-03-1995"` → ditolak `"format tanggal tidak valid, harus YYYY-MM-DD"`. Create lengkap semua field → sukses, dikonfirmasi lewat `GET .../detail/:id` data tersimpan benar (code/name/phone_number/birth_date/description semua match). Create cuma 3 field wajib (gender/birth_date/description dikosongkan) → sukses juga.
2. **Lewat POS Laravel end-to-end** (`POST /api/master/member`, `php artisan serve` lokal, `SERVER_ENDPOINT` diarahkan ke APIANDORDER test): validasi wajib lolos di layer POS. Create sukses → `id` baru dikonfirmasi ada di `master_member` ERP via endpoint Detail. Simulasi **APIANDORDER unreachable** (`SERVER_ENDPOINT` diarahkan ke port yang gak jalan) → response `{"code":100,"message":"POS sedang offline, tidak bisa membuat member baru"}`, BUKAN error cURL mentah, dan **dikonfirmasi TIDAK ada baris baru ke-insert** di ERP (request gagal sebelum sampai APIANDORDER).

**Default `member_type_id` — tervalidasi live (2026-11-04, perubahan susulan)**: Create tanpa kirim `member_type_id` sama sekali (field ini emang gak ada di body endpoint ini) → dikonfirmasi lewat `GET /backend/data/master/member/:id` (sudocore2 langsung) hasilnya `"member_type_id":3` — cocok dengan Member Type `"Customer"` yang dicek lebih dulu via `GET /backend/data/master/member-type` (`{"id":3,"name":"Customer","is_active":true}`). Default ini HARDCODE by id (`defaultMemberTypeIDCustomer = 3` di `member_handler.go`), bukan lookup dinamis by nama.

**Pesan English — tervalidasi live (2026-11-04, perubahan susulan)**: semua pesan yang ditulis khusus buat endpoint ini (bukan pesan lama) diganti ke English. Re-test lengkap: `code`/`gender`/`birth_date` invalid di APIANDORDER langsung → `"code is required"`/`"gender must be male or female"`/`"invalid date format, must be YYYY-MM-DD"`. Lewat POS → `"code is required"` (passthrough), sukses → `"Member created successfully"`, simulasi offline → `"POS is currently offline, unable to create a new member"`. Pesan di endpoint Member LAIN (Detail/List/Type List) TIDAK disentuh, masih Bahasa Indonesia seperti sebelumnya.

Semua data test (member id 43, 44, 45, 46, 48, 49 di DB dev) dinonaktifkan (`change-status`) setelah verifikasi — tidak ada endpoint Delete fisik untuk Member, konsisten modul lain.
