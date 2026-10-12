# Visit Purpose

```
GET /api/master/visit-purpose
```

Daftar visit purpose yang aktif untuk branch ini (JOIN `mr_branch_visit_purpose` → `mr_visit_purpose`).

Response:
```json
{
  "code": 0,
  "data": [
    { "id": 7, "name": "Dine In" },
    { "id": 8, "name": "Take Away" }
  ]
}
```

- Cuma `id`+`name` — konfigurasi lengkap (service charge, pajak, dll) ada di [`MENU LIST.md`](MENU%20LIST.md) (per visit purpose, digabung dengan pohon menu), bukan di sini.
- Sama bug `catch` block balikin `code => 0` saat error (lihat catatan di [`TABLE SECTION.md`](TABLE%20SECTION.md)).

## Alur Implementasi

Dipakai untuk dropdown/pilihan visit purpose — biasanya dipanggil bareng `menu-list` saat setup awal halaman Order, hasil `id` di sini dipakai untuk filter array `data` dari `menu-list` (field `id` yang sama).

## Sumber

- `app/Http/Controllers/MasterController.php` — `getVisitPurpose()`.
- Frontend: `src/services/MasterServices.ts` (`getVisitPurpose`).

## Status

Didokumentasikan 2026-10-12, berdasar pembacaan kode langsung (belum ada verifikasi ulang lewat HTTP request live di sesi ini).
