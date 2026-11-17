# Station List

```
GET /api/master/station-list
```

Semua station (printer/dapur/bar, dsb) — `StationModel::get()` apa adanya, tanpa filter `is_active`.

Response:
```json
{
  "code": 0,
  "data": [
    {
      "id": 12,
      "branch_id": 51,
      "name": "KASIR",
      "printer_name": "RECIPT",
      "printer_type": 1,
      "printer_connection": 1,
      "printing_mode": null,
      "port": null,
      "auto_cut": null,
      "cash_drawer": null,
      "line_character": null,
      "is_active": true
    },
    {
      "id": 15,
      "branch_id": 51,
      "name": "HALO",
      "printer_name": "192.168.1.50",
      "printer_type": 1,
      "printer_connection": 2,
      "printing_mode": null,
      "port": "9100",
      "auto_cut": null,
      "cash_drawer": null,
      "line_character": null,
      "is_active": true
    }
  ]
}
```

- `StationModel::get()` apa adanya — SELURUH kolom `mr_station` ikut dikirim, tidak ada whitelist.
- **Tidak difilter `is_active`** (beda dari [`TABLE SECTION.md`](TABLE%20SECTION.md) yang filter aktif) — semua station ikut dibalikin, termasuk yang nonaktif. FE perlu filter sendiri kalau cuma mau yang aktif.
- Data kosong sama sekali (bukan error) → `{ "code": 100, "message": "station gak muncul!" }` — **perilaku tidak umum**: array kosong dianggap kegagalan, bukan hasil valid "belum ada station" (beda dari kebanyakan endpoint List lain yang balikin `[]` dengan `code: 0`).

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

- `app/Http/Controllers/MasterController.php` — `GetStationList()`.
- `app/Services/PrintServices.php` — `resolvePrintConnector()` (baru, 2026-11-17, private) — dipakai 10 lokasi (semua fungsi print ESC/POS thermal yang sebelumnya `new WindowsPrintConnector($data_station->printer_name)` langsung).
- `app/Services/GeneralLabel.php` — `setNamePrinter()` (diselaraskan 2026-11-17, signature berubah jadi terima objek station, plus fix bug null pre-existing di catch block).
- Frontend: `src/services/MasterServices.ts` (`getStationList`).

## Status

Didokumentasikan 2026-10-12, berdasar pembacaan kode langsung (belum ada verifikasi ulang lewat HTTP request live di sesi ini).

Diupdate 2026-11-17: **Network Printer Connection diimplementasikan** — `PrintServices.php` sebelumnya SELALU pakai `WindowsPrintConnector`, walau kolom `printer_connection`/`port` sudah ada di skema sejak awal (gak pernah dibaca logic print manapun, cuma dipakai buat lookup nama di Kiosk). Sekarang 10 lokasi `new WindowsPrintConnector($data_station->printer_name)` diganti panggil `self::resolvePrintConnector($data_station)`, yang membaca `printer_connection` dan pilih connector yang sesuai. `GeneralLabel::setNamePrinter()` (dipakai `PrintPriparationStation()`/`PrintTest()` buat label/stiker, jalur TSPL terpisah dari thermal) juga diselaraskan + 1 bug pre-existing ditemukan+diperbaiki sekaligus (lihat section `printer_connection` di atas). `php -l` bersih di kedua file, tervalidasi via test langsung (reflection ke `resolvePrintConnector()`, instansiasi langsung `GeneralLabel::setNamePrinter()` dengan 3 skenario: string backward-compat, connection=1, connection=2) — **belum ada test cetak fisik ke printer network sungguhan** di sesi ini (gak ada printer network tersedia buat dites).
