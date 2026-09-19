# Sync Push (Lokal → Server ERP)

Kirim data transaksi order (bukan master data) DARI lokal (`db_pos`) KE server ERP. Logic di `App\Services\PushDataServices`, dipanggil dari `PushDataController` (`/api/push/*`).

## Endpoint

| Data | Route lokal | Service function | Model lokal | Kirim ke (server) |
| --- | --- | --- | --- | --- |
| Dayshift (header buka/tutup toko) | `GET /api/push/data-dayshift` | `pushDataDayShift()` | `DaySiftModel` → `tr_dayshift` | `POST {endpoint}/pos/push/data_dayshift` |
| Dayshift detail (per shift) | `GET /api/push/data-dayshift-detail` | `pushDataDayShiftDetail()` | `DayShiftDetailModel` → `tr_dayshift_detail` | `POST {endpoint}/pos/push/data_dayshift_detail` |
| Order (header) | `GET /api/push/data-order` | `pushDataOrder()` | `TrOrderModel` → `tr_order` | `POST {endpoint}/pos/push/data_order` |
| Order detail (item) | `GET /api/push/data-order-detail` | `pushDataOrderDetail()` | `TrOrderDetailModel` → `tr_order_detail` | `POST {endpoint}/pos/push/data_order_detail` |
| Order detail package | `GET /api/push/data-order-detail-package` | `pushDataOrderDetailPackage()` | `TrOrderDetailPackageModel` → `tr_order_detail_package` | `POST {endpoint}/pos/push/data_order_detail_package` |
| Order payment | `GET /api/push/data-order-payment` | `pushDataOrderPayment()` | `TrOrderPaymentModel` → `tr_order_payment` | `POST {endpoint}/pos/push/data_order_payment` |

Urutan push **harus** dayshift → dayshift detail → order → order detail → order detail package → order payment (dayshift itu buka toko, secara bisnis harus ada duluan sebelum order — `pos_order.dayshift_ulid` ngerujuk ke situ, walau gak ada FK constraint keras di server yang maksa urutan ini), lihat `PushAll()` di frontend.

## Cara kerja tiap fungsi push

Pola sama di ke-6 fungsi:

1. Ambil semua baris lokal yang `sync_at IS NULL` (belum pernah ke-push).
2. Normalisasi tipe data biar cocok sama yang diharapkan server Go (contoh: `flag_inclusive_tax`/`done_print` di-cast ke `bool`, `tax_rate` null jadi `'0'`, datetime lokal (`Y-m-d H:i:s`) dikonversi ke format ISO8601 `time.Time` lewat `formatedDateTimeToTimeTime()`/`formatedDateToTimeTime()`).
3. Kirim sekali jalan sebagai batch (`POST` isi array `list_order`/dst) ke endpoint server ERP.
4. Kalau server balas `code == 0` → `UPDATE ... SET sync_at = ?` di lokal buat semua baris yang barusan dikirim (dibungkus `DB::transaction`, rollback kalau gagal).
5. Kalau bukan `code == 0` → lempar exception, `sync_at` lokal **gak** keupdate (baris itu bakal ke-pull lagi di push berikutnya, aman/idempoten selama server nolak dengan bersih).

Catatan `formatedDateToTimeTime()` (khusus `order_date`): sengaja diinterpretasikan sebagai UTC (bukan timezone lokal Asia/Jakarta) sebelum dikonversi, karena `order_date` itu tanggal kalender murni (bukan instant waktu) — kalau dianggap WIB dulu baru dikonversi ke UTC, tanggalnya mundur 1 hari (00:00 WIB jadi 17:00 UTC hari sebelumnya).

## Audit kesesuaian kolom (POS lokal vs server ERP)

Dicek satu-satu kolom `tr_order*` (lokal) vs struct Go (`PosOrder*Model`, `APIANDORDER/backend/modules/apipos/pushdata/pushdata_model.go`):

- `tr_order_detail`, `tr_order_detail_package`, `tr_order_payment` — **cocok 100%**.
- `tr_order` — ketemu 2 kolom yang **gak ke-cover** di server: `customer_phone_number` dan `chasier_name` (ada di lokal dari awal, gak ada field-nya di `PosOrderModel`). Efeknya data ke-drop diam-diam pas push (Go `ShouldBindJSON` ngabaikan field JSON yang gak dikenal, gak error).
  - **Sudah dibenerin** (2026-08-10): migration `sudocore2/cmd/migration/076_alter_table_pos_order_add_customer_phone_chasier_name.sql` (nambah 2 kolom nullable di `pos_order`, udah di-apply ke `db_sudocore_dev`) + `PosOrderModel` (APIANDORDER) ditambah field `CustomerPhoneNumber`/`ChasierName`. Nama kolom **`chasier_name`** (bukan "cashier_name") sengaja ngikutin typo yang udah kepalanjur ada di `tr_order` lokal, biar field JSON tetep cocok tanpa custom mapping. Sisi Laravel gak perlu diubah — `TrOrderModel` gak punya `$hidden`, jadi semua kolom (termasuk 2 ini) udah otomatis kekirim.

