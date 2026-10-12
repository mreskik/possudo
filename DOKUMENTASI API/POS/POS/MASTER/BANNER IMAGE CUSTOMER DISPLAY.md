# Banner Image Customer Display

```
GET /api/master/banner-image-customer-display
```

Daftar gambar banner/slideshow untuk layar Customer Display kasir (channel `cd_pos`, fix di query — bukan parameter, tidak bisa minta channel lain lewat endpoint ini). Mirror persis Kiosk Banner Image (`GET /api/kiosk/banner-image`), beda sumber tabel (`mr_image_customer_display` vs `mr_image_kiosk`).

Response:
```json
{
  "code": 0,
  "data": [
    { "name": "Banner 1", "banner_src": "/img/master-image/xxx.jpg", "sequence": 1 }
  ]
}
```

- `banner_src` — **path relatif** ke server backend (bukan URL lengkap), file-nya sudah didownload saat sync (lihat [`../../SYNC/SYNC PULL.md`](../../SYNC/SYNC%20PULL.md)). **WAJIB di-prefix base URL API sebelum dipakai `<img :src>`** — ini pernah jadi bug nyata (`DisplayCustomerPage.vue` memakai path mentah tanpa prefix, gambar gagal load), sudah diperbaiki (pola: `API_BASE = API.defaults.baseURL.replace('/api', '')`, lalu `${API_BASE}${banner_src}`).
- `sequence` — urutan tampil slideshow, response sudah terurut `ASC` dari server.
- Cuma campaign `is_active = true` yang ikut muncul. Tidak ada campaign aktif → `data: []`.

## Alur Implementasi

Dipanggil sekali saat `DisplayCustomerPage.vue` dimuat (`onMounted`) — hasil dipetakan ke format `itemImageSrc`/`thumbnailImageSrc` untuk komponen `Galleria` (PrimeVue), auto-play slideshow dengan interval 10 detik.

## Sumber

- `app/Http/Controllers/MasterController.php` — `GetBannerImageCustomerDisplay()`.
- Frontend: `src/modules/display_customer/DisplayCustomerPage.vue`.

## Status

Didokumentasikan 2026-10-12, berdasar pembacaan kode langsung. Bug path relatif sudah diverifikasi & diperbaiki di sesi sebelumnya (build frontend sukses setelahnya).
