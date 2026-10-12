# Sync Group

Base path: `/api/sync-group`

10 endpoint — rangkuman per-kategori dari puluhan endpoint `sync_pull/*` individual (lihat [`SYNC PULL.md`](SYNC%20PULL.md)), dipakai UI checkbox-per-kategori di `SettingPage.vue` tab Sync. **Tidak menggantikan** `sync_pull/*` yang lama (tetap ada, tidak diubah) — ini cuma lapisan orkestrasi tambahan di atasnya, reuse `SetupServices`/`PushDataServices` yang sama persis, bukan duplikasi logic.

**9 dari 10 kategori PULL** dari ERP (arah sama seperti `sync_pull/*`). **`sales` beda arah** — PUSH data lokal ke ERP, reuse `PushDataServices` (6 fungsi yang sama dipakai background job `sync:push`, lihat [`../../INTERNAL/PUSH DATA.md`](../../INTERNAL/PUSH%20DATA.md)).

## Pola umum (9 kategori pull)

```
GET /api/sync-group/{kategori}
```

Response:
```json
{
  "code": 0,
  "data": {
    "get_category_list": { "code": 0, "message": "..." },
    "get_subcategory_list": { "code": 0, "message": "..." }
  }
}
```

- `data` — hasil tiap sub-step individual (key = nama endpoint `sync_pull` yang dipanggil), **urutan dieksekusi SATU-SATU** (bukan paralel), **try/catch PER STEP** — 1 step gagal tidak menghentikan step lain dalam grup yang sama.
- `code` di root — `0` cuma kalau **SEMUA** sub-step sukses, `100` kalau ada minimal 1 yang gagal (cek per-step di `data` buat tau step mana yang gagal).
- Belum pernah install → `{ "code": 100, "message": "branch has not been selected/saved, please complete setup first" }`.

## 9 Kategori Pull

| Kategori | Endpoint | Sub-step (urutan dieksekusi) | Catatan urutan |
|---|---|---|---|
| **Table** | `GET /sync-group/table` | `get_tablesection_list`, `get_table` | `get_tablesection_list` di sini diam-diam juga manggil `getTableSectionPrintCategorySetting()` dulu (disamakan dengan versi lama di `SyncController`) |
| **Promotion** | `GET /sync-group/promotion` | `get_promo_list` → `get_promo_branch` → `get_promo_visit_purpose` → `get_promo_type_member` → `get_promo_category` → `get_promo_sub_category` → `get_promo_item` → `get_promo_day` → `get_promo_time` → `get_promo_apply_to` | 10 sub-step, urutan ini (header dulu baru anak) |
| **Member** | `GET /sync-group/member` | `get_member_type_list`, `get_member_list` | |
| **User** | `GET /sync-group/user` | `get_menu_app` → `get_master_user` → `get_master_role_access` | **`get_menu_app` WAJIB duluan** — `mr_role_access.menu_id` refer ke `mr_menu_app.id`, kalau menu_app belum ke-sync duluan, role_access nunjuk ke ID yang belum ada |
| **Menu** | `GET /sync-group/menu` | `get_category_list` → `get_subcategory_list` → `get_item` → `get_item_conv` → `get_item_package` → `get_item_package_group` → `get_item_package_detail` → `get_item_package_detail_pricelist` → `get_pricelist` → `get_pricelist_detail` → `get_notes_menu_list` → `get_notes_menu_category` → `get_notes_menu_sub_category` → `get_notes_menu_detail` | 14 sub-step. Notes menu ikut di grup ini (bukan checkbox sendiri), header (`get_notes_menu_list`) wajib duluan dari child-nya |
| **Menu Images** | `GET /sync-group/menu-images` | `get_subcategory_images`, `get_item_images` | **Kategori TERPISAH** dari Menu — khusus download file gambar. **WAJIB dijalankan SETELAH Menu** (2 fungsi ini cuma UPDATE kolom gambar baris yang sudah ada, gak pernah insert baris baru) — sengaja terpisah biar user bisa sync data teks doang tanpa nunggu gambar kalau buru-buru |
| **Branch Setting** | `GET /sync-group/branch-setting` | `get_data_branch` → `get_station_list` → `get_terminal` → `get_tax` → `get_visit_purpose` → `get_branch_visit_purpose` → `get_payment_method` → `get_payment_method_visit_purpose` → `get_branch_ops_setting` → `get_master_image_customer_display` → `get_master_image_kiosk` | 11 sub-step |
| **Branch Setting Images** | `GET /sync-group/branch-setting-images` | `get_master_image_customer_display_images`, `get_master_image_kiosk_images` | Pasangan Menu Images — **WAJIB SETELAH Branch Setting** (sama alasan, update kolom gambar baris existing) |
| **Master Setting** | `GET /sync-group/master-setting` | `get_payment_method_type`, `get_payment_method_group` | Data yang jarang berubah, sengaja dipisah dari Branch Setting |

