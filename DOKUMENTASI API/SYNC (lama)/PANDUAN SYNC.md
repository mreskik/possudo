# Panduan Sync (Pull & Push)

POS lokal (`posv1-laravel` + `posv1-vue`) nyimpen salinan data master & transaksi sendiri (MySQL `db_pos`), disinkronkan ke server ERP pusat (`SERVER_ENDPOINT`, lewat APIANDORDER/sudocore2) dua arah terpisah:

- **Pull** — tarik master data DARI server KE lokal. Lihat [SYNC PULL.md](./SYNC%20PULL.md).
- **Push** — kirim data transaksi (order) DARI lokal KE server. Lihat [SYNC PUSH.md](./SYNC%20PUSH.md).

Pull dan push ini **dua sistem yang gak saling terkait** — beda controller, beda arah, beda trigger. Bukan satu mekanisme sync dua-arah yang terpadu.

## Trigger

- **Gak ada scheduler/cron** di Laravel (`routes/console.php` cuma ada command `inspire` bawaan, `bootstrap/app.php` gak daftar `withSchedule`).
- Pull dipicu **manual** — tombol sync di `Navbar.vue` (posv1-vue), yang loop manggil ~30 endpoint `/api/sync_pull/*` satu-satu secara berurutan (lihat `syncQueue` di `Navbar.vue`).
- Push dipicu **manual/on-demand** juga, dari alur order (bukan loop terjadwal) — lihat detail di [SYNC PUSH.md](./SYNC%20PUSH.md).

## Alur install pertama kali (route `/setup`)

1. Route `/setup` (`SetupPage.vue`) — login (`username`/`password`) → `POST /api/setup/get_branch_list` → tampil daftar branch.
2. User **pilih branch** di dropdown — cuma nyimpen pilihan ke `branch_select` (state lokal Vue), **belum** ada request apapun ke backend. (Sempat dibikin auto-trigger di `@change`, tapi di-revert — user gak suka ada network call sebelum sengaja klik tombol.)
3. Klik tombol **"Install"** → baru semuanya jalan berurutan dalam 1 fungsi (`install()`):
   - **Step 1**: `POST /api/setup/get_data_branch/{branch_id}` (`SetupServices::getDatabranch()`) → `mr_branch` lokal ke-save (id, token, logo, dst). Ini satu-satunya step yang masih butuh `branch_id` eksplisit dari frontend — logis, karena inilah yang nentuin/nyimpen branch-nya duluan. Gagal di sini → berhenti, `syncQueue` gak dijalanin.
   - **Step 2**: jalanin `syncQueue` (~33 endpoint `/api/sync_pull/*`), **tanpa** parameter `branch_id` sama sekali — semuanya ambil branch dari `mr_branch` lokal sendiri (lihat bagian "No branch_id" di bawah).
4. Semua sukses → tombol "Finish" → `GET /api/setup/install_success/{status}` → set `flag_install_status = true`, redirect ke `/terminal`.

Catatan: guard yang harusnya maksa user ke `/setup` kalau belum pernah install (`kesiapan()` di `router/index.js`) **di-comment** — jadi sekarang `/setup` cuma kesampe kalau dibuka manual, bukan auto-redirect.

## 2 pintu masuk buat pull (penting)

Tiap fungsi pull sebenernya cuma 1 logic di `App\Services\SetupServices`, tapi ada 2 controller yang manggilnya:

- **`SetupController`** (`/api/setup/*`) — dipakai pas instalasi awal, kirim `username`/`password` manual (belum ada token tersimpan). Cuma 3 route yang beneran ke-routing (lihat temuan di bawah), termasuk `get_data_branch/{branch_id}` yang masih butuh `branch_id`.
- **`SyncController`** (`/api/sync_pull/*`) — dipakai buat semua pull lainnya (baik pas install maupun re-sync setelah aktif). **Gak nerima `branch_id` dari client** — ambil sendiri dari `mr_branch` lokal (`currentBranch()`), karena tabel itu emang cuma pernah 1 baris (1 device = 1 branch).

**Temuan penting**: dari ~30 method yang ada di `SetupController.php` (`getStationList`, `getCategoryList`, `getMasterItem`, dst — semua kecuali 3), **cuma 3 yang beneran ke-routing** di `routes/api.php` prefix `setup`:
- `POST /api/setup/get_branch_list`
- `POST /api/setup/get_data_branch/{branch_id}`
- `GET /api/setup/install_success/{status}`

Sisanya (getStationList, getCategoryList, getMasterItem, dll di `SetupController`) **kode-nya ada tapi gak ada route yang manggil** — dead code, gak ke-hit sama sekali. Semua pull selain 2 di atas praktiknya lewat `SyncController` (`/api/sync_pull/*`) aja, walau method kembarnya masih nangkring di `SetupController`.

## Pola pull saat ini: truncate + insert

Semua ~35 fungsi pull di `SetupServices.php` pakai pola sama: `Model::truncate()` lalu `Model::insert($data)` — hapus semua baris lokal, insert ulang dari nol tiap kali sync jalan. Ini yang rencananya mau diubah jadi **upsert** (lihat bagian Rencana di bawah) — salah satu dampaknya, `getMasterItem()` jadi selalu download ulang gambar item (`downloadImage()`) tiap sync walau datanya gak berubah, karena truncate bikin lokal selalu kosong lagi sebelum insert.

## Status / Rencana

- [x] **No branch_id di pull** — `/api/sync_pull/*` (33 route) gak lagi terima `branch_id` di URL, ambil dari `mr_branch` lokal sendiri (`SyncController::currentBranch()`). `SetupServices` sendiri gak berubah kontraknya. Sempet dicoba trigger simpen branch langsung pas `@change` Select (sebelum tombol Install diklik) — **di-revert** karena bikin network call jalan diam-diam sebelum user sengaja klik apa-apa; sekarang balik ke pola tombol tunggal (klik "Install" → step 1 simpen branch → step 2 pull data, semua dalam 1 fungsi `install()`).
- [x] **Upsert** — pola `truncate()+insert()` diganti `Model::upsert($rows, ['id'])` buat 32 dari 34 fungsi pull. Pengecualian tetap replace: `getMasterUser` (data akses login, harus cerminan pasti server) dan `getTableSectionPrintCategorySetting` (`id` server gak reliable buat dedup). Lihat detail di [SYNC PULL.md](./SYNC%20PULL.md). Konsekuensi yang diterima: baris yang dihapus di server gak otomatis kehapus di lokal (belum ada mark & sweep).
- [x] **Navbar `syncQueue`** — udah ditambahin `getUsers`/`getMasterRoleAccess`/`getMenuApp` biar konsisten sama queue install.
- [x] **Push dayshift & dayshift detail** — sebelumnya gak dipush sama sekali (cuma 4 tabel order). Sekarang jadi 6 tabel, plus 3 bug yang dibenerin (skema `pos_dayshift_detail`, `sync_at` yang salah konvensi, `shift_number` yang gak pernah keisi bikin end-shift gagal). Lihat detail di [SYNC PUSH.md](./SYNC%20PUSH.md).
- [ ] **Auto push** — sistem push (lihat [SYNC PUSH.md](./SYNC%20PUSH.md)) rencananya mau dibikin otomatis (belum manual-trigger terus). Belum digarap, didokumentasikan dulu biar jelas titik mulainya.
- [ ] Method dead code di `SetupController` (yang gak ke-routing) — belum diputuskan mau dihapus atau dirapiin.
