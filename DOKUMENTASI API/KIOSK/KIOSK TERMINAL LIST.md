# Kiosk Terminal List

```
GET /api/kiosk/terminal
```

Gak butuh token. Daftar terminal yang **ber-type Kiosk doang** (`mr_pos_type.device_type = 'kiosk'`) — dipakai buat halaman pilih terminal pas kiosk pertama kali di-setup, sebelum device-nya tau `id` terminal mana yang harus dipanggil ke `GET /api/kiosk/terminal/{id}` (lihat `KIOSK TERMINAL DETAIL.md`). Terminal dengan `pos_type` lain (POS Desktop/mobile, Worker Mobile Customer) **tidak muncul** di sini.

Response sengaja **minimal** — cuma `id`, `name`, `device_type`. Field lengkap (branch, table section, receipt station, dst) baru diambil pas user beneran milih 1 terminal spesifik lewat endpoint Detail.

```json
{
  "code": 0,
  "data": [
    { "id": 4, "name": "KIOSK 1", "device_type": "kiosk" }
  ]
}
```

## Catatan

- **Gak ada filter/query string apapun** — selalu balikin semua terminal `device_type='kiosk'`, urut `ORDER BY t.name ASC`.
- `device_type` di sini selalu `"kiosk"` (itu justru filter-nya) — dicantumin di response murni buat konsistensi/kejelasan, bukan buat dibedain.
- **Gak ada pagination** — konsisten sama semua endpoint list lain di codebase ini.

## Tervalidasi live (2026-08-28)

- `GET /api/kiosk/terminal` — balikin `[{"id":4,"name":"KIOSK 1","device_type":"kiosk"}]` (data dev saat ini, cuma 1 kiosk).
- Endpoint Detail (`GET /api/kiosk/terminal/4`) dites ulang bareng, tetap jalan normal seperti sebelumnya.
