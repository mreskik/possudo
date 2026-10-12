# Get Detail Brand

```
GET /api/master/brand
```

Detail brand dari branch yang lagi terpasang di POS ini — **gak ada filter/parameter apa pun**, soalnya `mr_branch` lokal cuma pernah punya 1 baris (singleton per-install, sama pola kayak `GET /api/master/branch`, `MasterController::GetBranchDetail()`). Scope-nya sengaja SEMPIT, cuma 4 kolom warna+nama brand (beda dari `GetBranchDetail()` yang balikin semua kolom `mr_branch`).

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

- `brand_name`/`brand_color`/`primary_color`/`secondary_color` — apa adanya dari `mr_branch`, ditarik dari ERP (`master_brand`, migration sudocore2 263) lewat `sync_pull/get_data_branch` (`SetupServices::getDatabranch()`, lihat migration `2026_10_12_000000_add_brand_columns_to_mr_branch.php`). Nullable (`null`) kalau branch belum di-assign brand (`brand_id` kosong di ERP) atau belum pernah sync ulang sejak kolom ini ada.
- `brand_code`/`brand_id` **TIDAK ikut** di response ini (sengaja, scope cuma nama+warna) — walau kolomnya ada juga di `mr_branch`.

Belum pernah install/sync (`mr_branch` kosong) → `{ "code": 100, "message": "brandDetail gak muncul!" }`.

## Sumber

```php
BranchModel::first(['brand_name', 'brand_color', 'primary_color', 'secondary_color']);
```

`mr_branch` — field brand-nya di-refresh tiap kali sync (`sync_pull/get_data_branch`), bukan cuma sekali pas install. Lihat alur lengkap penarikan data brand (sudocore2 → APIANDORDER → POS) di `master_service.go` (APIANDORDER, `GetDataBranch()`, `LEFT JOIN master_brand`).

## Status

Baru dibuat (2026-10-12), kode + route udah ada, **belum tervalidasi live** (belum dites hit endpoint beneran + cek response, dan belum ada branch yang brand_id-nya diisi buat tes data asli).
