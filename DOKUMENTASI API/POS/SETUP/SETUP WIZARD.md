# Setup Wizard

Base path: `/api/setup`

Proses install pertama kali POS di 1 device/branch baru — **sebelum** `mr_branch` lokal terisi. Dipanggil cuma sekali (atau diulang manual kalau mau setup ulang device), beda dari sync rutin (`SYNC/SYNC PULL.md`) yang jalan terus-menerus setelah install.

**3 endpoint ini SATU-SATUNYA yang masih hidup** dari `SetupController.php` — controller itu sebelumnya punya ~27 method (`getStationList`, `getCategoryList`, `getMasterItem`, dkk, pola `/setup/get_*/{branch_id}` dengan username/password), tapi **24 di antaranya dead code** (gak pernah dipanggil frontend manapun) dan sudah dihapus (2026-10-12) — lihat "Catatan historis" di bawah.

## 1. Get Branch List

```
POST /api/setup/get_branch_list
```

Login ke ERP pakai username/password, balikin daftar branch yang bisa dipilih buat di-install di device ini.

**Request:**
```json
{ "username": "admin", "password": "rahasia123" }
```

**Response:**
```json
{
  "code": 0,
  "data": [
    { "Id": 51, "Name": "SUDO BREW - EVENT" },
    { "Id": 14, "Name": "TONAKO BANDUNG" }
  ]
}
```

- Ini **proxy langsung** ke endpoint ERP (`{SERVER_ENDPOINT}/pos/setup/get_branch_list`) — POS cuma meneruskan request/response apa adanya (`SetupController::getBranchList()`), tidak ada logic tambahan di sisi POS.
- `username`/`password` ini kredensial staff ERP (bukan PIN kasir/login harian POS) — cuma dipakai sekali di titik ini untuk autentikasi proses install.
- Error (username/password salah dkk) — pesannya apa adanya dari ERP, `code > 0`.

## 2. Get Data Branch (Install)

```
POST /api/setup/get_data_branch/{branch_id}
```

Setelah branch dipilih dari List — tarik detail lengkap branch itu (termasuk `token` branch, dipakai untuk semua request sync berikutnya) dan **simpan ke `mr_branch` lokal** (truncate+create, bukan upsert — `mr_branch` memang selalu cuma 1 baris).

**Request:**
```json
{ "username": "admin", "password": "rahasia123" }
```

**Response:**
```json
{ "code": 0, "message": "success" }
```

- Data branch yang disimpan: `branch_code`, `branch_name`, `brand_id`/`brand_code`/`brand_name`/`brand_color`/`primary_color`/`secondary_color` (migration POS `2026_10_12_000000_add_brand_columns_to_mr_branch.php`), `address`, `phone`, `printing_header`/`printing_footer`, `company_id`, `token`, plus `logo_header_src`/`image_footer_src` (gambar di-**download** beneran ke `public/img/branch/`, bukan cuma nyimpen path mentah dari ERP — lihat `SetupServices::downloadImage()`).
- `company_id`/`token` WAJIB ada di response ERP (keduanya kolom `NOT NULL` di lokal) — kalau salah satu kosong, gagal eksplisit dengan pesan jelas, bukan error "Integrity constraint violation" yang samar.
- `mr_branch_setting` (domain_receipt, whatsapp_template_text) ikut di-truncate+create di titik yang sama.
- **Fungsi service-nya (`SetupServices::getDatabranch()`) di-SHARE** dengan `SyncController::getDataBranch()` (`sync_pull/get_data_branch`, dipanggil ULANG tiap sync rutin, pakai `token` bukan username/password) — logic simpan-nya SAMA PERSIS, cuma beda cara otentikasi request ke ERP-nya. Detail lengkap kolom ada di [`../SYNC/SYNC PULL.md`](../SYNC/SYNC%20PULL.md).

## 3. Change Status Install

```
GET /api/setup/install_success/{status}
```

Tandai proses install selesai (atau di-reset) — `status` cuma `1` (install sukses) atau `0` (reset/belum install).

**Response:**
```json
{ "code": 0, "message": "success" }
```

- Update `flag_install_status` di `SetupConfigModel` (1 baris, `id = 1`) — dipakai router Vue buat nentuin apakah device ini perlu diarahkan ke halaman Setup atau langsung ke Dashboard.
- `status` selain `1`/`0` — tidak divalidasi eksplisit, diam-diam tidak melakukan apa-apa (bukan error).

