# Auth

Base path: `/api/auth`

Login kasir POS — **PIN numerik**, bukan username/password (beda dari login ERP di [`../../SETUP/SETUP WIZARD.md`](../../SETUP/SETUP%20WIZARD.md) yang pakai username/password staff ERP). Session disimpan di tabel `tr_session` lokal (bukan Laravel session bawaan), token-nya langsung `session_id` (UUID), dikirim via `Authorization: Bearer <token>`.

## Login

```
POST /api/auth/login
```

**Request:**
```json
{ "pin": "123456" }
```

**Response sukses:**
```json
{
  "code": 0,
  "message": "success login!",
  "data": { "token": "a1b2c3d4-...-uuid" }
}
```

**Response gagal:**
```json
{ "code": 100, "message": "Incorrect password!" }
```

- Cari `MasterUserModel` yang kolom `sandi`-nya **persis sama** dengan `pin` yang dikirim — **perbandingan string polos, bukan hash** (`where('sandi', $request->input('pin'))`). Tidak ada rate-limit/lockout percobaan gagal di endpoint ini.
- Sukses → buat baris baru di `SessionModel` (`session_id` = UUID baru, `data` = seluruh row user di-encode JSON) → `token` yang dibalikin = `session_id` itu, dipakai sebagai Bearer token di semua request berikutnya.
- **Tidak ada pengecekan `pin` kosong/format** di level endpoint — `pin` kosong/apa pun yang tidak cocok ke `sandi` manapun di DB sama-sama balik "Incorrect password!".

## Logout

```
GET /api/auth/logout
```

Hapus baris `SessionModel` yang `session_id`-nya sama dengan Bearer token di request. Response: `{ "code": 0, "message": "success logout!" }` — **tidak error** walau token yang dikirim sudah tidak ada/invalid (`delete()` pada query kosong tetap dianggap sukses).

## Info

```
GET /api/auth/info
```

Baca balik data user yang tersimpan di session (`SessionModel.data`, JSON yang di-dump saat login) berdasarkan Bearer token.

Response: `{ "code": 0, "data": { ...seluruh kolom MasterUserModel, termasuk "sandi"... } }`.

**Catatan keamanan**: `data` di sini adalah **snapshot penuh** row `MasterUserModel` saat login (termasuk kolom `sandi`/PIN) — bukan hanya field yang aman ditampilkan. Kalau user diubah (nonaktif, ganti role) setelah login, endpoint ini **tidak** mencerminkan perubahan itu sampai login ulang (data beku dari saat login, bukan live query ke `MasterUserModel`).

Token invalid/sudah logout → `$data` null → `json_decode(null->data)` akan **throw error PHP** (akses property dari null), ditangkap `catch` generik, balik `{ "code": 100, "message": "..." }` dengan pesan error PHP mentah (bukan pesan yang didesain, mis. bukan "token tidak ditemukan" yang eksplisit).

## Alur Implementasi

1. **Lock screen** (`LockscreenPage.vue` via `useAuth.ts`) — kasir input PIN lewat virtual keyboard → `LoginPin()` → `POST /auth/login`. Sukses → `token` disimpan ke `useAuthStore` (persisted, dipakai header `Authorization` semua request berikutnya lewat axios interceptor) → redirect ke Dashboard.
2. **Load data awal** (`globalstore.js::loadData()`) — dipanggil tiap Dashboard dimuat, salah satu dari 4 panggilan paralel (`master/branch`, `dayshift/get`, `auth/info`, `setting/load`). `auth/info` gagal (`code !== 0`) **tidak** memblokir load data lain — cuma `user.value` tetap kosong.
3. **Logout** — tombol logout panggil `Logout()` → `GET /auth/logout`, lalu FE hapus token dari `useAuthStore` sendiri (endpoint tidak mengirim instruksi clear-side, FE yang tanggung jawab).

**Catatan buat migrasi FE baru**: endpoint `info` rawan error PHP mentah kalau dipanggil dengan token basi — sebaiknya FE baru treat **semua** response `code !== 0` dari endpoint ini sebagai "perlu login ulang", jangan parsing pesan error-nya secara spesifik.

## Sumber

- `app/Http/Controllers/AuthController.php` — `login()`, `logout()`, `info()`.
- `app/Models/MasterUserModel.php`, `app/Models/SessionModel.php`.
- `routes/api.php` — group `Route::prefix('auth')`.
- Frontend: `src/modules/lockscreen/composables/useAuth.ts`, `src/stores/globalstore.js`, `src/stores/useAuthStore.ts` (persisted token).

## Status

Didokumentasikan 2026-10-12, berdasar pembacaan kode langsung (belum ada verifikasi ulang lewat HTTP request live di sesi ini).
