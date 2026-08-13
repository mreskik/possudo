# Kiosk Terminal Detail

```
GET /api/kiosk/terminal/{id}
```

Gak butuh token. Detail 1 terminal dari `mr_terminal`, **plus join** `pos_type_name` (dari `mr_pos_type`), `branch_name` (dari `mr_branch`), `table_section_name` (dari `mr_table_section`), dan nested `receipt_station` (detail lengkap `mr_station`-nya, kalau ada).

Response:

```json
{
  "code": 0,
  "data": {
    "id": 4,
    "name": "KIOSK 1",
    "branch_id": 14,
    "branch_name": "TONAKO BANDUNG",
    "device_id": "",
    "pos_type_id": 3,
    "pos_type_name": "Kiosk",
    "pos_type_device_type": "kiosk",
    "is_active": true,
    "is_used": false,
    "table_section_id": 14,
    "table_section_name": "QUICK SERVICE",
    "receipt_station_id": 11,
    "flag_printer_frontend": false,
    "receipt_station": {
      "name": "LABEL",
      "printer_name": "LABEL",
      "printer_type": 2,
      "printer_connection": 1,
      "printing_mode": 1,
      "port": "9001",
      "auto_cut": false,
      "cash_drawer": true,
      "line_character": 32,
      "printer_type_name": "Epson Sticker",
      "printer_connection_name": "Windows Printer Connection",
      "printing_mode_name": "Standar Printing"
    }
  }
}
```

Kalau `id` gak ketemu:

```json
{ "code": 100, "message": "terminal tidak ditemukan" }
```

## Catatan

- **Boolean dinormalisasi eksplisit** ke `true`/`false` asli — `is_active`, `is_used`, `flag_printer_frontend` (terminal), `auto_cut`, `cash_drawer` (station). Default-nya PDO balikin `"0"`/`"1"` (string), bukan boolean JSON — di-cast manual di controller biar konsisten.
- **`pos_type_name`/`pos_type_device_type`** — join `mr_pos_type` via `pos_type_id` (`LEFT JOIN`, walau `pos_type_id` di `mr_terminal` sebenernya `NOT NULL` — tetep pakai left join buat jaga-jaga data yang gak konsisten).
- **`branch_name`** — join `mr_branch` via `branch_id`. **`table_section_name`** — join `mr_table_section` via `table_section_id`, `null` kalau `table_section_id`-nya emang `null`.
- **Urutan field response sengaja diatur** — tiap `*_id` diikuti langsung sama `*_name` pasangannya (`branch_id`→`branch_name`, `pos_type_id`→`pos_type_name`→`pos_type_device_type`, `table_section_id`→`table_section_name`), select-nya eksplisit kolom per kolom (bukan `t.*` ditempel di belakang) biar urutannya kekontrol. `receipt_station` (nested object) ada di paling akhir — itu properti baru yang di-assign belakangan di kode, gak bisa disisipin persis abis `receipt_station_id` kayak pasangan `*_id`/`*_name` yang lain.
- **`receipt_station`** — **`null`** kalau `receipt_station_id` kosong (terminal belum di-setup station-nya) — bukan error. Kalau ada, isinya row `mr_station` (**tanpa** `id`/`branch_id` — dibuang, udah kewakilan dari `receipt_station_id` dan `branch_id` di level header, gak perlu dobel) plus 3 nama hasil join ke tabel lookup:
  - `printer_type_name` ← `mr_printer_type` (`Thermal Printer` / `Epson Sticker` / `GPrinter Sticker`)
  - `printer_connection_name` ← `mr_printer_connection` (`Windows Printer Connection` / `Network Printer Connection` / `Android Bluetooth Connection` / `Android USB Printer Connection`)
  - `printing_mode_name` ← `mr_printing_mode` (`Standar Printing` / `Single Menu Printing` / `Qty Menu Printing`)
  
  3 tabel lookup ini **udah ada dari awal** (bukan ditambah sesi ini) — `printer_type`/`printer_connection`/`printing_mode` di `mr_station` itu FK ke situ, bukan enum integer tanpa makna.

## Tervalidasi live (2026-08-13)

- Terminal dengan `receipt_station_id` keisi → `receipt_station` lengkap (tanpa `id`/`branch_id`), semua boolean (`auto_cut`/`cash_drawer`) dan 3 nama lookup (`printer_type_name`/`printer_connection_name`/`printing_mode_name`) muncul bener.
- Terminal tanpa `table_section_id`/`receipt_station_id` (`null`) → `table_section_name: null`, `receipt_station: null`, gak error. `branch_name` tetap keisi (branch selalu ada).
- `id` gak ketemu → tetap `"terminal tidak ditemukan"`.
- `flag_printer_frontend`/`is_active`/`is_used` semua balik sebagai boolean asli (`true`/`false`), bukan `0`/`1`.
