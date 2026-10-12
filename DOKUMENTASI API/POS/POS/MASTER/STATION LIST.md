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
    { "id": 1, "name": "Kitchen", "printer_name": "EPSON-TM88", "is_active": true }
  ]
}
```

- **Tidak difilter `is_active`** (beda dari [`TABLE SECTION.md`](TABLE%20SECTION.md) yang filter aktif) — semua station ikut dibalikin, termasuk yang nonaktif. FE perlu filter sendiri kalau cuma mau yang aktif.
- Data kosong sama sekali (bukan error) → `{ "code": 100, "message": "station gak muncul!" }` — **perilaku tidak umum**: array kosong dianggap kegagalan, bukan hasil valid "belum ada station" (beda dari kebanyakan endpoint List lain yang balikin `[]` dengan `code: 0`).

## Alur Implementasi

Dipakai di tab Setting (routing print per station) dan saat Test Print (pilih station tujuan test).

## Sumber

- `app/Http/Controllers/MasterController.php` — `GetStationList()`.
- Frontend: `src/services/MasterServices.ts` (`getStationList`).

## Status

Didokumentasikan 2026-10-12, berdasar pembacaan kode langsung (belum ada verifikasi ulang lewat HTTP request live di sesi ini).