Kalau nanti ada kolom baru ditambah ke `tr_order*` di lokal, cek dulu manual apa ada padanannya di `PosOrder*Model` — gak ada validasi otomatis yang nge-flag field yang "ke-drop" kayak gini.

## Dayshift push — dibangun dari nol (2026-08-10)

Sebelum ini, `tr_dayshift`/`tr_dayshift_detail` **gak pernah dipush sama sekali** — gak ada service function, controller, atau route di Laravel. Struct Go-nya (`PosDayShiftModel`/`PosDayShiftDetailModel`) udah ada tapi nganggur (gak ada Service/Handler/Route yang makainya). Dibangun ngikutin pola persis push order yang udah jalan. Sekalian ketemu & dibenerin 3 bug yang bikin fitur ini gak bakal jalan kalau dibiarin:

1. **Skema `pos_dayshift_detail` (Postgres) gak nyambung** — kolomnya `dayshift_id` (bigint), harusnya `dayshift_ulid` (varchar, ngikutin pola ulid di tabel `pos_*` lain), dan gak ada kolom `shift_number`. Dibenerin lewat migration `sudocore2/cmd/migration/077_alter_table_pos_dayshift_detail_columns.sql` (tabel kosong/belum pernah kepakai, aman diubah langsung) + fix tipe field `DayShiftULID` (Go, `int64`→`string`) dan tambah field `ShiftNumber`.
2. **`tr_dayshift_detail.sync_at` (lokal) salah konvensi** — kepasang `NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` (beda dari semua tabel push lain yang `NULL DEFAULT NULL`). Efeknya kolom itu kepakai kayak "last modified" (auto-keisi tiap insert/update), bukan "belum dipush" — query `WHERE sync_at IS NULL` gak akan pernah nemu baris tanpa fix ini. Dibenerin lewat migration Laravel `2026_08_10_000001_fix_tr_dayshift_detail_sync_at.php`.
3. **`shift_number` gak pernah diisi** — `DayShiftServices::EndShift()` manggil `DayShiftDetailModel::create([...])` tanpa `shift_number`, padahal kolomnya `NOT NULL` tanpa default. Tervalidasi ini bikin **end-shift beneran gagal** di bawah `STRICT_TRANS_TABLES` (`Field 'shift_number' doesn't have a default value`) — bug lama yang gak ketauan sebelum audit ini. Dibenerin: `EndShift()` sekarang ngitung `shift_number` = jumlah shift yang udah ada di dayshift itu + 1 (matching label "Shift N" yang udah ada di `Navbar.vue`, `globalstore.shiftlist.length + 1`).

Tervalidasi end-to-end: `StartDay` → `EndShift` (sukses, `shift_number=1` kecatat) → push dayshift & dayshift detail (`sync_at` ke-update lokal, data nyampe bener di `pos_dayshift`/`pos_dayshift_detail`) → push ulang (idempoten, gak dobel).

## Bug ketemu & dibenerin (2026-08-10)

`PushDataPosOrderDetail` (`pushdata_handler.go`) di jalur suksesnya lupa `.SetCode(0)` — beda dari handler saudaranya yang eksplisit set. Karena `Response.Code` itu `*int` (default `nil` kalau gak di-set), respons sukses balikin `"code": null`. Kebetulan gak kerasa dampaknya karena Laravel ngecek `$response->json('code') == 0` (loose comparison, `null == 0` itu `true` di PHP) — tapi tetep rapuh buat consumer lain. Sudah ditambahin `.SetCode(0)`.

## Remove Item Before Save (2026-09-18)

`tr_remove_item_before_save` (+ `_package`) — audit trail item yang dihapus kasir dari cart lokal SEBELUM order pernah tersimpan (dicatat lewat `OrderServices::RecordRemoveItemBeforeSave()`, dipanggil dari `removeItemOrder()` di `orderPage.vue`). Jalur push-nya dibangun lengkap end-to-end, ngikutin pola 6 fungsi push yang udah ada di atas persis:

