# Menu Management (Sold Out & Stok Qty)

Base path: `/api/branch-menu`

**⚠️ Bug pola sama seperti modul lain**: controller di folder ini **selalu balas HTTP 200**, baik sukses maupun gagal (`catch` block tetap `response()->json([...])` tanpa `->status()`) — frontend **wajib** baca field `code` di body (`0` = sukses, `100` = gagal), **tidak bisa** mengandalkan HTTP status untuk deteksi error.

## Load

```
GET /api/branch-menu/load
```

Response: `{ "code": 0, "data": [ { "id": 10, "name": "Nasi Goreng", "short_name": "NASGOR", "stok_qty": 50, "flag_soldout": false, "subcategory_id": 3, "...": "kolom lain mr_item" } ] }`

- Query **JOIN 4 tabel** (`mr_branch_visit_purpose` → `mr_pricelist_detail` → `mr_item_conv` → `mr_item`), `SELECT DISTINCT mi.*` — hasil akhirnya daftar unik `mr_item` yang terhubung lewat pricelist aktif branch ini. **Menu yang tidak masuk pricelist/visit-purpose manapun TIDAK AKAN muncul** di daftar ini walau ada di tabel `mr_item` — bukan bug, tapi bisa membingungkan kalau menu "hilang" tanpa pesan error apapun.
- Query pakai `DB::select()` raw (bukan Eloquent) — cast `boolean` di `MasterItemModel` **tidak berlaku** di sini, makanya `flag_soldout` dinormalisasi manual jadi `true`/`false` lewat loop PHP setelah query. Kolom lain (termasuk `stok_qty`) **tidak** dinormalisasi tipenya — bisa jadi string tergantung driver DB.

## Save Sold Out

```
POST /api/branch-menu/save-soldout
```

**Request:** `{ "menu_id": 10, "flag_soldout": true }`

**Response:** `{ "code": 0, "message": "success" }`

