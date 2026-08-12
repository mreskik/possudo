# Kiosk Day Status

```
GET /api/kiosk/day-status
```

Gak butuh token. Cek toko lagi buka apa engga — gabungan **jam operasional branch** (`mr_branch_ops_setting`, per hari) dan **status dayshift** (`dayin_time` keisi, `dayout_time` masih `NULL`). Kiosk pakai ini sebelum ngizinin self-order.

Response (buka):

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

Response (tutup — jam operasional atau dayshift belum ada):

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

`ulid` — dayshift_ulid dari dayshift yang lagi aktif (kalau ada), dipakai kiosk pas nulis order (`dayshift_ulid` di `tr_order`). `dayin_time`/`ulid` tetap diisi apa adanya kalau dayshift-nya ada, **walau** `is_open` jadi `false` gara-gara di luar jam operasional (lihat step 3 di bawah) — jangan dipakai buat nentuin boleh-order-atau-enggak, itu tugasnya `is_open`.

## Urutan cek (`DayShiftServices::GetKioskDayStatus()`)

Dicek satu-satu, berhenti di step pertama yang nentuin hasil:

1. **`mr_branch_ops_setting` gak punya baris buat hari ini** (`day` = nama hari sekarang, lowercase Inggris: `monday`...`sunday`) → **error** (`code: 100`), bukan `is_open: false` — data jam operasionalnya belum ke-pull/belum disetting di ERP, gak bisa dijawab beneran buka/tutup tanpa ini.

   ```json
   { "code": 100, "message": "branch ops setting belum di-pull, tidak bisa cek jam operasional" }
   ```

2. **`status = 'closed'`** → `is_open: false`, gak peduli dayshift lagi aktif atau enggak (toko sengaja tutup hari ini).

3. **`status = 'open'` dan sekarang di luar `[open_time, closed_time]`** → `is_open: false` — **prioritas di atas status dayshift**. Ini kasus yang paling sering: kasir kadang sengaja belum dayout (masih ngurus selisih kas dll), tapi customer tetap gak boleh order lewat kiosk begitu lewat jam tutup.

4. Sisanya (`status = 'always_open'`, atau `status = 'open'` dan masih dalam jam) → `is_open` ngikutin status dayshift murni: `dayin_time` keisi **dan** `dayout_time` masih `NULL`.

## Sumber

`DayShiftServices::GetKioskDayStatus()` — beda dari `DayShiftServices::GetDayShift()` yang dipakai di sisi kasir (`DayShiftController::CurrentDay`), yang itu murni cek dayshift tanpa jam operasional. Data jam operasional ditarik dari tabel lokal `mr_branch_ops_setting` (di-pull dari ERP, lihat `SYNC PULL.md`).

## Tervalidasi live (2026-08-11)

4 kombinasi status dicoba pakai dayshift aktif: `open` + dalam jam → `is_open: true`; `closed` → `false`; `open` + di luar jam (walau dayshift masih aktif) → `false`; `always_open` → `true`. Kasus tabel `mr_branch_ops_setting` kosong (belum pernah pull) → `code: 100` sesuai desain. Data test udah dibersihin.