| Data | Route lokal | Service function | Model lokal | Kirim ke (server) |
| --- | --- | --- | --- | --- |
| Remove item before save (header) | `GET /api/push/data-remove-item-before-save` | `pushDataRemoveItemBeforeSave()` | `TrRemoveItemBeforeSaveModel` → `tr_remove_item_before_save` | `POST {endpoint}/pos/push/data_remove_item_before_save` |
| Remove item before save package | `GET /api/push/data-remove-item-before-save-package` | `pushDataRemoveItemBeforeSavePackage()` | `TrRemoveItemBeforeSavePackageModel` → `tr_remove_item_before_save_package` | `POST {endpoint}/pos/push/data_remove_item_before_save_package` |

Endpoint terima di APIANDORDER (`pushdata_handler.go` — `PushDataPosRemoveItemBeforeSave`/`...Package`) nulis ke `pos_remove_item_before_save`/`pos_remove_item_before_save_package` di ERP (`sudocore2` migration `213`/`214`). `company_id` di-resolve di APIANDORDER dari `branch_id` baris pertama — sama pola kayak `PushPOSOrder` (header py `branch_id` sendiri, detail/package ikut header tanpa `company_id` sendiri).

Sudah ditambahkan ke `usePushData.ts` (`PushDataRemoveItemBeforeSave`/`PushDataRemoveItemBeforeSavePackage`) dan `PushAll()`, ditaruh setelah `data_order_payment` — tabel ini gak punya dependency FK keras ke `tr_order`/`tr_dayshift` (`order_number`/`dayshift_ulid` nullable), jadi urutannya gak sekritikal grup order.

