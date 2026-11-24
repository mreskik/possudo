# Save / Edit / View Payment

Pembayaran **manual** (CASH/kartu/EDC dkk, dicatat kasir sendiri) — beda dari [`PAYMENT GATEWAY.md`](PAYMENT%20GATEWAY.md) yang untuk QRIS/e-wallet via service pihak ketiga.

## Save Payment

```
POST /api/payment/save-payment
```

**Request** — field yang BENERAN dibaca `PaymentServices::SavePayment()` (controller meneruskan `$request` mentah, gak ada validasi struct/whitelist — field lain yang ikut dikirim diabaikan begitu saja, gak bikin error):
```json
{
  "order_number": "NOSBE...",
  "payment_detail": [
    {
      "payment_method_id": 1,
      "payment_amount": "123123.00",
      "change_amount": "1877.00",
      "info": null
    }
  ]
}
```

- `order_number` — wajib, order yang mau dibayar.
- **`member_id` TIDAK DIBACA LAGI** (2026-11-17, keputusan user) — kalau tetap dikirim di body, field itu diabaikan sepenuhnya. `tr_order.member_id` sekarang murni cuma dari [`SaveOrder()`](../ORDER/SAVE%20ORDER.md) (dipilih di halaman Order) — **tidak bisa lagi** diubah/dipilih lewat halaman Payment.
- `payment_detail[]` — array, **support split payment** (>1 baris metode berbeda):
  - `payment_method_id` — wajib.
  - `payment_amount` — wajib, nominal yang **dihitung membayar tagihan** untuk baris ini — **BERSIH, tidak termasuk kembalian**. FE (`paymentPage.vue`) mengirim `min(uang diterima, sisa tagihan)`, jadi untuk tagihan 123.123 dibayar tunai 125.000: `payment_amount` = `123123.00` dan `change_amount` = `1877.00`. Uang yang benar-benar diterima kasir (125.000) **tidak dikirim** ke backend; = `payment_amount + change_amount`.
  - `change_amount` — opsional, default `0` kalau tidak dikirim (`$item['change_amount'] ?? 0`) — cuma relevan untuk CASH. Kembalian disimpan **terpisah** dari `payment_amount` (disepakati 2026-08-31) supaya laporan/rekonsiliasi cash drawer bisa membedakan "uang diterima" dari "yang dihitung membayar tagihan".
  - `info` — opsional, default `null` kalau tidak dikirim (`$item['info'] ?? null`) — field bebas teks.
  - **Tidak ada `mdr_amount` di request** — dihitung server-side, lihat poin di bawah.

**Response sukses:** `{ "code": 0, "data": { "paymentNumber": "PS1SBE2026101214350001" } }`

**Response gagal** (pola BEDA dari kebanyakan endpoint lain — `$response->success` di-cek manual di controller, bukan exception):
- Order sudah ada `payment_number` → `{ "code": 100, "message": "order {order_number} has paid!" }`
- Order tidak ketemu → `{ "code": 100, "message": "order {order_number} not found!" }`
- **Guard payment type OTHER gak boleh digabung (2026-11-17, BARU)** — kalau `payment_detail[]` lebih dari 1 baris (split payment) **dan** salah satu baris itu `payment_method_id`-nya tergolong `mr_payment_method_type.id = 4` (OTHER), ditolak **sebelum** `payment_number` di-generate/transaksi dibuka (gak ada efek samping apa pun, order tetap `pending`) → `{ "code": 100, "message": "payment method type other harus 1 tidak boleh digabung" }`. OTHER sendirian (1 baris doang, gak di-split) tetap boleh. Berlaku juga kalau SEMUA baris-nya OTHER (misal 2 payment method yang beda id, dua-duanya type 4) — tetap ditolak, bukan cuma kombinasi OTHER+metode lain.
- **Guard payment type OTHER gak boleh dipakai di order yang ke-apply discount (2026-11-17, BARU)** — kalau salah satu baris `payment_detail[]` type-nya OTHER (`payment_method_type_id = 4`), DAN order ini punya minimal 1 item (`tr_order_detail.discount_amount > 0`) **atau** sub-item package (`tr_order_detail_package.discount_amount > 0`) yang ke-discount, ditolak **sebelum** `payment_number` di-generate/transaksi dibuka → `{ "code": 100, "message": "payment method type other cannot be applied to a discounted order" }` (pesan Bahasa Inggris, konsisten pesan error customer-facing lain). Dicek independen dari guard di atas — berlaku juga kalau OTHER-nya sendirian (1 baris, lolos guard pertama), tetap ditolak kalau order-nya ada discount.

