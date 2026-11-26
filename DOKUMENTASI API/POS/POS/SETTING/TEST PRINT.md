# Test Print

## Test Print All

```
GET /api/setting/test-print-all
```

Loop **semua** `StationModel` yang ada, panggil `PrintServices::PrintTest($station->id)` per station — satu request memicu banyak kali cetak fisik (1x per station terdaftar), bukan 1x cetak saja.

Response (sesuai kode `SettingController::testPrintAll()`, dikoreksi 2026-11-26 — sebelumnya tertulis `{ "code": 0, "message": "success" }`): `{ "code": 0, "data": [ { "station_id": 20, "station_name": "RECIPT", "printer_name": "RECIPT", "result": "OK" }, ... ] }` — satu item per station, `result` = `OK` atau pesan error station itu (mis. `ERR` untuk tipe 3). `code` selalu `0` kalau loop-nya sendiri tidak error; kegagalan per station ada di `result`.

## Test Print (per station)

```
GET /api/setting/test-print/{station_id}
```

Cetak tes ke **1 station** spesifik. Response: `{ "code": 0, "message": "OK" }` kalau `PrintServices::PrintTest()` balikin string `"OK"`, selain itu `{ "code": 100, "message": "<pesan error dari PrintServices>" }`.

## Isi cetak tes per tipe station (`mr_station.printer_type`)

| Tipe | Nama (`mr_printer_type`) | Yang dicetak |
|---|---|---|
| 1 | Thermal Printer | **logo header branch** (baru 2026-11-26, lihat bagian Logo di bawah), judul `TEST PRINT`, `Station   : <nama>`, `Printer`, `Char/Line`, `Logo      : <keterangan>`, `Time`, penggaris angka sepanjang `line_character`, `Printer OK` |
| 2 | Epson Sticker | **3 baris** (label kecil 40×60 mm): `Station: <nama>` (baris pertama, **baru 2026-11-24**), penggaris angka sepanjang `line_character`, `Printer OK` |
| 3 | GPrinter Sticker | **belum didukung** — `PrintTest()` mengembalikan `ERR` (di `test-print` jadi `code: 100`, di `test-print-all` tercatat `ERR` untuk station itu). Dibiarkan sementara atas keputusan owner |

Baris `Station: <nama>` pada tipe 2: dipotong sesuai `line_character` (diakhiri `..` kalau kepanjangan, contoh 20 kolom: `Station: STATION D..`), dan tanda kutip ganda `"` di nama station diganti kutip tunggal `'` karena teks disisipkan mentah ke perintah label `TEXT x,y,"font",0,1,1,"<teks>"`.

## Logo di tes print thermal (2026-11-26)

Tujuan: mengetahui ukuran logo branch sebenarnya di struk. Cetak tes station **thermal** (`printer_type == 1`, dicek lewat kode tipe, bukan nama station) memuat logo header branch (`mr_branch.logo_header_src`, file di `public/img/branch/`) di bagian PALING ATAS, sebelum `TEST PRINT`. Berlaku juga di `test-print-all` (hanya untuk station thermal; station label tipe 2 tidak mencetak logo).

- **Lebar logo = `PrintServices::LOGO_PRINT_WIDTH` (180 px)**, konstanta yang SAMA dipakai `PrintPayment()` dan `PrintTopup()` — satu sumber, jadi ukuran di tes print selalu sama dengan ukuran di struk payment. Tinggi mengikuti rasio gambar asli. Lebar tidak mengikuti lebar kertas (180 px kira-kira 47% kertas 58mm / 31% kertas 80mm).
- Baris `Logo      : ...` di struk tes menunjukkan hasilnya: `180px (asli 147x134)` kalau logo tercetak, atau alasannya: `(branch has no logo)` (kolom `logo_header_src` kosong), `file not found: <path>` (file belum ter-download ke lokal — jalankan sync branch setting/images), `File bukan gambar yang valid`, `Format tidak didukung` (selain PNG/JPEG).
- Resize memakai **file sementara unik** (`sys_get_temp_dir()`, dihapus lagi setelah dicetak), bukan `public/logo_resize.png` yang dipakai bill — tes tidak menimpa file bill dan tidak bisa mencetak logo basi.

**Perbaikan terkait di print payment/topup (2026-11-26, bug nyata di kode)**: `resizeGambar()` sekarang return `true` kalau berhasil (sebelumnya `null`), dan 4 pemanggilnya (logo + footer di `PrintPayment()` dan `PrintTopup()`) mengecek `=== true` sebelum memuat file hasil resize. Sebelumnya hasil resize diabaikan, jadi gambar yang gagal di-resize (mis. WebP) tetap lanjut memuat `logo_resize.png`/`footer_resize.png` sisa lama dan yang tercetak logo/footer basi. Sekarang bagian itu dilewati. Juga: `getimagesize()` false (bukan gambar) tidak lagi memicu warning, dan tinggi hasil resize dibulatkan ke integer.

## Alur Implementasi

- `test-print-all`/`test-print/{id}` dipanggil dari tombol "Test Print" di `SettingPage.vue`, per-station atau sekali untuk semua station.

## Sumber

- `app/Http/Controllers/SettingController.php` — `testPrintAll()`, `testPrint()`.
- `app/Services/PrintServices.php` — `PrintTest()`.
- Frontend: `src/services/SettingServices.ts` (`testPrintAll`, `testPrint`), `src/modules/dashboard/setting/SettingPage.vue`.

## Status

Didokumentasikan 2026-10-12, berdasar pembacaan kode langsung.

Diupdate 2026-11-24: cetak tes station **Epson Sticker** (tipe 2) kini memuat nama station (`Station: <nama>`). Dites dengan memanggil `PrintServices::PrintTest()` ke station sementara yang menunjuk ke listener TCP lokal (bukan printer fisik): nama pendek, nama panjang (dipotong), nama bertanda kutip ganda (diganti), tipe 1 tetap seperti semula, tipe 3 tetap `ERR`. Station uji dihapus setelahnya. Printer sticker fisik belum dites.

Diupdate 2026-11-26: cetak tes thermal memuat logo header branch dengan lebar yang sama dengan print payment (`LOGO_PRINT_WIDTH` = 180), plus perbaikan logo/footer basi di bill. Tervalidasi dengan memanggil `PrintServices::printBranchLogoForTest()` memakai printer tiruan (tanpa printer fisik) di dalam transaksi yang di-rollback: tanpa logo → `(branch has no logo)`; path tidak ada → `file not found`; PNG valid (147x134) → `180px (asli 147x134)` dan `bitImage` dipanggil (raster 180x164 px); file bukan gambar → `File bukan gambar yang valid`; `public/logo_resize.png` tidak tersentuh; tidak ada file sementara yang bocor; `mr_branch.logo_header_src` kembali NULL. **Belum diuji dengan printer fisik** (tidak ada printer di sesi ini) dan branch 53 sendiri saat ini belum punya logo (`logo_header_src` NULL) — pasang logo di ERP lalu sync branch setting + images sebelum mencoba tes print sesungguhnya.
