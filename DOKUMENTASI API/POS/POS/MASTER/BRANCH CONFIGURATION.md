# Branch Configuration

```
GET /api/master/branch-configuration
```

**BARU (2026-11-03).** Endpoint khusus buat baca `mr_branch_setting` **sendirian** — sebelumnya satu-satunya jalur baca setting ini cuma lewat [`BRANCH DETAIL.md`](BRANCH%20DETAIL.md) (nempel di field `setting`, bareng seluruh field Branch Detail lain yang gak relevan kalau cuma mau refresh setting doang, mis. `whatsapp_template_text`/`domain_receipt` abis diubah di ERP). Berguna buat FE yang cuma butuh data setting-nya, tanpa narik ulang seluruh Branch Detail.

Baca data **LOKAL** (`mr_branch_setting`, hasil sync terakhir) — **BUKAN** hit APIANDORDER/ERP langsung. Kalau mau data terbaru dari ERP, sync dulu (endpoint `GET /api/sync-group/branch-setting`, `SyncGroupController::syncBranchSetting()` — MEMICU SYNC ULANG, beda total dari endpoint ini), baru panggil endpoint ini buat baca hasilnya.

**Penamaan path/method/dokumen sengaja "Configuration" (bukan "Setting")** — biar gak bentrok sama `GET /api/sync-group/branch-setting` yang sudah ada duluan (nama mirip tapi fungsinya beda total: itu trigger sync, ini baca data lokal).

Response:
```json
{
  "code": 0,
  "data": {
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
```

- Kolom `mr_branch_setting` (selain `branch_id`) dibalikin, **kecuali `flag_online_service_mobile_customer`/`flag_barber_service`** (lihat poin khusus di bawah) — **1:1 sama shape** field `setting` di [`BRANCH DETAIL.md`](BRANCH%20DETAIL.md), sengaja diduplikasi langsung (bukan diekstrak jadi helper bersama, masih kecil) — lihat dokumen itu juga buat detail arti tiap kolom & asal-usul skema (**1:1 sama `master_branch_setting` ERP**, sudocore2 `backend/models/masterBranch.go`).
- `mandatory_setting` (2026-11-03) — `flag_bill_name`/`flag_note_payment` di-nest terpisah dari flat list, karena maknanya "WAJIB diisi nama/catatan pembayaran sebelum bisa lanjut bayar" (validasi input kasir), beda semangat dari flag_* lain yang sifatnya "nyala/mati fitur". Sumber datanya tetap kolom `flag_bill_name`/`flag_note_payment` yang sama di `mr_branch_setting`, murni pengelompokan response.
- `flag_online_service_mobile_customer`/`flag_barber_service` TIDAK di-expose (2026-11-03, perubahan susulan) — 2 kolom ini ADA di `mr_branch_setting` (tetap ke-sync normal dari ERP, TIDAK diubah), tapi SENGAJA tidak dikembalikan di sini — scope-nya modul lain (sudomobile/sudobarber), bukan POS.
- Fallback kalau belum pernah sync: string → `""`, bool → `false`, angka → `0` (bukan `null`).
- Belum pernah install (`mr_branch` kosong, branch belum ke-setup) → `{ "code": 100, "message": "branchDetail gak muncul!" }` (pesan sama persis dengan `BRANCH DETAIL.md`, karena sumber baris pertama-nya juga `mr_branch`, dipakai buat cari `branch_id`).

## Sumber

- `app/Http/Controllers/MasterController.php` — `GetBranchConfiguration()`.
- Route: `routes/api.php`, grup `master`, didaftarkan setelah `branch`.

## Status

**Tervalidasi live (2026-11-03)**: hit endpoint lewat `php artisan serve` (DB test lokal) → response awal semua field default (`""`/`false`/`0`, belum pernah di-set). Seed manual `domain_receipt`/`whatsapp_template_text`/`flag_barber_service` ke `mr_branch_setting` → hit ulang endpoint → ketiga field ter-update benar sesuai seed, field lain tetap default — membuktikan endpoint beneran baca data lokal real, bukan hardcode. Path lama (seandainya dipakai nama "branch-setting") dicek TIDAK ke-register sebagai route API (fallback ke SPA index.html, bukan JSON) — konfirmasi rename ke "branch-configuration" berhasil menghindari bentrok nama sama sekali. Data test dikembalikan ke semula setelah verifikasi.

**Update 2026-11-03 (nested `mandatory_setting`) — tervalidasi live**: seed manual `flag_bill_name`/`flag_note_payment` jadi `true` → hit endpoint → response `data.mandatory_setting` berisi `{"flag_bill_name":1,"flag_note_payment":1}` (nested, bukan flat lagi), field lain tidak terpengaruh. Data test dikembalikan ke semula setelah verifikasi.

**Update 2026-11-03 (hapus `flag_online_service_mobile_customer`/`flag_barber_service` dari response) — tervalidasi live**: kedua field dihapus dari response (kode `MasterController::GetBranchConfiguration()`) — DB/sync TIDAK diubah, cuma gak di-expose di response ini. Hit endpoint setelah perubahan → response `data` dicek TIDAK mengandung 2 key itu sama sekali, field lain tetap normal.