- `payment_detail[]` — **support split payment** (array, bisa >1 baris metode berbeda).
- `change_amount` — kembalian tunai, cuma relevan untuk CASH, default `0` untuk metode lain. **Tidak** termasuk di `payment_amount` (lihat penjelasan di atas) — laporan Dayshift (Payment Method Summary, Cash In) menjumlah `payment_amount` apa adanya, jadi angkanya sudah bersih dari kembalian.
- `info` — field bebas teks (pengganti `card_number`/`bank_name`/`account_name`/`verification_code` lama, migration 2026-09-30) — diisi kasir apa adanya untuk metode non-cash.
- **`mdr_amount` DIHITUNG SERVER-SIDE** (`calculateMdrAmount()`, dari `mr_payment_method.mdr`), **bukan** dikirim dari client — sengaja, supaya tidak bisa dimanipulasi FE. Ini pengecualian dari pola umum "server tidak pernah hitung ulang" (lihat [`../ORDER/SAVE ORDER.md`](../ORDER/SAVE%20ORDER.md)) — murni informasi, tidak ada jurnal akuntansi yang terpengaruh nilai ini.
- Sukses → `status` order jadi `paid`, `payment_number`/`payment_at` ter-update (`member_id` TIDAK lagi ikut di-update sejak 2026-11-17, lihat poin di atas), **otomatis print struk** (`PrintServices::PrintPayment()`) — **kecuali** order dari Kiosk yang terminal-nya `flag_printer_frontend = true` (printing dilakukan di browser Kiosk, bukan server).
- **`status` jadi `other_cost` kalau dibayar pakai payment type OTHER (2026-11-17, BARU)** — MENGGANTIKAN `paid` sepenuhnya (bukan status tambahan): order yang baris `payment_detail`-nya `payment_method_type_id = 4` (OTHER, otomatis cuma 1 baris karena guard di atas) **tidak pernah** tercatat `status = paid` sama sekali, langsung `other_cost`. **Konsekuensi disengaja**: modul yang filter `status = 'paid'` **tidak** menghitung order jenis ini sebagai penjualan — `other_cost` adalah FOC/fasilitas (mis. untuk karyawan): barangnya nyata keluar dan ada nilainya, tapi **bukan pendapatan**. Update 2026-11-24: laporan Dayshift (**Report V3** dan 4 print) sekarang menampilkannya sebagai baris terpisah **Other Cost** (nilai `total_billing` apa adanya + jumlah order), **tidak** masuk Total Bills/Net Sales/Gross Sales/Category/Payment — lihat [`../DAYSHIFT/LIST AND DETAIL.md`](../DAYSHIFT/LIST%20AND%20DETAIL.md#sales-summary-scope--rumus-2026-11-24). Report V2 tetap tidak menampilkannya (deprecated). Jurnal End Day ERP memprosesnya sendiri (hanya tahap COGS, tanpa Revenue).
- **Khusus order dari Kiosk**: payment sukses juga trigger print kitchen (ditunda sejak `SaveOrder()`, baru sekarang karena Kiosk perlu kepastian bayar dulu) dan insert ke `tr_kiosk_order_notif` (trigger toast notifikasi order baru di POS, lihat [`../ORDER/ORDER NOTIF.md`](../ORDER/ORDER%20NOTIF.md)) — order Kiosk yang dibuat tapi tidak jadi dibayar **tidak pernah** muncul sebagai notifikasi.
- **`print_checker_on === 'save_payment'` (2026-11-04, BARU)** — SELAIN Kiosk, print kitchen (`PrintTableChecker2`/`PrintMainChecker2`/`PrintPriparationStation`) di sini juga jalan untuk order **non-Kiosk** kalau `mr_table_section.print_checker_on` order itu di-set `'save_payment'` (bukan default `'save_order'`) — 2 kondisi independen, digabung OR (`order_source === 'kiosk' || print_checker_on === 'save_payment'`). Kasus ini TIDAK nge-print di `SaveOrder()` sama sekali, satu-satunya titik cetak checker-nya di sini. Detail lengkap + tabel kombinasi lihat [`../ORDER/SAVE ORDER.md`](../ORDER/SAVE%20ORDER.md#print-checker--kapan-di-trigger-2026-11-04).

## Edit Payment

```
POST /api/payment/edit-payment
```

**Request** — field yang BENERAN dibaca `PaymentServices::EditPayment()`:
```json
{
  "order_number": "NOSBE...",
  "total_payment": "123123.00",
  "payment_detail": [
    {
      "payment_method_id": 1,
      "payment_amount": "123123.00",
      "change_amount": "1877.00",
      "info": null
    }
  ]
}
```

- `order_number` — wajib.
- `total_payment` — wajib, **dicek `!=` persis** (bukan toleransi pembulatan) terhadap `tr_order.total_billing` tersimpan.
- `payment_detail[]` — sama struktur Save Payment di atas (`payment_method_id`/`payment_amount` wajib, `change_amount`/`info` opsional, `mdr_amount` dihitung ulang server-side).
- **`member_id` TIDAK dibaca sama sekali di sini** — sama kayak Save Payment (2026-11-17), kalau tetap dikirim di body, field itu diabaikan, `tr_order.member_id` existing TIDAK ikut berubah lewat endpoint ini.

**Response:** `{ "code": 0, "message": "update payment success!" }`

- **Guard order `status = 'other_cost'` TIDAK BISA diedit sama sekali (2026-11-17, BARU)** — dicek **PALING AWAL** (sebelum guard `payment_number`/`total_billing` di bawah) → `{ "code": 100, "message": "order with other cost status cannot be edited" }`. Order yang statusnya `other_cost` (dibayar pakai payment type OTHER, lihat [Save Payment](#save-payment)) gak bisa diubah payment-nya lewat endpoint ini sama sekali, titik.
- Order belum pernah dibayar → `"order number {order_number} has never been paid!"`.
- `total_payment` **wajib persis sama** dengan `total_billing` tersimpan (`!=`, bukan toleransi pembulatan) → tidak cocok → `"Payment amount does not match the order!"`.
- **Guard payment type OTHER — SAMA PERSIS 2 guard Save Payment di atas (2026-11-17, BARU)**, dipasang juga di sini biar Edit Payment gak bisa dipakai buat "ngelewatin" guard yang ada di Save Payment:
  - Lebih dari 1 baris `payment_detail[]` dan ada yang type OTHER → `"payment method type other harus 1 tidak boleh digabung"`.
  - Ada baris type OTHER dan order ini punya item/sub-item package yang `discount_amount > 0` → `"payment method type other cannot be applied to a discounted order"`.
  - Dicek **sebelum** `DB::beginTransaction()`/baris `tr_order_payment` lama dihapus — kalau ditolak, payment lama TETAP UTUH (gak ada efek samping, bukan "kehapus lalu gagal insert ulang").
- **Replace total**: semua baris `tr_order_payment` lama untuk order ini **dihapus**, lalu insert ulang dari `payment_detail[]` baru — bukan update per-baris.

## View Payment

```
GET /api/payment/view-payment/{order_number}
```

Response (data real dari DB dev, order `NO112320261027165250`):
```json
{
  "code": 0,
  "data": {
    "order_number": "NO112320261027165250",
    "payment_number": "PS112320261102172127",
    "total_payment": "65000.00",
    "item_voucher": 0,
    "total_amount_voucher": 0,
    "total_change": 0,
    "payment_detail": [
      {
        "ulid": "01M6DWEE5YHGN1NNGCH9FG1VSM",
        "payment_number": "PS112320261102172127",
        "payment_method_id": 2,
        "payment_gateway_order_id": null,
        "payment_amount": "65000.00",
        "change_amount": "0.00",
        "voucher_code": null,
        "created_at": null,
        "updated_at": "2026-11-02T10:22:00.000000Z",
        "created_by": null,
        "updated_by": null,
        "sync_at": "2026-11-02 17:22:00",
        "info": null,
        "deduct_member_id": null,
        "mdr_amount": "0.00"
      }
    ],
    "member_id": null,
    "member_name": null
  }
}
```

- Order belum pernah dibayar → `"order number {order_number} has never been paid!"`.
- `item_voucher`/`total_amount_voucher` — **selalu `0`**, field placeholder yang tidak pernah diisi logic apa pun saat ini (fitur voucher belum diimplementasikan).
- `total_change` — jumlah `change_amount` semua baris payment (sama pola seperti [`../SALES/VIEW SALES.md`](../SALES/VIEW%20SALES.md)).
- `payment_detail[]` — `TrOrderPaymentModel::get()` apa adanya, SELURUH kolom `tr_order_payment` ikut (tidak ada whitelist): `ulid`, `payment_number`, `payment_method_id`, `payment_gateway_order_id` (nullable, cuma keisi buat payment gateway/QRIS — lihat [`PAYMENT GATEWAY.md`](PAYMENT%20GATEWAY.md)), `payment_amount`, `change_amount`, `voucher_code` (nullable — **TIDAK diisi** `SavePayment()`/`EditPayment()` manual kasir di dokumen ini, cuma keisi untuk order yang masuk lewat `MobileOrderPullServices.php`, jalur order dari app customer/sudomobile), `created_at`/`updated_at`/`created_by`/`updated_by`/`sync_at`, `info` (nullable, field bebas teks), `deduct_member_id` (nullable — snapshot id member yang saldonya kepotong buat metode "Bayar dengan Saldo Member"; sama kayak `voucher_code`, **TIDAK diisi** oleh `SavePayment()`/`EditPayment()` di dokumen ini, cuma dari `MobileOrderPullServices.php`), `mdr_amount`.

## Alur Implementasi

1. Kasir di halaman Payment (`paymentPage.vue`) — input metode+nominal per baris, `SavePayment()` dipanggil saat submit.
2. Kalau perlu ubah payment **setelah** sudah tersimpan (koreksi split payment, dll) — pakai `EditPayment()`, bukan `SavePayment()` lagi (yang akan ditolak karena order sudah punya `payment_number`).
3. `ViewPayment()` dipakai untuk menampilkan ulang detail pembayaran (misal reprint, atau buka kembali halaman payment untuk order yang sudah lunas).

## Sumber

- `app/Http/Controllers/PaymentController.php` — `savePayment()`, `editPayment()`, `viewPayment()`.
- `app/Services/PaymentServices.php` — `SavePayment()`, `EditPayment()`, `ViewPayment()`, `calculateMdrAmount()` (private).
- Frontend: `src/services/PaymentServices.ts`.

## Status

Didokumentasikan 2026-10-12, berdasar pembacaan kode langsung (belum ada verifikasi ulang lewat HTTP request live di sesi ini).

**Contoh JSON View Payment dilengkapi (2026-11-17)**: `PaymentServices::ViewPayment()` dipanggil langsung (tinker) untuk order real di DB dev (`NO112320261027165250`) — response disalin apa adanya ke dokumentasi (sebelumnya placeholder `"...": "..."`). Sekalian dicek sumber `voucher_code`/`deduct_member_id` (keduanya nullable di `tr_order_payment`) — ternyata **tidak diisi** oleh `SavePayment()`/`EditPayment()` manual kasir, cuma oleh `MobileOrderPullServices.php` (jalur order dari sudomobile/app customer, di luar scope dokumen ini).

**Request Save/Edit Payment dilengkapi (2026-11-17)**: dibaca langsung dari kode `PaymentServices::SavePayment()`/`EditPayment()` — tiap field dikonfirmasi satu per satu field apa yang beneran dipakai vs diabaikan. Temuan: `PaymentController::savePayment()`/`editPayment()` meneruskan `$request` mentah ke service (gak ada validasi struct/whitelist), jadi field tambahan yang dikirim client gak bikin error, cuma diam-diam diabaikan kalau servicenya gak baca.

**`member_id` DIHAPUS dari `SavePayment()` (2026-11-17, keputusan user, bukan cuma dokumentasi)**: sebelumnya `SavePayment()` ikut nulis `tr_order.member_id` dari body request (dokumentasi lama "sama seperti Save Payment" dipakai juga buat Edit Payment, padahal `EditPayment()` TIDAK PERNAH baca `member_id` sama sekali, cuma Save Payment yang baca). Ditemukan inkonsistensi ini saat merapikan dokumentasi, lalu user minta `member_id` di-takeout sepenuhnya dari `SavePayment()` juga — sekarang KEDUA endpoint konsisten TIDAK baca `member_id`. `tr_order.member_id` sekarang murni cuma ditentukan sekali di [`SaveOrder()`](../ORDER/SAVE%20ORDER.md), gak bisa lagi diubah di titik pembayaran manapun.

**Guard payment type OTHER (split) ditambahkan, tervalidasi live (2026-11-17)**: `PaymentServices::SavePayment()` ditambah pengecekan di awal (sebelum `payment_number` di-generate/`DB::beginTransaction()`) — kalau `count($payment_detail) > 1` DAN ada 1 baris dengan `mr_payment_method.payment_method_type_id === 4` (OTHER), ditolak `"payment method type other harus 1 tidak boleh digabung"`. Dites via tinker dengan payment method test (`id=9001`, `payment_method_type_id=4`) dan order test — split 2 baris (CASH + OTHER) → ditolak dengan pesan yang benar, order tetap `pending`/`payment_number` tetap `NULL` (tidak ada efek samping). OTHER sendirian (1 baris) → lolos guard, `SavePayment()` sukses seperti biasa. `php -l` bersih. Data test (order + payment method) dibersihkan setelah verifikasi.

**Guard payment type OTHER (discount) ditambahkan, tervalidasi live (2026-11-17)**: ditambahkan TEPAT SETELAH guard di atas, independen — kalau salah satu baris `payment_detail` type OTHER, dicek `TrOrderDetailModel::where('order_number', ...)->where('discount_amount', '>', 0)->exists()` **dan** `TrOrderDetailPackageModel` (via `tr_order_detail_ulid` milik order itu) dengan kondisi sama — kalau salah satunya `true`, ditolak `"payment method type other cannot be applied to a discounted order"` (pesan Bahasa Inggris). Dites 3 skenario via tinker: (1) OTHER + item utama discount → ditolak; (2) OTHER tanpa discount sama sekali (item+package) → lolos, `SavePayment()` sukses; (3) OTHER + discount cuma di level `tr_order_detail_package` (item utama TIDAK discount) → tetap ditolak, membuktikan cek package-nya beneran jalan, bukan cuma item utama. `php -l` bersih. Data test dibersihkan setelah verifikasi.

**Kedua guard payment type OTHER ditambahkan JUGA ke `EditPayment()`, tervalidasi live (2026-11-17)**: pola sama persis `SavePayment()`, dipasang sebelum `DB::beginTransaction()`/`TrOrderPaymentModel::delete()` (pakai `throw new \Exception(...)`, bukan `$response->message`, konsisten gaya existing `EditPayment()`). Dites via tinker dengan order test yang sudah `status: paid` + 1 baris payment existing: (1) kirim 2 baris payment (CASH+OTHER) → ditolak `"...harus 1 tidak boleh digabung"`, baris payment lama TETAP ADA (`count: 1`, gak ke-delete — membuktikan guard mencegah transaksi dibuka sama sekali, bukan "kehapus dulu baru gagal"); (2) OTHER sendirian di order yang masih ada discount → ditolak `"...cannot be applied to a discounted order"`, payment lama tetap utuh; (3) discount dihapus, OTHER sendirian → lolos kedua guard, `EditPayment()` sukses (`"update payment success!"`). `php -l` bersih. Data test dibersihkan setelah verifikasi.

**Tervalidasi live**: baris `'member_id' => $datajson->member_id` dihapus dari `TrOrderModel::update()` di `SavePayment()`. Dites via tinker — order test dibuat dengan `member_id = 1`, `SavePayment()` dipanggil dengan body `member_id: null` → sukses (`status` jadi `paid`), tapi `tr_order.member_id` TETAP `1` (tidak ter-reset ke `null`), membuktikan field itu beneran diabaikan sepenuhnya. `php -l` bersih. Data test dibersihkan setelah verifikasi.

**Status order `other_cost` ditambahkan, tervalidasi live (2026-11-17)**: `SavePayment()` nentuin `status` lewat `$isOtherPayment ? 'other_cost' : 'paid'` — flag `$isOtherPayment` di-set `true` di loop guard discount yang sudah ada (reuse lookup `MasterPaymentMethodModel`, gak nambah query baru), **perbandingannya pakai `payment_method_type_id` (ID numerik, bukan nama/`name` "OTHER")** — sengaja, biar aman kalau admin ubah nama type-nya di `mr_payment_method_type` kapan pun, logic ini gak ikut rusak. Dites via tinker: order test dibayar pakai payment method test (`id=9001`, `payment_method_type_id=4`) → `status` jadi `other_cost`. Regression check: order test lain dibayar pakai CASH (`payment_method_type_id=1`) → `status` tetap `paid` seperti semula, gak ada yang berubah buat payment method biasa. Migration komentar (`2026_04_23_100709_transaction_data.php`) diupdate nambahin `other_cost` ke daftar status yang dicatat (kolomnya `string` biasa, BUKAN `enum`/`CHECK` constraint — gak perlu migration schema buat nilai status baru). **Scope sengaja CUMA status order** — modul lain yang filter `status = 'paid'` (laporan Sales, EndDay, dll) TIDAK disentuh/disesuaikan di sesi ini (keputusan user, menyusul terpisah kalau perlu). `php -l` bersih. Data test dibersihkan setelah verifikasi.

**Guard `other_cost` tidak bisa diedit ditambahkan, tervalidasi live (2026-11-17)**: `EditPayment()` ditambah pengecekan `$dataorder->status === 'other_cost'` **paling awal** (sebelum guard `payment_number`/`total_billing`) → `throw new \Exception("order with other cost status cannot be edited")`. Dites via tinker: order test dengan `status: other_cost` + 1 baris payment existing → `EditPayment()` ditolak dengan pesan yang benar, payment lama TETAP UTUH (`count: 1`, gak ke-delete). Regression check: order test yang sama diubah `status` ke `paid` → `EditPayment()` sukses normal (`"update payment success!"`), membuktikan guard ini cuma ngeblok `other_cost` doang, gak ngaruh ke order `paid` biasa. `php -l` bersih. Data test dibersihkan setelah verifikasi.

Diupdate 2026-11-24: contoh request Save Payment/Edit Payment diperbaiki (`payment_amount` 125000 → 123123; contoh lama menganggap `payment_amount` = uang yang diserahkan, padahal FE mengirim nilai bersih dan kembalian terpisah di `change_amount`, sesuai komentar `paymentPage.vue` 2026-08-31) dan konsekuensi status `other_cost` diperbarui. Murni koreksi dokumentasi, tidak ada perubahan kode pembayaran.
