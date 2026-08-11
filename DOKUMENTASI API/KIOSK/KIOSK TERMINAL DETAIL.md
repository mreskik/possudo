# Kiosk Terminal Detail

```
GET /api/kiosk/terminal/{id}
```

Gak butuh token. Detail 1 terminal apa adanya dari `mr_terminal` (gak ada join).

Response:

```json
{
  "code": 0,
  "data": {
    "id": 9,
    "name": "KIOSK 1",
    "branch_id": 52,
    "device_id": "",
    "pos_type_id": 3,
    "is_active": 1,
    "is_used": 0,
    "table_section_id": 36,
    "receipt_station_id": 22
  }
}
```

Kalau `id` gak ketemu:

```json
{ "code": 100, "message": "terminal tidak ditemukan" }
```
