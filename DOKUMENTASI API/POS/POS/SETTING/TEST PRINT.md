# Test Print

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

## Alur Implementasi

- `test-print-all`/`test-print/{id}` dipanggil dari tombol "Test Print" di `SettingPage.vue`, per-station atau sekali untuk semua station.

## Sumber

- `app/Http/Controllers/SettingController.php` — `testPrintAll()`, `testPrint()`.
- `app/Services/PrintServices.php` — `PrintTest()`.
- Frontend: `src/services/SettingServices.ts` (`testPrintAll`, `testPrint`), `src/modules/dashboard/setting/SettingPage.vue`.

## Status

Didokumentasikan 2026-10-12, berdasar pembacaan kode langsung.
