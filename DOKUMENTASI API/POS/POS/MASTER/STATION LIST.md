# Station List

```
GET /api/master/station-list
```

Semua station (printer/dapur/bar, dsb) — `mr_station` apa adanya (SELURUH kolom, tidak ada whitelist) + 3 kolom lookup nama (2026-11-17, baru), tanpa filter `is_active`.

Response (data real dari DB dev, branch 27):
```json
{
  "code": 0,
  "data": [
    {
      "id": 12,
      "branch_id": 27,
      "name": "KASIR",
      "printer_name": "RECIPT",
      "printer_type": 1,
      "printer_type_name": "Thermal Printer",
      "printer_connection": 1,
      "printer_connection_name": "Windows Printer Connection",
      "printing_mode": 1,
      "printing_mode_name": "Standar Printing",
      "port": "",
      "auto_cut": false,
      "cash_drawer": true,
      "line_character": 48,
      "created_at": null,
      "updated_at": null
    },
    {
      "id": 15,
      "branch_id": 27,
      "name": "HALO",
      "printer_name": "asd",
      "printer_type": 1,
      "printer_type_name": "Thermal Printer",
      "printer_connection": 2,
      "printer_connection_name": "Network Printer Connection",
      "printing_mode": 1,
      "printing_mode_name": "Standar Printing",
      "port": "1233",
      "auto_cut": true,
      "cash_drawer": true,
      "line_character": 123,
      "created_at": null,
      "updated_at": null
    },
    {
      "id": 13,
      "branch_id": 27,
      "name": "BEVERAGE",
      "printer_name": "LABEL",
      "printer_type": 2,
      "printer_type_name": "Epson Sticker",
      "printer_connection": 1,
      "printer_connection_name": "Windows Printer Connection",
      "printing_mode": 1,
      "printing_mode_name": "Standar Printing",
      "port": "",
      "auto_cut": false,
      "cash_drawer": true,
      "line_character": 30,
      "created_at": null,
      "updated_at": null
    }
  ]
}
```

- **Field lama** (`s.*`, langsung dari `mr_station`) — SELURUH kolom tabel ikut dikirim apa adanya, tidak ada whitelist. **Tidak difilter `is_active`** (beda dari [`TABLE SECTION.md`](TABLE%20SECTION.md) yang filter aktif) — semua station ikut dibalikin, termasuk yang nonaktif. FE perlu filter sendiri kalau cuma mau yang aktif.
- **3 field lookup baru (2026-11-17)** — `printer_type_name`/`printer_connection_name`/`printing_mode_name`, hasil `LEFT JOIN` ke `mr_printer_type`/`mr_printer_connection`/`mr_printing_mode` by id (`pty.id = s.printer_type`, dst) — pola sama persis yang sudah dipakai `KioskController::GetTerminalDetail()` (field `receipt_station`). `LEFT JOIN` (bukan `JOIN`) — station yang kolom lookupnya masih `NULL`/belum pernah di-set tetap muncul, cuma `*_name`-nya ikut `null`, bukan baris station-nya hilang. Lihat tabel referensi `printer_type` dan `printer_connection` di bawah.
- **`auto_cut`/`cash_drawer` di-cast ke boolean (2026-11-17)** — kolomnya `tinyint(1)` di DB, `DB::table()->get()` yang dipakai sekarang (ganti dari `StationModel::get()`) gak auto-cast ke boolean PHP seperti Eloquent `$casts` — di-cast manual (`(bool)`) sebelum dikirim, jadi response beneran `true`/`false`, bukan `0`/`1` mentah.
- Data kosong sama sekali (bukan error) → `{ "code": 100, "message": "station gak muncul!" }` — **perilaku tidak umum**: array kosong dianggap kegagalan, bukan hasil valid "belum ada station" (beda dari kebanyakan endpoint List lain yang balikin `[]` dengan `code: 0`). **(2026-11-17, bug lama dibenerin sekalian)**: kode SEBELUMNYA pakai `if ($data)` ke Eloquent Collection hasil `StationModel::get()` — Collection SELALU truthy walau isinya kosong, jadi cabang `else` ini **sebenarnya mati/gak pernah tercapai** sebelum perubahan ini. Sekarang pakai `$data->isNotEmpty()` yang benar-benar mengecek isi Collection.

