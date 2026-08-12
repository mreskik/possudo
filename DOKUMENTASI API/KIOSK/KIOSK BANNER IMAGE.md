# Kiosk Banner Image

```
GET /api/kiosk/banner-image
```

Gak butuh token. Daftar gambar buat layar Kiosk (banner/slideshow) — filter channel `cd_kiosk` udah fix di query (bukan parameter client, gak bisa diminta channel lain lewat endpoint ini).

Response:

```json
{
  "code": 0,
  "data": [
    { "image_src": "/img/master-image/019f648a-f12e-7252-a76f-aa8fc55679bf.jpg", "sequence": 1 },
    { "image_src": "/img/master-image/0190f7f0-....jpg", "sequence": 2 }
  ]
}
```

Kosong (`[]`) kalau belum ada campaign gambar yang aktif & target `cd_kiosk` buat branch ini.

- `image_src` — **path lokal** (bukan path ERP) — file-nya udah didownload beneran ke POS pas sync pull (`SetupServices::getMasterImageList()`, lihat `SYNC PULL.md`), bukan cuma nyimpen path mentah dari ERP. Frontend prefix sendiri pakai base URL Laravel, sama pola kayak `image_src`/`icon_src` item di endpoint Kiosk lain.
- `sequence` — urutan tampil (dipakai buat urutan slideshow), makin kecil makin duluan. Response udah urut `ASC` dari server, frontend gak perlu sort ulang.
- Cuma campaign yang `is_active = true` (di `mr_image`) yang ikut muncul.

## Sumber

```sql
SELECT mil.image_src, mil.sequence
FROM mr_image_list mil
JOIN mr_image mi ON mi.id = mil.master_image_id
JOIN mr_image_list_apply_for milaf ON milaf.master_image_list_id = mil.id
WHERE mi.is_active = 1 AND milaf.apply_for = 'cd_kiosk'
ORDER BY mil.sequence ASC
```

Data-nya sepenuhnya dari tabel sync lokal (`mr_image`/`mr_image_list`/`mr_image_list_apply_for`, ditarik dari ERP `master_image`/`master_image_list`/`master_image_list_apply_for` — lihat `MASTER IMAGE.md` di sudocore2 buat skema aslinya) — **bukan** live lookup ke ERP (beda dari `KIOSK MEMBER CHECK.md`), karena gambar campaign gak perlu real-time banget, cukup ke-update tiap sync jalan.

Endpoint sejenis buat channel POS (`cd_pos`, customer display kasir) **belum dibikin** — baru Kiosk yang ada endpoint konsumsinya, sisi POS masih nyusul.

## Tervalidasi live (2026-08-12)

Insert campaign test di ERP (branch scoped, 1 gambar target `cd_kiosk`) pakai gambar asli yang ada di storage ERP → pull 3 endpoint sync (`get_master_image`, `get_master_image_list`, `get_master_image_list_apply_for`) → gambar beneran ke-download ke `public/img/master-image/` → `GET /api/kiosk/banner-image` (nama lama sebelum rename: `/api/kiosk/images`, method controller `GetBannerImageKiosk()` sebelumnya `GetImages()`) balikin data yang bener, path lokalnya bisa diakses langsung (`200`). Data test + file gambar dibersihin abis verifikasi.

## Update (2026-08-12, rename)

Endpoint & method controller di-rename biar lebih jelas maksudnya: `/api/kiosk/images` → `/api/kiosk/banner-image`, `KioskController::GetImages()` → `KioskController::GetBannerImageKiosk()`. Logic/query gak berubah sama sekali, cuma penamaan. Tervalidasi: route lama udah gak terdaftar (`php artisan route:list` cuma nunjukin `banner-image`), route baru jalan normal.