## 10. Sales (arah PUSH, beda dari 9 di atas)

```
GET /api/sync-group/sales
```

Push data transaksi lokal ke ERP — **tidak butuh `mr_branch` check** (beda dari 9 kategori di atas), reuse `PushDataServices` langsung.

Response:
```json
{
  "code": 0,
  "data": {
    "dayshift": { "code": 0, "message": "ok" },
    "dayshift_detail": { "code": 0, "message": "ok" },
    "order": { "code": 0, "message": "ok" },
    "order_detail": { "code": 0, "message": "ok" },
    "order_detail_package": { "code": 0, "message": "ok" },
    "order_payment": { "code": 0, "message": "ok" }
  }
}
```

Urutan 6 sub-step **wajib**: `dayshift` dulu (karena `pos_order.dayshift_ulid` merujuk ke situ) — urutan ini sama persis dengan yang dipakai command `sync:push` (background job) dan `DayShiftServices::EndDay()`. Detail tiap fungsi push ada di [`../../INTERNAL/PUSH DATA.md`](../../INTERNAL/PUSH%20DATA.md).

## Alur Implementasi

Dipakai di `SettingPage.vue` tab Sync — UI checkbox per kategori (9 kategori pull + Sales), tombol "Sync Selected":

1. User centang kategori yang mau di-sync (bisa lebih dari 1).
2. `syncSelectedGroups()` jalan **satu-per-satu** (bukan `Promise.all`) — banyak kategori punya dependensi urutan sendiri (lihat kolom "Catatan urutan" di atas), jadi kategori-kategori dijalankan berurutan sesuai urutan checkbox di UI (bukan paralel).
3. **Urutan antar-kategori yang disarankan** kalau user centang beberapa sekaligus: Branch Setting → Branch Setting Images → Menu → Menu Images → (kategori lain bebas urutan, tidak saling bergantung).
4. Tiap kategori dapat badge status sendiri (loading/success/timeout/error — lihat `SyncStatusBadge.vue`, dibangun ulang 2026-10-12 dari sebelumnya span manual).
5. Tombol "Cancel Sinkron" — `AbortController`, memutus request yang lagi jalan DAN menghentikan loop sebelum lanjut ke kategori berikutnya (tidak perlu nunggu timeout backend kalau internet mati).

## Sumber

- `app/Http/Controllers/SyncGroupController.php` — 10 method.
- `app/Services/SetupServices.php` (9 kategori pull), `app/Services/PushDataServices.php` (Sales).
- `routes/api.php` — group `Route::prefix('sync-group')`.
- Frontend: `src/services/sync/syncServices.ts` (`SyncGroup*` functions, support `AbortSignal`), `src/modules/dashboard/setting/SettingPage.vue` (tab Sync), `src/component/Navbar.vue` (dipanggil juga dari `install()`, badge sync navbar).

## Status

Didokumentasikan 2026-10-12, berdasar pembacaan kode langsung (belum ada verifikasi ulang lewat HTTP request live di sesi ini).