### `printer_type` (referensi `mr_printer_type`)

| `id` | `name` |
|---|---|
| 1 | Thermal Printer |
| 2 | Epson Sticker |
| 3 | GPrinter Sticker |

### `printing_mode` (referensi `mr_printing_mode`)

| `id` | `name` |
|---|---|
| 1 | Standar Printing |
| 2 | Single Menu Printing |
| 3 | Qty Menu Printing |

### `printer_connection` (referensi `mr_printer_connection`)

| `id` | `name` | `printer_name` artinya | Status implementasi |
|---|---|---|---|
| 1 | Windows Printer Connection | Nama printer yang terdaftar di **Windows Printer Settings** (Control Panel / Settings > Printers & Scanners) | ✅ Diimplementasikan (dipakai sejak awal) |
| 2 | Network Printer Connection | **IP address atau hostname** printer (contoh: `"192.168.1.50"`) — `port` diisi juga (default `9100` kalau kosong, port standar raw/JetDirect printing) | ✅ Diimplementasikan (baru, 2026-11-17) |
| 3 | Android Bluetooth Connection | — | ❌ Belum diimplementasikan di POS web/`PrintServices.php` (relevan buat app Android terpisah, kalau ada) |
| 4 | Android USB Printer Connection | — | ❌ Belum diimplementasikan di POS web/`PrintServices.php` (relevan buat app Android terpisah, kalau ada) |

**Penting**: `printer_name` **artinya beda tergantung `printer_connection`** — field yang sama di-reuse, bukan field terpisah per connection type (keputusan sadar, biar gak perlu migration kolom baru `printer_ip`). Kalau `printer_connection = 2`, isi `printer_name` **harus** IP/hostname, bukan nama printer Windows — salah isi bakal gagal konek (socket ke alamat yang salah), bukan error validasi yang jelas.

`PrintServices::resolvePrintConnector($data_station)` (private, dipakai internal semua fungsi print **ESC/POS thermal**) yang membaca `printer_connection` dan memutuskan `WindowsPrintConnector`/`NetworkPrintConnector` yang dipakai — connection type 3/4 FALLBACK ke `WindowsPrintConnector` (behavior lama) karena belum ada implementasinya, bukan error fatal.

**`GeneralLabel::setNamePrinter()` (label/stiker TSPL, dipakai `PrintPriparationStation()` & `PrintTest()` percabangan `printer_type == 2`)** — diselaraskan juga (2026-11-17) biar dukung Network Printer Connection, bukan cuma struk thermal. Signature berubah dari `setNamePrinter(string $name)` jadi `setNamePrinter($station)` — terima objek `$data_station` (bukan `$data_station->printer_name` doang), dengan **backward-compat**: kalau tetap dikasih string polos, otomatis dianggap `WindowsPrintConnector` seperti sebelumnya (gak breaking buat caller yang belum di-update). Logic percabangannya diduplikasi di class ini (bukan reuse `PrintServices::resolvePrintConnector()`) karena `GeneralLabel` adalah class independen tanpa dependency ke `PrintServices`.

**Bug pre-existing ditemukan+diperbaiki sekaligus (2026-11-17)**: catch block `setNamePrinter()` lama (`$this->printer->close(); throw $e;`) SELALU gagal dengan `Call to a member function close() on null` setiap kali connector gagal diinisialisasi — `$this->printer` masih `null` di titik itu (`Printer` instance baru dibuat di `sikat()`, SETELAH connector sukses), jadi exception asli (misal pesan network timeout yang penting buat debug) **selalu tertutupi** error null itu. Ditemukan saat test skenario `printer_connection=2` gagal konek. Fix: guard `if ($this->printer !== null)` sebelum `close()`.

