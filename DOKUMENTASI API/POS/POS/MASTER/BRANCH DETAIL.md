# Branch Detail

```
GET /api/master/branch
```

Detail branch yang terpasang di POS ini — singleton (`mr_branch` cuma 1 baris), plus setting minimal (`domain_receipt`/`whatsapp_template_text`).

Response:
```json
{
  "code": 0,
  "data": {
    "id": 51,
    "branch_code": "SBE",
    "branch_name": "SUDO BREW - EVENT",
    "brand_id": 3,
    "brand_code": "SUDO",
    "brand_name": "SUDO BREW",
    "brand_color": "#1E3A8A",
    "primary_color": "#2563EB",
    "secondary_color": "#F59E0B",
    "address": "Jl. Boulevard...",
    "phone": "081234567890",
    "printing_header": "...",
    "printing_footer": "...",
    "company_id": 1,
    "token": "...",
    "logo_header_src": "/img/branch/xxx.jpg",
    "image_footer_src": "/img/branch/yyy.jpg",
    "setting": {
      "domain_receipt": "https://struk.example.com",
      "whatsapp_template_text": "Terima kasih sudah berbelanja..."
    }
  }
}
```

- **Semua kolom `mr_branch`** dibalikin apa adanya (beda dari [`BRAND DETAIL.md`](BRAND%20DETAIL.md) yang scope-nya sempit cuma 4 kolom warna+nama).
- `logo_header_src`/`image_footer_src` — path lokal (sudah di-download, lihat [`../../SETUP/SETUP WIZARD.md`](../../SETUP/SETUP%20WIZARD.md)), **perlu di-prefix base URL API** sebelum dipakai `<img :src>` (sama pola yang dipakai `paymentPage.vue`: `API_BASE = API.defaults.baseURL.replace('/api','')`) — kalau tidak di-prefix, gambar gagal load (ditemukan sebagai bug nyata di `DisplayCustomerPage.vue` sebelumnya, sudah diperbaiki di situ).
- `setting` — `domain_receipt`/`whatsapp_template_text` dari `mr_branch_setting`, `""` (bukan `null`) kalau belum pernah sync setelah migration setting ini ada.
- Belum pernah install (`mr_branch` kosong) → `{ "code": 100, "message": "branchDetail gak muncul!" }`.

## Alur Implementasi

Dipanggil di 2 tempat: `globalstore.js::loadData()` (sekali saat Dashboard load, hasilnya disimpan di `branch` store — dipakai banyak komponen lain termasuk logo di `paymentPage.vue`) dan `TerminalPage.vue` (saat memilih terminal, sebelum masuk Dashboard).

## Sumber

- `app/Http/Controllers/MasterController.php` — `GetBranchDetail()`.
- Frontend: `src/stores/globalstore.js`, `src/modules/setup/TerminalPage.vue`.

## Status

Didokumentasikan 2026-10-12, berdasar pembacaan kode langsung (belum ada verifikasi ulang lewat HTTP request live di sesi ini).