- **Tidak ada validasi apapun** — `menu_id`/`flag_soldout` diambil langsung via `$request->input()`, tidak ada `required`/`exists`/tipe data check. `flag_soldout` diterima apa adanya (bisa `true`/`false`/`1`/`0`/string) lalu langsung `UPDATE mr_item SET flag_soldout = ...` tanpa normalisasi.
- **`menu_id` tidak dicek exist** — kalau tidak match baris manapun, `update()` tetap sukses dengan 0 baris terupdate, tapi method tetap return `'success'` → **silent no-op**, FE akan terima `code: 0` walau sebenarnya tidak ada yang berubah.
- **Efek downstream penting**: `flag_soldout` di sini langsung dibaca ulang oleh halaman Order (`orderPage.vue`) untuk blokir penambahan item yang sedang sold out ke keranjang (label merah "Sold Out !"). Toggle di Menu Management = langsung berlaku di kasir, real-time via reload data, **tanpa** WebSocket/broadcast — murni baca ulang saat halaman masing-masing di-reload/dibuka.
- **TIDAK otomatis sinkron ke ERP** — lihat [Push Sold Out](#push-sold-out-terkait) di bawah.

## Save Stok Qty

```
POST /api/branch-menu/save-stokqty
```

**Request:** `{ "menu_id": 10, "stok_qty": 25 }`

**Response:** `{ "code": 0, "message": "success" }`

- Sama persis pola `save-soldout` — tidak ada validasi (termasuk tidak ada guard angka negatif), tidak cek `menu_id` exist, silent no-op kalau `menu_id` tidak match.
- **Catatan penamaan**: route terdaftar sebagai `saveStokQTY` (huruf besar semua), method asli di controller `saveStokQty` — beda case tapi tetap berfungsi normal (PHP method call case-insensitive). Disebut di sini supaya tidak membingungkan saat baca source code langsung.

## Push Sold Out (terkait, folder berbeda)

```
GET /api/push/sold-out
```

Tombol **manual** terpisah "Push Sold Out" di halaman Menu Management — **wajib diklik** supaya status sold-out POS lokal benar-benar terkirim ke ERP, toggle `save-soldout` **tidak** otomatis mendorongnya.

- Mengambil **snapshot penuh** `mr_item.id` yang **saat ini** `flag_soldout = true` (query ulang dari DB saat tombol diklik, bukan dari state cache frontend) → `POST {ERP_endpoint}/pos/push/sold_out` dengan body `{ branch_id, item_ids: [...] }`.
- Pola **REPLACE TOTAL** (bukan `sync_at`-based seperti push lain di [`../INTERNAL/PUSH DATA.md`](../../INTERNAL/PUSH%20DATA.md)) — `mr_item` tidak punya kolom `sync_at`, dan status sold-out itu *state* naik-turun, bukan histori transaksi. ERP men-DELETE+INSERT ulang seluruh baris branch ini di `master_item_sold_out` — item yang baru di-unsoldout-kan otomatis hilang dari ERP di push berikutnya, tidak perlu kirim daftar "hapus" terpisah.
- `item_ids` yang dikirim = `mr_item.id` (ID item **fisik**, sama persis `master_item.id` di ERP) — bukan `mr_item_conv.id` (level varian/pricing).
- Timeout: connect 5 detik, total 30 detik — panggilan HTTP **sinkron** langsung saat tombol diklik, bukan queue/job background.
- **Risiko operasional**: kalau kasir lupa klik tombol ini, data sold-out POS lokal dan ERP bisa **berbeda** tanpa ada warning otomatis dari sistem manapun.

## Alur Implementasi

1. Halaman Menu Management dibuka → `loadMenuList()` (`GET /branch-menu/load`) dipanggil di `onMounted()`, bersamaan dengan load kategori/subkategori untuk lookup nama.
2. User toggle switch "Sold Out" per baris → `saveFlagSoldOut()` (`POST /branch-menu/save-soldout`) → **selalu** `loadMenuList()` ulang setelahnya (baik path sukses maupun catch) untuk refresh tabel.
3. User edit cell "Qty" (inline edit DataTable) → selesai edit (blur/enter) → `saveQty()` (`POST /branch-menu/save-stokqty`) → reload ulang sama seperti di atas.
4. User klik tombol "Push Sold Out" (terpisah, manual) → `GET /push/sold-out` → kirim snapshot penuh ke ERP, tampil toast sukses/gagal.
5. **Downstream**: status `flag_soldout` yang diubah di langkah 2 langsung dipakai `orderPage.vue` kasir untuk blokir penambahan item ke order — perubahan di Menu Management berefek langsung ke halaman Order begitu halaman Order di-reload/dibuka ulang (tidak real-time via push/WebSocket).

**Catatan migrasi — bug penting untuk ditangani di FE baru:**
- Karena HTTP selalu 200, blok `catch` di composable frontend saat ini (`useBranchMenu.ts`) **praktis tidak pernah ter-trigger oleh error logic backend** (`code: 100`) — hanya oleh error network murni. Artinya **saat ini user tidak pernah melihat pesan error** walau backend sebenarnya gagal (misal `menu_id` invalid) — FE baru **wajib** cek field `code` di response body secara eksplisit, jangan andalkan try/catch axios saja.
- Tidak ada validasi apapun di backend untuk `menu_id`/`flag_soldout`/`stok_qty` — kalau FE baru butuh validasi (misal cegah `stok_qty` negatif), harus ditambahkan di sisi FE sendiri atau diajukan sebagai perbaikan backend terpisah.

## Sumber

- `routes/api.php` — grup `branch-menu` (`load`, `save-soldout`, `save-stokqty`), serta `push/sold-out` di grup `push`.
- `app/Http/Controllers/BranchMenuController.php` — `load()`, `saveSoldout()`, `saveStokQty()`.
- `app/Services/BranchMenuServices.php` — `load()`, `saveFlagSoldOut()`, `saveStokQTY()`.
- `app/Models/MasterItemModel.php` — table `mr_item`, `$guarded = []`, `$timestamps = false` (tidak ada audit trail waktu update).
- `app/Http/Controllers/PushDataController.php` — `PushDataSoldOut()`.
- `app/Services/PushDataServices.php` — `pushDataSoldOut()`.
- Frontend: `src/composables/useBranchMenu.ts` (`loadMenuList`, `saveFlagSoldOut`, `saveQty`, `pushSoldOut`), `src/modules/dashboard/menu_management/MenuManagementPage.vue`.

## Status

Didokumentasikan 2026-10-12, berdasar pembacaan kode langsung (belum ada verifikasi ulang lewat HTTP request live di sesi ini).
