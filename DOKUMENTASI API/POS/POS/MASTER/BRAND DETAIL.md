# Brand Detail

```
GET /api/master/brand
```

**Baru (2026-10-12), belum ada consumer frontend sama sekali** — endpoint siap pakai, dibuat untuk kebutuhan theming/branding di UI yang belum dibangun.

Scope sempit — cuma 4 kolom (beda dari [`BRANCH DETAIL.md`](BRANCH%20DETAIL.md) yang balikin semua kolom `mr_branch`), tanpa filter/parameter (singleton, `mr_branch` cuma 1 baris).

Response:
```json
{
  "code": 0,
  "data": {
    "brand_name": "SUDO BREW",
    "brand_color": "#1E3A8A",
    "primary_color": "#2563EB",
    "secondary_color": "#F59E0B"
  }
}
```

- `brand_name`/3 warna — apa adanya dari `mr_branch`, ditarik dari ERP (`master_brand`, migration sudocore2 263) lewat `sync_pull/get_data_branch` (lihat [`../../SYNC/SYNC PULL.md`](../../SYNC/SYNC%20PULL.md)).
- `brand_code`/`brand_id` **tidak ikut** di response ini — sengaja, scope cuma nama+warna.
- Belum pernah install → `{ "code": 100, "message": "brandDetail gak muncul!" }`.

## Alur Implementasi

**Belum ada** — endpoint tersedia, tapi belum ada komponen frontend yang memanggilnya. Kandidat pemakaian ke depan: theming dinamis UI (warna brand sebagai CSS variable), atau splash/loading screen yang menampilkan identitas brand.

## Sumber

- `app/Http/Controllers/MasterController.php` — `GetBrandDetail()`.
- `database/migrations/2026_10_12_000000_add_brand_columns_to_mr_branch.php`.

## Status

Didokumentasikan 2026-10-12. Kode selesai & teruji manual (lihat sesi pengembangan terkait), **belum ada consumer FE**, belum ada verifikasi ulang lewat HTTP request live di sesi dokumentasi ini.