## Alur Implementasi

Urutan panggil yang benar di frontend (`SetupPage.vue`):

1. **Login** — form username/password → `getBranchList()` (`services/setup/getBranchList.ts`) → `POST /setup/get_branch_list`. Sukses (`code === 0`) → tampilkan dropdown branch dari `response.data` (field `Id`/`Name`, **PascalCase** — beda dari konvensi `snake_case` yang biasa dipakai endpoint lain, karena ini proxy apa adanya dari ERP).
2. **Pilih branch** — user pilih dari dropdown → `install()` dipanggil dengan `branch_id` terpilih.
3. **Simpan branch** — `GetDataBranch(branch_id, username, password)` (`services/setup/getDataBranch.ts`) → `POST /setup/get_data_branch/{branch_id}`. Gagal (`code !== 0`) → tampilkan toast error, **hentikan** alur (jangan lanjut ke step 4).
4. **Sync data awal** — begitu branch tersimpan, loop panggil seluruh `Sync*` dari `syncServices.ts` (~36 fungsi: `SyncStationList`, `SyncCategoryList`, `SyncItem`, dst — daftar lengkap & detail tiap endpoint ada di [`../SYNC/SYNC PULL.md`](../SYNC/SYNC%20PULL.md)) — ini **BUKAN** endpoint `/setup/*` lagi, sudah pindah ke `sync_pull/*` yang otentikasinya pakai `token` branch yang baru disimpan di step 3.
5. **Tandai install selesai** — semua sync sukses → `StatusInstall(1)` → `GET /setup/install_success/1`. Sukses → hapus `terminal_id`/`terminal_name` dari `localStorage` (device mulai fresh, belum pilih terminal) → redirect ke halaman pilih Terminal.

**Penting buat migrasi FE baru**: step 1-3 (`get_branch_list`, `get_data_branch`) itu **satu-satunya** bagian yang beneran unik buat "Setup Wizard" — step 4 reuse endpoint `sync_pull/*` yang sama dengan sync rutin harian (dokumentasinya ada di folder `SYNC/`, tidak diulang di sini).

## Catatan historis — dead code yang sudah dibersihkan

`SetupController.php` sebelumnya punya ~27 method publik, tapi cuma 3 di atas yang terdaftar di `routes/api.php`. 24 method lain (`getStationList`, `getCategoryList`, `getMasterItem*`, `getMasterPricelist*`, `getMasterPaymentMethod*`, `getMasterBranchVisitPurpose`, `getMasterVisitPurpose`, `getMasterUser`, `getMasterRoleAccess`, `getMenuApp`, `getPromo*`, `getMemberTypeList`, `getMemberList`) **tidak pernah di-route**, murni dead code dari desain awal Setup Wizard yang sempat narik SEMUA master data lewat username/password — pendekatan itu digantikan total oleh jalur `sync_pull/*` (token-based, dipanggil setelah branch tersimpan).

Method-method itu sudah **dihapus** (2026-10-12) dari `SetupController.php`. **Service pendukungnya (`SetupServices.php`) TIDAK ikut dihapus** — ternyata semua method di situ (`getStationList`, `getCategoryList`, dst) masih **aktif dipakai** oleh `SyncController.php` (jalur `sync_pull/*`), cuma dipanggil dengan parameter `token` alih-alih `username`/`password`. Jadi dead code-nya murni di layer controller (`SetupController`), bukan di layer service.

Di sisi frontend, `services/setup/getCategoryList.ts` dan `services/setup/getStationList.ts` yang jadi consumer 24 method dead itu juga sudah dibersihkan — cuma `StatusInstall()` yang dipertahankan (dipakai, lihat di atas).

## Sumber

- `app/Http/Controllers/SetupController.php` — `getBranchList()`, `getDataBranch()`, `ChangeStatusInstall()`.
- `app/Services/SetupServices.php` — `getDatabranch()` (shared dengan `SyncController`).
- `app/Services/ConfigService.php` — `ChangeStatusInstall()`.
- `routes/api.php` — group `Route::prefix('setup')`.
- Frontend: `src/modules/setup/SetupPage.vue`, `src/services/setup/getBranchList.ts`, `src/services/setup/getDataBranch.ts`, `src/services/setup/getCategoryList.ts` (fungsi `StatusInstall`).

## Status

Didokumentasikan 2026-10-12, berdasar pembacaan kode langsung (belum ada verifikasi ulang lewat HTTP request live di sesi ini).
