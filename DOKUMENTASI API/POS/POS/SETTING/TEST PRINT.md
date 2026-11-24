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

## Isi cetak tes per tipe station (`mr_station.printer_type`)

| Tipe | Nama (`mr_printer_type`) | Yang dicetak |
|---|---|---|
| 1 | Thermal Printer | judul `TEST PRINT`, `Station   : <nama>`, `Printer`, `Char/Line`, `Time`, penggaris angka sepanjang `line_character`, `Printer OK` |
| 2 | Epson Sticker | **3 baris** (label kecil 40×60 mm): `Station: <nama>` (baris pertama, **baru 2026-11-24**), penggaris angka sepanjang `line_character`, `Printer OK` |
| 3 | GPrinter Sticker | **belum didukung** — `PrintTest()` mengembalikan `ERR` (di `test-print` jadi `code: 100`, di `test-print-all` tercatat `ERR` untuk station itu). Dibiarkan sementara atas keputusan owner |

Baris `Station: <nama>` pada tipe 2: dipotong sesuai `line_character` (diakhiri `..` kalau kepanjangan, contoh 20 kolom: `Station: STATION D..`), dan tanda kutip ganda `"` di nama station diganti kutip tunggal `'` karena teks disisipkan mentah ke perintah label `TEXT x,y,"font",0,1,1,"<teks>"`.

## Alur Implementasi

- `test-print-all`/`test-print/{id}` dipanggil dari tombol "Test Print" di `SettingPage.vue`, per-station atau sekali untuk semua station.

## Sumber

- `app/Http/Controllers/SettingController.php` — `testPrintAll()`, `testPrint()`.
- `app/Services/PrintServices.php` — `PrintTest()`.
- Frontend: `src/services/SettingServices.ts` (`testPrintAll`, `testPrint`), `src/modules/dashboard/setting/SettingPage.vue`.

## Status

Didokumentasikan 2026-10-12, berdasar pembacaan kode langsung.

Diupdate 2026-11-24: cetak tes station **Epson Sticker** (tipe 2) kini memuat nama station (`Station: <nama>`). Dites dengan memanggil `PrintServices::PrintTest()` ke station sementara yang menunjuk ke listener TCP lokal (bukan printer fisik): nama pendek, nama panjang (dipotong), nama bertanda kutip ganda (diganti), tipe 1 tetap seperti semula, tipe 3 tetap `ERR`. Station uji dihapus setelahnya. Printer sticker fisik belum dites.