**Bug ketemu & dibenerin (2026-09-18, audit sebelum dianggap selesai)**: migration awal `tr_remove_item_before_save` (`2026_09_18_100000_*.php`) **gak punya kolom `branch_id`** — beda dari `tr_order`/`tr_dayshift` yang keduanya punya. Efeknya sama pola bug `customer_phone_number`/`chasier_name` di atas: `PosRemoveItemBeforeSaveModel.BranchID` (APIANDORDER) selalu ke-default `0` (zero-value Go, bukan pointer), `PushPOSRemoveItemBeforeSave()` query `SELECT ... FROM master_branch WHERE id = 0` gagal (`sql.ErrNoRows`), push header **selalu gagal**, dan push package ikut gagal (FK constraint `REFERENCES pos_remove_item_before_save(ulid)`, baris header-nya gak pernah ke-commit). Dibenerin: migration baru `2026_09_18_110000_add_branch_id_to_tr_remove_item_before_save.php` (kolom `branch_id` nullable) + `OrderServices::RecordRemoveItemBeforeSave()` diisi `$branch->id` (`BranchModel::first()`, sama pola `pushDataOrder()`'s payload). Sekalian ditambahin guard `item_conv_id <= 0` (skip insert, gak ada gunanya nyatet baris yang gak nunjuk ke item manapun). Sisi APIANDORDER/`sudocore2` **gak perlu diubah** — kolom `branch_id`/`company_id` udah disiapin dari awal di `pos_remove_item_before_save`, cuma nunggu payload-nya beneran bawa `branch_id` yang valid.

**`order_number` kini terisi kalau relevan (2026-09-19)**: awalnya `order_number` SELALU dikirim `null` dari `removeItemOrder()`. Ternyata ada skenario di mana itu kehilangan konteks: kasir buka order **pending** yang udah ada nomornya (`ViewOrder()`), nambah menu BARU ke order itu (item baru ini belum punya `ulid` sendiri — makanya tombol yang muncul tetap Trash2/`removeItemOrder()`, bukan "Cancel Item" yang butuh `ulid`), terus batal sebelum tekan save ulang. Sekarang `removeItemOrder()` (`orderPage.vue`) ngirim `dataOrderObject.value.orderNumber || null` sebagai `order_number` di payload — kosong (`''`, order baru yang belum pernah tersimpan sama sekali) jadi `null`, terisi (order pending existing) jadi nomor order-nya, biar baris audit bisa dilacak balik ke order mana asalnya. `RecordRemoveItemBeforeSave()` (`OrderServices.php`) dan `RemoveItemBeforeSave()` (`Order.js`) disesuaikan nerima parameter `order_number` opsional ini.

**Cakupan diperluas — nutup celah "pindah halaman tanpa Trash2/Save" (2026-09-19)**: sebelumnya, satu-satunya trigger `RemoveItemBeforeSave()` adalah tombol Trash2 (`removeItemOrder()`) — kalau kasir nambah item ke cart lalu navigasi keluar halaman (klik sidebar/navbar, dst) TANPA hapus item manual atau Save, item itu hilang begitu aja dari `listOrder` (state komponen) tanpa kecatat sama sekali. Ditambahin hook `onBeforeUnmount` (`orderPage.vue`) yang loop `listOrder` pas komponen mau di-destroy, fire `RemoveItemBeforeSave()` per item yang **belum punya `ulid`**.

Guard penting: `listOrder` **gak pernah di-update dengan `ulid` hasil `SaveOrder()`** (`saveOrderHandle()` langsung `router.push()` abis save sukses, gak nyentuh state `listOrder` sama sekali) — jadi filter `!item.ulid` doang gak cukup buat ngebedain "baru aja sukses disave" vs "beneran dibuang". Ditambahin flag `orderSaved` (ref, di-set `true` tepat sebelum `router.push()` di jalur Save sukses) — `onBeforeUnmount` skip total kalau flag ini `true`. Jalur navigasi lain (Cancel Order, klik keluar manual) **sengaja TIDAK** ikut nyalain flag ini, karena perilakunya udah benar tanpa perlu di-skip: item LAMA (dari `ViewOrder()`) udah punya `ulid` (otomatis ke-skip filter), item BARU yang belum sempat disave emang seharusnya tercatat sebagai "batal" kalau order-nya sendiri di-cancel atau kasir keluar tanpa save.

**Cakupan yang BELUM ketutup**: `onBeforeUnmount` cuma kepicu buat navigasi router di DALAM aplikasi (SPA) — TIDAK kepicu kalau kasir nutup tab paksa atau reload browser (F5) di tengah nambah item, karena Vue lifecycle hook gak dijamin sempat jalan di situ. Nutup kasus itu SEPENUHNYA (beneran ngirim data-nya) butuh `navigator.sendBeacon()` (belum diimplementasikan — `sendBeacon` gak bisa bawa header `Authorization` custom kayak axios biasa, perlu keputusan tambahan soal auth endpoint-nya).

**Cegat refresh/nutup tab (2026-09-19)**: karena ngirim data pas `beforeunload` gak reliable (lihat di atas), pendekatan yang dipakai adalah MENCEGAH kejadiannya duluan, bukan ngirim data setelahnya. Ditambahin `window.addEventListener('beforeunload', beforeUnloadHandler)` (di-attach di `onMounted`, di-lepas di `onBeforeUnmount` biar gak nempel di halaman lain) — kalau `hasUnsavedItems()` (ada baris `listOrder` yang `!ulid`) `true`, `e.preventDefault()` + `e.returnValue = ''` dipanggil, browser nampilin native confirm dialog ("Leave site?"/"Reload site?", tombol Cancel/Leave). **Teks dialog ini GAK BISA dikustom** — batasan browser sejak lama (Chrome/Firefox/Edge semua ngunci jadi pesan generik bawaan sendiri, demi keamanan, nyegah penyalahgunaan buat phishing/scare tactic) — jadi gak bisa nulis pesan custom kayak "Jangan refresh halaman order". Kasir tetap BISA reload/nutup paksa kalau eksplisit pilih "Leave"/"Reload" di dialog itu — ini murni "nanya dulu/nyegat gak sengaja", bukan proteksi mutlak.

## Trigger saat ini: manual, dan ada skeleton auto-push yang dimatiin

Frontend (`posv1-vue`):

- `src/composables/usePushData.ts` — bungkus ke-6 API call di atas + `PushAll()` yang manggil berurutan (dayshift → dayshift detail → order → order detail → order detail package → order payment).
- `src/App.vue` — **sudah ada percobaan auto-push pakai `setInterval`, tapi di-comment / dimatiin**:

  ```js
  // timer = setInterval(() => {
  //   pushData.PushAll()
  // }, 5000)
  ```

  Ini kandidat titik mulai buat "auto push" yang direncanakan — tinggal diaktifkan lagi (atau dirombak, misal pindah dari polling interval 5 detik ke trigger event-based tiap abis transaksi) — lihat rencana di [PANDUAN SYNC.md](./PANDUAN%20SYNC.md).

## Yang perlu diperhatiin sebelum aktifin auto-push

- **Belum ada retry/backoff** — kalau server ERP down, tiap `setInterval` tick bakal gagal terus tanpa jeda naik (bisa spam request tiap 5 detik selama server down).
- **Belum ada lock/guard concurrent push** — kalau interval jalan lagi sebelum push sebelumnya selesai (request lambat), bisa ada 2 push jalan barengan narik baris `sync_at IS NULL` yang sama.
- **Urutan push antar tipe data gak dijamin atomic** — `PushAll()` nge-await berurutan, tapi kalau `pushDataOrder()` sukses lalu `pushDataOrderDetail()` gagal, order header udah ke-mark `sync_at` walau detail-nya belum nyusul (perlu dicek apakah ini masalah di sisi server, atau server toleran nerima header duluan).
