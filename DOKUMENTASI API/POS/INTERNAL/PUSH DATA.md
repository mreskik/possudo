# Push Data

Base path: `/api/push`

9 endpoint — push data transaksi lokal POS ke ERP (arah kebalik dari `sync_pull/*`). **Sebagian besar sudah otomatis dijalankan** oleh background command `sync:push` (jalan terus tiap 120 detik, lihat "Latar belakang: command `sync:push`" di bawah) — endpoint manualnya ada sebagai cadangan/trigger on-demand, bukan satu-satunya jalur.

## Pola umum (7 endpoint push per-tabel)

```
GET /api/push/data-{nama}
```

| Endpoint | Fungsi service | Tabel yang di-push |
|---|---|---|
| `GET /push/data-dayshift` | `pushDataDayShift()` | `tr_dayshift` |
| `GET /push/data-dayshift-detail` | `pushDataDayShiftDetail()` | `tr_dayshift_detail` |
| `GET /push/data-order` | `pushDataOrder()` | `tr_order` |
| `GET /push/data-order-detail` | `pushDataOrderDetail()` | `tr_order_detail` |
| `GET /push/data-order-detail-package` | `pushDataOrderDetailPackage()` | `tr_order_detail_package` |
| `GET /push/data-order-payment` | `pushDataOrderPayment()` | `tr_order_payment` |
| `GET /push/data-remove-item-before-save` | `pushDataRemoveItemBeforeSave()` | `tr_remove_item_before_save` |
| `GET /push/data-remove-item-before-save-package` | `pushDataRemoveItemBeforeSavePackage()` | `tr_remove_item_before_save_package` |

Response: `{ "code": 0, "data": { ... hasil push ... } }`.

**Mekanisme `sync_at`** — semua fungsi push filter `WHERE sync_at IS NULL` (baris yang belum pernah ke-push), lalu set `sync_at` ke timestamp sekarang setelah berhasil. `sync_at` di-reset jadi `NULL` lagi tiap ada perubahan data (lihat "sync update" di `OrderServices.php`) — jadi **aman dipanggil berkali-kali kapan saja**, tidak ada duplikasi push, dan tidak akan bentrok dengan panggilan lain (`sync:push` command atau `EndDay()`) karena baris yang sudah ke-push di satu jalur otomatis tidak lolos filter lagi di jalur lain.

## PushOrderSyncAll — push 4 tabel order sekaligus

```
GET /api/push/order-sync-all
```

Push `data-order` + `data-order-detail` + `data-order-detail-package` + `data-order-payment` dalam **1 request**, **tanpa parameter** `order_number` (filter `sync_at IS NULL` tetap global, bukan push 1 order spesifik).

Response:
```json
{
  "code": 0,
  "data": {
    "data_order": {...},
    "data_order_detail": {...},
    "data_order_detail_package": {...},
    "data_order_payment": {...}
  }
}
```

- **Urutan WAJIB**: order dulu, baru detail/package/payment (`pos_order_detail.order_number` merujuk ke `pos_order`) — **beda dari 4 endpoint individual di atas**, di sini tiap langkah **gagal langsung dihentikan** (bukan try/catch per-langkah seperti `EndDay()`) — dipanggil on-demand sinkron, caller perlu tau persis langkah mana yang gagal buat memutuskan retry.
- **Use case**: dibuat (2026-09-25) supaya caller (mis. tombol "Send WA" struk, belum di-wire ke sini saat dokumen ini ditulis) bisa trigger "sync sekarang" tanpa nunggu interval `sync:push` (default 120 detik) atau nunggu `EndDay()` — mengurangi risiko link struk publik 404 (order belum ke-sync ke ERP, jadi belum "ada" di sisi ERP).

## PushDataSoldOut — snapshot penuh, bukan incremental

```
GET /api/push/sold-out
```

Tombol manual "Push" di halaman Menu Management — **snapshot PENUH** item yang lagi sold out, **REPLACE total** di ERP (BEDA dari 8 endpoint di atas yang semuanya berbasis `sync_at` incremental).

Response: `{ "code": 0, "data": {...} }`.

## Alur Implementasi

- **7 endpoint per-tabel + `order-sync-all`**: dalam pemakaian normal, **TIDAK PERLU dipanggil manual dari frontend** — `sync:push` (command background, lihat di bawah) sudah menjalankan 6 di antaranya (semua kecuali `remove-item-before-save*`, dan `order-sync-all` tidak dipanggil command sama sekali) setiap 120 detik. Endpoint-endpoint ini tersedia untuk trigger manual/on-demand kalau perlu "sync sekarang" tanpa nunggu interval.
- **`PushDataSoldOut`**: dipanggil dari tombol "Push" eksplisit di Menu Management page — **harus** manual, tidak ada job otomatis yang menjalankannya.

## Latar belakang: command `sync:push`

**Bukan endpoint HTTP** — `php artisan sync:push`, dijalankan terus-menerus sebagai background process (lihat `POS-SyncPush` di daftar service `posUpdater`/NSSM). Loop tiap `SYNC_PUSH_INTERVAL_SECONDS` (`.env`, default 120 detik):

```
dayshift → dayshift_detail → order → order_detail → order_detail_package
→ order_payment → remove_item_before_save → remove_item_before_save_package
```

Urutan **wajib** sama seperti `order-sync-all` (dayshift/order dulu, baru turunannya) — tapi di sini **try/catch per-langkah** (1 gagal tidak menghentikan langkah lain di putaran yang sama, beda dari `order-sync-all`). Putaran dianggap sukses kalau **semua 8** fungsi lolos; kalau ada yang gagal, dilaporkan ke `JobHealthReporter` (terlihat di [`SYSTEM.md`](SYSTEM.md) → `jobs-health`). `remove_item_before_save*` **cuma** dijalankan dari command ini (bukan dari `EndDay()`) — data itu murni audit trail (item dihapus dari cart sebelum order tersimpan), tidak ada relasi ke jurnal/dayshift.

`EndDay()` (`DayShiftServices.php`) **juga** memanggil push (dayshift + order + detail + payment, bukan `remove_item_before_save*`) sebagai "jaring pengaman terakhir" sebelum jurnal — jalan berdampingan dengan `sync:push`, tidak saling menggantikan.

## Sumber

- `app/Http/Controllers/PushDataController.php` — 9 method.
- `app/Services/PushDataServices.php` — logic push + filter `sync_at`.
- `app/Console/Commands/SyncPush.php` — command background `sync:push`.
- `app/Services/DayShiftServices.php` — `EndDay()` (push sebagai jaring pengaman).
- `routes/api.php` — group `Route::prefix('push')`.

## Status

Didokumentasikan 2026-10-12, berdasar pembacaan kode langsung (belum ada verifikasi ulang lewat HTTP request live di sesi ini).
