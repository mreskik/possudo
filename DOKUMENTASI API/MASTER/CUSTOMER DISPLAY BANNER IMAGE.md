# Customer Display Banner Image

```
GET /api/master/banner-image-customer-display
```

Daftar gambar buat customer display kasir (banner/slideshow) — filter channel `cd_pos` udah fix di query (bukan parameter client, gak bisa diminta channel lain lewat endpoint ini). Mirror persis [Kiosk Banner Image](../KIOSK/KIOSK%20BANNER%20IMAGE.md), bedanya cuma sumber tabel (`mr_image_customer_display` vs `mr_image_kiosk`) dan gak di-prefix `/kiosk`.

Response:

```json
{
  "code": 0,
  "data": [
    { "name": "Banner Kasir 1", "banner_src": "/img/master-image/019f648a-f12e-7252-a76f-aa8fc55679bf.jpg", "sequence": 1 }
  ]
}
```

Kosong (`[]`) kalau belum ada campaign gambar yang aktif & target `cd_pos` buat branch ini.

- `name` (2026-08-24) — nama gambar per-baris, apa adanya dari `mr_image_customer_display.name` (diisi admin pas Create/Update di ERP). Gak dipakai buat logic apapun di server, dipakai `DisplayCustomerPage.vue` sebagai `alt` text di `Galleria`.
- `banner_src` (2026-08-24, rename dari `image_src`) — **path lokal** (bukan path ERP) — file-nya udah didownload beneran ke POS pas sync pull (`SetupServices::getMasterImageCustomerDisplay()`, lihat `SYNC PULL.md`), bukan cuma nyimpen path mentah dari ERP.
- `sequence` — urutan tampil (dipakai buat urutan slideshow), makin kecil makin duluan. Response udah urut `ASC` dari server, frontend gak perlu sort ulang.
- Cuma campaign yang `is_active = true` (di `mr_image`) yang ikut muncul.

## Sumber

```sql
SELECT micd.name, micd.banner_src, micd.sequence
FROM mr_image_customer_display micd
JOIN mr_image mi ON mi.id = micd.master_image_id
WHERE mi.is_active = 1
ORDER BY micd.sequence ASC
```

Data-nya sepenuhnya dari tabel sync lokal (`mr_image`/`mr_image_customer_display`, ditarik dari ERP `master_image`/`master_image_customer_display` — lihat `MASTER IMAGE.md` di sudocore2 buat skema aslinya) — **bukan** live lookup ke ERP, sama pola kayak `KIOSK BANNER IMAGE.md`.

## Konsumen

Dipakai `DisplayCustomerPage.vue` (`posv1-vue`, `src/modules/display_customer/`) — di-fetch sekali pas `onMounted`, hasilnya dipetain ke format `itemImageSrc`/`thumbnailImageSrc` buat komponen `Galleria` PrimeVue. Sebelum 2026-08-24 komponen ini hardcode 2 file gambar statis (`SLIDE-dedication.jpg`/`SLIDE-SUDO-1024x550.jpg`), sekarang narik dari sini.

## Tervalidasi live (2026-08-24)

Insert test langsung via `psql` (`master_image`/`master_image_customer_display`, `flag_all_branches=true`) → sync pull (`GET /api/sync_pull/get_master_image_customer_display`) → sukses, gambar ke-download ke `public/img/master-image/` (`200` diakses langsung) → `GET /api/master/banner-image-customer-display` balikin **cuma** gambar `cd_pos` (gak ketuker sama gambar `cd_kiosk` yang dites bareng, lihat `KIOSK BANNER IMAGE.md`). Data test dibersihin abis verifikasi. Belum dites lewat browser beneran (render `DisplayCustomerPage.vue` sungguhan) — baru dites di level API/data.
