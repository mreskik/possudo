# Kiosk Day Status

```
GET /api/kiosk/day-status
```

Gak butuh token. Cek toko lagi buka apa engga — ada dayshift aktif (`dayin_time` keisi, `dayout_time` masih `NULL`).

Response:

```json
{
  "code": 0,
  "data": {
    "is_open": true,
    "dayin_time": "2026-08-10 10:49:28",
    "ulid": "POSKPS20260810104928"
  }
}
```

Kalau gak ada dayshift aktif:

```json
{
  "code": 0,
  "data": {
    "is_open": false,
    "dayin_time": null,
    "ulid": null
  }
}
```

`ulid` — dayshift_ulid dari dayshift yang lagi aktif, dipakai kiosk pas nulis order (`dayshift_ulid` di `tr_order`).

Sumber: `DayShiftServices::GetDayShift()` (`WHERE dayout_time IS NULL`), reuse dari yang udah dipakai di alur kasir.
