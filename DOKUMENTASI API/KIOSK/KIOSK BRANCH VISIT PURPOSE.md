# Kiosk Branch Visit Purpose List

```
GET /api/kiosk/branch-visit-purpose
```

Gak butuh token. Baris `mr_branch_visit_purpose` yang boleh muncul di kanal Kiosk — filter `flag_kiosk = 1`. Minimal (cuma id/nama) — detail lengkap (pajak, biaya, pohon menu) ada di endpoint terpisah.

Response:

```json
{
  "code": 0,
  "data": [
    {
      "id": 187,
      "visit_purpose_id": 2,
      "visit_purpose_name": "TAKEAWAY"
    }
  ]
}
```

- `id` — id baris `mr_branch_visit_purpose` itu sendiri.
- `visit_purpose_id` — FK ke `mr_visit_purpose.id`. **Ini** yang dipakai buat manggil [KIOSK BRANCH VISIT PURPOSE DETAIL.md](./KIOSK%20BRANCH%20VISIT%20PURPOSE%20DETAIL.md) (`GET /api/kiosk/branch-visit-purpose/{id}`), bukan `id`.
- `visit_purpose_name` — `mr_visit_purpose.name`.
