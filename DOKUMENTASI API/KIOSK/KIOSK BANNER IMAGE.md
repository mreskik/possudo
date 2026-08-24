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
    { "name": "Banner Kiosk 1", "banner_src": "/img/master-image/019f648a-f12e-7252-a76f-aa8fc55679bf.jpg", "sequence": 1 },
    { "name": "Banner Kiosk 2", "banner_src": "/img/master-image/0190f7f0-....jpg", "sequence": 2 }
  ]
}
```

Kosong (`[]`) kalau belum ada campaign gambar yang aktif & target `cd_kiosk` buat branch ini.

- `name` (2026-08-24) — nama gambar per-baris, apa adanya dari `mr_image_kiosk.name` (diisi admin pas Create/Update di ERP). Gak dipakai buat logic apapun di server, sekadar informasi buat frontend (misal caption/alt text).
- `banner_src` (2026-08-24, rename dari `image_src` -- penyeragaman istilah sama modul Master Image Mobile Customer di ERP) — **path lokal** (bukan path ERP) — file-nya udah didownload beneran ke POS pas sync pull (`SetupServices::getMasterImageKiosk()`, lihat `SYNC PULL.md`), bukan cuma nyimpen path mentah dari ERP. Frontend prefix sendiri pakai base URL Laravel, sama pola kayak `image_src`/`icon_src` item di endpoint Kiosk lain (field item itu SENDIRI gak ikut di-rename, cuma khusus banner campaign ini).
- `sequence` — urutan tampil (dipakai buat urutan slideshow), makin kecil makin duluan. Response udah urut `ASC` dari server, frontend gak perlu sort ulang.
- Cuma campaign yang `is_active = true` (di `mr_image`) yang ikut muncul.

## Sumber

```sql
SELECT mik.name, mik.banner_src, mik.sequence
FROM mr_image_kiosk mik
JOIN mr_image mi ON mi.id = mik.master_image_id
WHERE mi.is_active = 1
ORDER BY mik.sequence ASC
```

Data-nya sepenuhnya dari tabel sync lokal (`mr_image`/`mr_image_kiosk`, ditarik dari ERP `master_image`/`master_image_kiosk` — lihat `MASTER IMAGE.md` di sudocore2 buat skema aslinya) — **bukan** live lookup ke ERP (beda dari `KIOSK MEMBER CHECK.md`), karena gambar campaign gak perlu real-time banget, cukup ke-update tiap sync jalan.

Endpoint sejenis buat channel POS (`cd_pos`, customer display kasir) **udah ada** (2026-08-24) --
lihat [Customer Display Banner Image](../MASTER/CUSTOMER%20DISPLAY%20BANNER%20IMAGE.md)
(`MasterController::GetBannerImageCustomerDisplay()`, route
`GET /api/master/banner-image-customer-display`, baca `mr_image_customer_display`, mirror
endpoint ini persis). Dipakai `DisplayCustomerPage.vue` (`posv1-vue`) buat slideshow customer
display kasir.

**Update (2026-08-24, restrukturisasi channel)** -- `mr_image_list`+`mr_image_list_apply_for`
(1 tabel gambar + tabel tag channel terpisah) diganti 2 tabel eksplisit per-channel
(`mr_image_customer_display` buat `cd_pos`, `mr_image_kiosk` buat `cd_kiosk`) -- ngikutin
restrukturisasi `master_image` di ERP. Query di atas udah disesuaikan (gak perlu join
`apply_for` lagi, channel-nya implisit dari tabel `mr_image_kiosk` ini sendiri). **Sync pull**
buat tabel baru ini (`SetupServices::getMasterImageKiosk()`/`getMasterImageCustomerDisplay()`)
udah ditulis ulang & rantai ERP → APIANDORDER → POS udah nyambung lagi penuh.

**Update (2026-08-24, rename kolom)** -- `image_src` → `banner_src` di `mr_image_kiosk`
(migration `2026_08_24_100000_...`), penyeragaman istilah sama modul Master Image Mobile
Customer (ERP) yang emang dari awal pakai `banner_src`. Rename ikut di semua layer termasuk
field JSON response ini -- **breaking change** buat konsumen response lama (kalau ada), tapi
disengaja/diterima karena tujuannya 1 istilah konsisten lintas modul.

## Tervalidasi live (2026-08-24, struktur baru per-channel)

Insert test langsung via `psql` (`master_image`/`master_image_customer_display`/
`master_image_kiosk`, `flag_all_branches=true`, pakai 2 file gambar asli yang udah ada di
storage ERP) → sudocore2 dinyalain sementara → pull lewat APIANDORDER
(`/pos/sync/get_master_image_kiosk/14`, pakai token branch asli dari `mr_branch` lokal) →
sukses, data bener per-channel → trigger `GET /api/sync_pull/get_master_image_kiosk` di Laravel
→ sukses, `mr_image_kiosk` ke-upsert, gambar beneran ke-download ke `public/img/master-image/`
(`200` diakses langsung) → `GET /api/kiosk/banner-image` balikin **cuma** gambar kiosk (gak
ketuker sama gambar `cd_pos` yang dites bareng di endpoint `banner-image-customer-display`,
lihat catatan isolasi channel di atas). Data test + file gambar + server sudocore2 sementara
dibersihin/dimatiin abis verifikasi.

## Tervalidasi live (2026-08-12, struktur lama -- historis)

Insert campaign test di ERP (branch scoped, 1 gambar target `cd_kiosk`) pakai gambar asli yang ada di storage ERP → pull 3 endpoint sync (`get_master_image`, `get_master_image_list`, `get_master_image_list_apply_for` -- **nama endpoint ini udah gak ada lagi**, diganti `get_master_image_customer_display`/`get_master_image_kiosk` per restrukturisasi 2026-08-24) → gambar beneran ke-download ke `public/img/master-image/` → `GET /api/kiosk/banner-image` (nama lama sebelum rename: `/api/kiosk/images`, method controller `GetBannerImageKiosk()` sebelumnya `GetImages()`) balikin data yang bener, path lokalnya bisa diakses langsung (`200`). Data test + file gambar dibersihin abis verifikasi.

## Update (2026-08-12, rename)

Endpoint & method controller di-rename biar lebih jelas maksudnya: `/api/kiosk/images` → `/api/kiosk/banner-image`, `KioskController::GetImages()` → `KioskController::GetBannerImageKiosk()`. Logic/query gak berubah sama sekali, cuma penamaan. Tervalidasi: route lama udah gak terdaftar (`php artisan route:list` cuma nunjukin `banner-image`), route baru jalan normal.
