# Kiosk Verify Sandi

```
POST /api/kiosk/verify-sandi
Content-Type: application/json
```

Gak butuh token. Cek `sandi` staff (kolom `mr_user.sandi`) — dipakai buat **buka halaman Setting di Kiosk**. Murni perbantuan (sandi valid = boleh masuk), **bukan login** — beda dari `POST /api/auth/login` (dipakai kasir buat login penuh, bikin session/token). Endpoint ini gak nyimpen/bikin session apapun, cuma jawab valid apa enggak tiap dipanggil.

Body:
```json
{ "sandi": "9999" }
```

Response (sandi valid):
```json
{
  "code": 0,
  "message": "Sandi benar!",
  "data": {
    "id": 1,
    "username": "sudoalpa",
    "fullname": "Muhammad Al Fathan"
  }
}
```

Response (sandi salah/gak ketemu):
```json
{ "code": 100, "message": "Sandi salah!" }
```

## Catatan

- **`sandi` disimpan plaintext** di `mr_user` (bukan hash) — compare-nya langsung `WHERE sandi = ?`, sama pola dengan `AuthController::login` (login kasir pakai field `pin`, endpoint ini pakai `sandi` — beda nama field doang, kolom yang dicek sama).
- **Gak ada scoping tambahan** (branch/role/hak approve) — sengaja dibikin sesimpel mungkin karena kegunaannya cuma buka halaman Setting, bukan approval transaksi yang butuh jejak siapa-approve-apa.
- `data` cuma dikirim balik kalau sandi valid — buat ditampilin di UI kiosk kalau perlu (mis. "dibuka oleh Muhammad Al Fathan"), opsional dipakai atau enggak.

## Tervalidasi live (2026-08-28)

- Sandi valid (`9999`, milik user id 1) → `code: 0`, `data` keisi id/username/fullname bener.
- Sandi salah (`"salahbanget"`) → `code: 100, message: "Sandi salah!"`.
- Body kosong / `sandi` gak dikirim → `code: 100, message: "Sandi salah!"` (gak nge-match user manapun, aman gak ada bypass).
