# Kiosk Member Check

```
GET /api/kiosk/member/check/{phone_number}
```

Gak butuh token (proteksinya di level IP allowlist, sama kayak endpoint Kiosk lain). Cek nomor HP yang diketik customer udah kedaftar member apa belum.

**Beda dari endpoint Kiosk lain**: ini **live lookup ke ERP** (lewat APIANDORDER → Postgres langsung), **bukan** baca dari tabel sync lokal (`mr_member`). Sengaja gak pakai cache — data member butuh akurat saat itu juga, gak boleh nunggu sync berikutnya.

Response (member ketemu):

```json
{
  "code": 0,
  "data": {
    "id": 5,
    "member_type_id": 3,
    "member_type_name": "Customer",
    "code": "asd",
    "name": "qwe",
    "contact_name": "qweqweqwe",
    "email": "mqew@gmail.com",
    "phone_number": "0896263621423"
  }
}
```

Response (nomor belum kedaftar — **bukan error**, `code` tetap `0`):

```json
{ "code": 0, "data": null }
```

Response gagal (mis. ERP/APIANDORDER gak bisa dihubungi, atau branch belum di-setup):

```json
{ "code": 100, "message": "<pesan error>" }
```

`{phone_number}` — nomor HP apa adanya (gak ada normalisasi format di endpoint ini, dicocokkan persis ke `master_member.phone_number`).

## Alur

```
Kiosk → POS Laravel (MemberServices::CheckByPhone())
      → APIANDORDER (GET /pos/member/:branch_id/by-phone/:phone_number, Authorization: Bearer <branch token>)
      → Postgres (master_member JOIN master_member_type)
```

- Token yang dikirim ke APIANDORDER itu `mr_branch.token` lokal — token yang sama dipakai buat auth `/pos/sync/*` dan `/pos/endday/*` (lihat `SYNC PULL.md`, `MASTER DAYSHIFT JURNAL.md` di sudocore2). Divalidasi APIANDORDER (`middleware.BranchTokenAuth`) terhadap `master_branch.token WHERE id = branch_id`.
- Cuma member `is_active = true` yang ke-return — member nonaktif dianggap "gak ketemu" (`data: null`), sama kayak nomor yang beneran belum pernah kedaftar.
- `master_member` itu **global** di ERP (gak di-scope company/branch) — hasil pencarian gak dibatasi ke branch/company yang manggil.

## Catatan teknis

- `master_member.phone_number` sekarang **unique** buat nilai yang keisi (partial unique index, `NULL`/string kosong dikecualikan — migration `086_alter_table_master_member_unique_phone_number.sql` di sudocore2). Sebelum ini dipasang, sempet ada 4 baris data test yang share 1 nomor sama — udah dibersihin manual duluan sebelum constraint-nya bisa kepasang.
- Handler baru di APIANDORDER: `backend/modules/apipos/member/member_handler.go` — modul terpisah (bukan bagian `master` yang isinya query buat sync pull), karena ini murni live lookup, gak ada sisi "tarik & simpen lokal".

## Tervalidasi live (2026-08-12)

3 skenario dicoba langsung ke APIANDORDER dan lewat Laravel: tanpa token → `401`; token benar + nomor kedaftar → data lengkap balik termasuk `member_type_name` hasil join; nomor gak kedaftar → `code: 0, data: null` (bukan error). Semua pakai data member real yang udah ada, gak ada data test yang perlu dibersihin.