**Tervalidasi (2026-11-17)**: dites via reflection langsung ke `resolvePrintConnector()` — `printer_connection=1` menghasilkan `WindowsPrintConnector` (class benar). `printer_connection=2` dengan `port` terisi (`"9999"`) maupun kosong (fallback `9100`) keduanya memanggil `NetworkPrintConnector` dan benar-benar mencoba `fsockopen()` ke alamat yang diminta (terbukti dari exception "connection actively refused" saat dites ke `127.0.0.1` yang tidak ada printer-nya — bukan error logic, tapi bukti socket benar-benar dicoba dibuka ke alamat & port yang benar).

## Alur Implementasi

Dipakai di tab Setting (routing print per station) dan saat Test Print (pilih station tujuan test).

## Sumber

- `app/Http/Controllers/MasterController.php` — `GetStationList()` (diubah 2026-11-17, dari `StationModel::get()` jadi raw query + 2 LEFT JOIN tambahan, plus cast boolean `auto_cut`/`cash_drawer`).
- `app/Services/PrintServices.php` — `resolvePrintConnector()` (baru, 2026-11-17, private) — dipakai 10 lokasi (semua fungsi print ESC/POS thermal yang sebelumnya `new WindowsPrintConnector($data_station->printer_name)` langsung).
- `app/Services/GeneralLabel.php` — `setNamePrinter()` (diselaraskan 2026-11-17, signature berubah jadi terima objek station, plus fix bug null pre-existing di catch block).
- Frontend: `src/services/MasterServices.ts` (`getStationList`).

## Status

Didokumentasikan 2026-10-12, berdasar pembacaan kode langsung (belum ada verifikasi ulang lewat HTTP request live di sesi ini).

Diupdate 2026-11-17: **Network Printer Connection diimplementasikan** — `PrintServices.php` sebelumnya SELALU pakai `WindowsPrintConnector`, walau kolom `printer_connection`/`port` sudah ada di skema sejak awal (gak pernah dibaca logic print manapun, cuma dipakai buat lookup nama di Kiosk). Sekarang 10 lokasi `new WindowsPrintConnector($data_station->printer_name)` diganti panggil `self::resolvePrintConnector($data_station)`, yang membaca `printer_connection` dan pilih connector yang sesuai. `GeneralLabel::setNamePrinter()` (dipakai `PrintPriparationStation()`/`PrintTest()` buat label/stiker, jalur TSPL terpisah dari thermal) juga diselaraskan + 1 bug pre-existing ditemukan+diperbaiki sekaligus (lihat section `printer_connection` di atas). `php -l` bersih di kedua file, tervalidasi via test langsung (reflection ke `resolvePrintConnector()`, instansiasi langsung `GeneralLabel::setNamePrinter()` dengan 3 skenario: string backward-compat, connection=1, connection=2) — **belum ada test cetak fisik ke printer network sungguhan** di sesi ini (gak ada printer network tersedia buat dites).

Diupdate 2026-11-17, lanjutan: **`printer_type_name`/`printer_connection_name`/`printing_mode_name` ditambahkan** ke response — `GetStationList()` diubah dari `StationModel::get()` jadi raw query (`DB::table('mr_station as s')`) + 3 `LEFT JOIN` ke tabel referensi, pola sama persis `KioskController::GetTerminalDetail()`. Sekalian cast `auto_cut`/`cash_drawer` (`tinyint(1)` di DB) ke boolean PHP asli, dan ganti guard array-kosong dari `if ($data)` (bug lama — Eloquent Collection selalu truthy, cabang gagal gak pernah tercapai) jadi `$data->isNotEmpty()` yang benar. **Tervalidasi live**: hit `GetStationList()` langsung lewat tinker, response berisi 3 station real (branch 27) dengan `printer_type_name`/`printer_connection_name`/`printing_mode_name` terisi benar sesuai lookup id-nya, `auto_cut`/`cash_drawer` tampil `true`/`false` (bukan `0`/`1`). `php -l` bersih.
