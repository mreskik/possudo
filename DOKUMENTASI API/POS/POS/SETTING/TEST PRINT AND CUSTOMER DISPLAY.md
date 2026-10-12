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

## Customer Display

```
GET /api/setting/customer_display
```

**⚠️ BUKAN endpoint data biasa — efek samping langsung membuka jendela browser di komputer server/kasir.**

Kalau `SettingModel.use_customer_display === true`, backend menjalankan **raw shell command**:
```php
exec("chrome.exe --new-window http://localhost:5173/display_customer");
```
— ini men-spawn proses Chrome **langsung dari proses PHP backend** (bukan dari browser kasir yang memanggil API), dengan URL **hardcoded ke `localhost:5173`** (port dev server Vite, bukan URL production manapun).

Response: body kosong (`response('')`) — tidak ada `code`/`message` standar seperti endpoint lain.

**Kenapa ini kritis untuk migrasi:**
- **`localhost:5173` adalah port dev Vite** — di environment production FE biasanya di-build jadi static files dan disajikan di port lain (atau domain berbeda sama sekali). Baris ini **tidak portable**, kemungkinan besar peninggalan development yang belum diupdate ke konfigurasi production, atau memang environment deploy POS saat ini kebetulan masih menjalankan dev server di tiap kasir (perlu dikonfirmasi ke tim infra sebelum migrasi, jangan diasumsikan).
- **Proses launching GUI dari backend API** — pola ini cuma masuk akal kalau backend Laravel dan Chrome yang dibuka **jalan di komputer fisik yang sama** (1 PC kasir = 1 instance backend lokal, sesuai arsitektur POS yang desktop-based/per-outlet). **Tidak akan bekerja** kalau arsitektur baru memindahkan backend ke server terpusat/cloud — `exec()` akan jalan di server, bukan di PC kasir, membuka Chrome di tempat yang salah (atau gagal karena server headless).
- Ada baris **commented-out** di atasnya menunjukkan "versi asli" yang dimaksud — memakai path lengkap Chromium Windows beserta argumen posisi jendela (`--window-position`, dll, selaras dengan `customer_display_left`/`customer_display_top` dari [`SETTING.md`](SETTING.md)) — menunjukkan awalnya endpoint ini dirancang untuk mem-posisikan window Customer Display secara presisi di monitor kedua, lalu disederhanakan ke versi `chrome.exe` polos yang sekarang (kemungkinan sementara/debugging yang tidak pernah dikembalikan).

**Rekomendasi migrasi**: endpoint ini **tidak bisa langsung di-port apa adanya** ke arsitektur FE baru manapun yang tidak 1:1 desktop-per-outlet. Perlu didesain ulang — kemungkinan jadi murni tanggung jawab FE (buka window baru via `window.open()` dari browser kasir sendiri), bukan backend yang mengeksekusi shell command.

## Alur Implementasi

- `test-print-all`/`test-print/{id}` dipanggil dari tombol "Test Print" di `SettingPage.vue`, per-station atau sekali untuk semua station.
- `customer_display` dipanggil **langsung dari `Navbar.vue:157`** (`await API.get('/setting/customer_display')`), bukan dari `SettingServices.ts` — dipicu dari tombol/menu di Navbar, bukan dari halaman Setting itu sendiri. Kasir klik tombol ini setiap kali ingin membuka ulang/menampilkan jendela Customer Display (misal setelah sempat ditutup).

## Sumber

- `app/Http/Controllers/SettingController.php` — `testPrintAll()`, `testPrint()`, `customer_display()`.
- `app/Services/PrintServices.php` — `PrintTest()`.
- Frontend: `src/services/SettingServices.ts` (`testPrintAll`, `testPrint` — **tidak** ada wrapper untuk `customer_display`), `src/component/Navbar.vue:157` (panggil `customer_display` langsung via `API.get`), `src/modules/dashboard/setting/SettingPage.vue`.

## Status

Didokumentasikan 2026-10-12, berdasar pembacaan kode langsung (belum ada verifikasi ulang lewat HTTP request live di sesi ini). Perilaku `exec()` tidak dites langsung (butuh environment desktop nyata dengan Chrome terpasang) — ditandai sebagai catatan arsitektur kritis untuk tim migrasi, bukan klaim hasil pengujian.
