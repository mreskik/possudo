# Branch Detail

```
GET /api/master/branch
```

Detail branch yang terpasang di POS ini — singleton (`mr_branch` cuma 1 baris), plus setting (`mr_branch_setting`, **1:1 sama skema ERP `master_branch_setting` sejak 2026-11-03** — sebelumnya cuma 2 kolom `domain_receipt`/`whatsapp_template_text`, sekarang semua 19 kolom tambahan ikut ditarik).

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
      "whatsapp_template_text": "Terima kasih sudah berbelanja...",
      "flag_time_out_user": false,
      "time_out_second": 0,
      "flag_rounding": true,
      "rounding_mode": "up",
      "rounding_nearst_value": "100.00",
      "flag_email_report_endday": false,
      "email_report_endday": "",
      "flag_notification_end": false,
      "notification_end": "",
      "flag_queue": true,
      "queue_reset_after_each": 1,
      "queue_start_from": 1,
      "flag_guest_comment": false,
      "mandatory_setting": {
        "flag_bill_name": true,
        "flag_note_payment": true
      }
    }
  }
}
```

- **Semua kolom `mr_branch`** dibalikin apa adanya (beda dari [`BRAND DETAIL.md`](BRAND%20DETAIL.md) yang scope-nya sempit cuma 4 kolom warna+nama).
- `logo_header_src`/`image_footer_src` — path lokal (sudah di-download, lihat [`../../SETUP/SETUP WIZARD.md`](../../SETUP/SETUP%20WIZARD.md)), **perlu di-prefix base URL API** sebelum dipakai `<img :src>` (sama pola yang dipakai `paymentPage.vue`: `API_BASE = API.defaults.baseURL.replace('/api','')`) — kalau tidak di-prefix, gambar gagal load (ditemukan sebagai bug nyata di `DisplayCustomerPage.vue` sebelumnya, sudah diperbaiki di situ).
- `setting` — dari `mr_branch_setting`, **1:1 sama `master_branch_setting` ERP** (sudocore2, `backend/models/masterBranch.go`), kecuali `id` surrogate ERP yang gak perlu di sini (`branch_id` tetap PK, pola yang sudah berjalan). Fallback kalau belum pernah sync: string → `""`, bool → `false`, angka → `0` (bukan `null`). Kalau cuma butuh field ini doang (tanpa seluruh field Branch Detail lain), ada endpoint khusus: [`BRANCH CONFIGURATION.md`](BRANCH%20CONFIGURATION.md) (`GET /api/master/branch-configuration`, BARU 2026-11-03).
  - `domain_receipt`/`whatsapp_template_text` — sudah ada sejak 2026-09-25.
  - **Kolom baru (2026-11-03)**: `flag_time_out_user`/`time_out_second`, `flag_rounding`/`rounding_mode`/`rounding_nearst_value`, `flag_email_report_endday`/`email_report_endday`, `flag_notification_end`/`notification_end`, `flag_queue`/`queue_reset_after_each`/`queue_start_from`, `flag_guest_comment`, `flag_bill_name`, `flag_note_payment` — semuanya setting operasional branch, penggunaan di FE POS belum digarap (CRUD+sync doang buat sekarang, sama status seperti 2 kolom lama sebelum perubahan ini).
  - **`mandatory_setting` (2026-11-03, perubahan susulan)** — `flag_bill_name`/`flag_note_payment` DIPINDAH dari flat list jadi nested object `mandatory_setting: { flag_bill_name, flag_note_payment }`. Alasan dipisah: 2 flag ini maknanya "WAJIB diisi nama/catatan pembayaran sebelum bisa lanjut bayar" (validasi input kasir), beda semangat dari flag_* lain yang sifatnya "nyala/mati fitur" — pengelompokan ini murni biar lebih jelas dibaca, bukan perubahan sumber data (tetap kolom `flag_bill_name`/`flag_note_payment` yang sama di `mr_branch_setting`).
  - **`flag_online_service_mobile_customer`/`flag_barber_service` TIDAK di-expose (2026-11-03, perubahan susulan)** — 2 kolom ini ADA di `mr_branch_setting` (tetap ke-sync normal dari ERP lewat `SetupServices::getDatabranch()`, TIDAK diubah), tapi SENGAJA tidak dikembalikan di response `setting` ini — scope-nya modul lain (sudomobile/sudobarber), bukan POS.
- Belum pernah install (`mr_branch` kosong) → `{ "code": 100, "message": "branchDetail gak muncul!" }`.

## Alur Implementasi

Dipanggil di 2 tempat: `globalstore.js::loadData()` (sekali saat Dashboard load, hasilnya disimpan di `branch` store — dipakai banyak komponen lain termasuk logo di `paymentPage.vue`) dan `TerminalPage.vue` (saat memilih terminal, sebelum masuk Dashboard).

## Sumber

- `app/Http/Controllers/MasterController.php` — `GetBranchDetail()`.
- Frontend: `src/stores/globalstore.js`, `src/modules/setup/TerminalPage.vue`.

## Status

Didokumentasikan 2026-10-12, berdasar pembacaan kode langsung (belum ada verifikasi ulang lewat HTTP request live di sesi ini).

**Update 2026-11-03 (17 kolom setting baru) — tervalidasi live**: migration `database/migrations/2026_11_03_000000_add_columns_to_mr_branch_setting.php` dijalankan di DB test lokal → `mr_branch_setting` jadi 20 kolom. Seed manual beberapa kolom (`flag_time_out_user`, `time_out_second`, `flag_rounding`, `rounding_mode`, `rounding_nearst_value`, `flag_queue`, `queue_reset_after_each`, `queue_start_from`, `flag_guest_comment`) lalu hit `GET /api/master/branch` lewat server lokal (`php artisan serve`) → response `setting` berisi semua 19 field dengan value yang benar sesuai yang di-seed, sisanya fallback default. Data test dikembalikan ke semula setelah verifikasi.

**Update 2026-11-03 (nested `mandatory_setting`) — tervalidasi live**: `flag_bill_name`/`flag_note_payment` dipindah dari flat list jadi nested object `mandatory_setting`. Seed manual kedua kolom jadi `true` → hit `GET /api/master/branch` → response `setting.mandatory_setting` berisi `{"flag_bill_name":1,"flag_note_payment":1}` (bukan lagi flat), field lain di `setting` tidak terpengaruh. Data test dikembalikan ke semula setelah verifikasi.

**Update 2026-11-03 (hapus `flag_online_service_mobile_customer`/`flag_barber_service` dari response) — tervalidasi live**: kedua field dihapus dari response `setting` (kode `MasterController::GetBranchDetail()`) — DB/sync TIDAK diubah, cuma gak di-expose di response ini. Hit `GET /api/master/branch` setelah perubahan → response `setting` dicek TIDAK mengandung 2 key itu sama sekali, field lain tetap normal.
