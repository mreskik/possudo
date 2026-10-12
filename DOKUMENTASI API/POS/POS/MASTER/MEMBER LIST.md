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

- `app/Http/Controllers/MasterController.php` — `GetMemberList()`, `GetMemberTypeList()`.
- Frontend: `src/composables/useMember.js`.

## Status

Didokumentasikan 2026-10-12, berdasar pembacaan kode langsung (belum ada verifikasi ulang lewat HTTP request live di sesi ini).
