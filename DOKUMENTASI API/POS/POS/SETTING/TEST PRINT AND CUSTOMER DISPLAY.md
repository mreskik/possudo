# Test Print & Customer Display

## Test Print All

```
GET /api/setting/test-print-all
```

Loop **semua** `StationModel` yang ada, panggil `PrintServices::PrintTest($station->id)` per station — satu request memicu banyak kali cetak fisik (1x per station terdaftar), bukan 1x cetak saja.

Response: `{ "code": 0, "message": "success" }`

## Test Print (per station)

```
GET /api/setting/test-print/{station_id}
```

Cetak tes ke **1 station** spesifik. Response: `{ "code": 0, "message": "OK" }` kalau `PrintServices::PrintTest()` balikin string `"OK"`, selain itu `{ "code": 100, "message": "<pesan error dari PrintServices>" }`.

## Customer Display (dihapus 2026-10-14)

Dulu ada `GET /api/setting/customer_display` yang menjalankan raw shell command (`exec("chrome.exe --new-window ...")`) dari backend untuk membuka jendela Chrome di PC kasir — **sudah dihapus total** (route, method `customer_display()` di `SettingController.php`, dan pemanggil dead-nya `showcustomerdisplay()` di `Navbar.vue`), karena ternyata tidak pernah dipanggil dari tombol manapun di UI.

Jalur yang **benar-benar dipakai** untuk membuka Customer Display murni client-side, tanpa API sama sekali: tombol di `Navbar.vue` (`customerDisplayOpen()`) langsung `window.open('/display_customer', '_blank')`. Lihat detail arsitektur cross-tab (localStorage + Pinia `persist`) di dokumentasi Display Customer / `PETA HALAMAN.md`.

## Alur Implementasi

- `test-print-all`/`test-print/{id}` dipanggil dari tombol "Test Print" di `SettingPage.vue`, per-station atau sekali untuk semua station.

## Sumber

- `app/Http/Controllers/SettingController.php` — `testPrintAll()`, `testPrint()`.
- `app/Services/PrintServices.php` — `PrintTest()`.
- Frontend: `src/services/SettingServices.ts` (`testPrintAll`, `testPrint`), `src/modules/dashboard/setting/SettingPage.vue`.

## Status

Didokumentasikan 2026-10-12, berdasar pembacaan kode langsung. Diupdate 2026-10-14: endpoint `customer_display` (`exec()` shell command) dihapus karena dead code, tidak pernah dipanggil dari UI.
