# Terminal List

```
GET /api/master/terminal-list
```

Semua terminal yang aktif, JOIN `mr_pos_type` (tipe device: POS/Kiosk/dll).

Response:
```json
{
  "code": 0,
  "data": [
    {
      "id": 1,
      "name": "Kasir 1",
      "pos_type_id": 1,
      "pos_type_name": "POS",
      "pos_type_device_type": "desktop",
      "table_section_id": 1
    }
  ]
}
```

- Filter `is_active = true`.
- `pos_type_device_type` — dipakai menentukan device ini POS biasa atau Kiosk (lihat `localStorage.terminal_type === '3'` di `router/index.js`, redirect ke bundle `/kiosk` kalau Kiosk).

## Alur Implementasi

Dipanggil di `TerminalPage.vue` saat device pertama kali pilih terminal (atau ganti terminal) — hasil pilihan disimpan ke `localStorage` (`terminal_id`, `terminal_name`, `terminal_type`), dibaca ulang tiap request lewat `router.beforeEach` guard.

## Sumber

- `app/Http/Controllers/MasterController.php` — `GetTerminalList()`.
- Frontend: `src/services/TerminalServices.ts`, `src/modules/setup/TerminalPage.vue`, `src/router/index.js`.

## Status

Didokumentasikan 2026-10-12, berdasar pembacaan kode langsung (belum ada verifikasi ulang lewat HTTP request live di sesi ini).
