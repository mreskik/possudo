# Kiosk Member Topup

Top-up saldo member (dompet digital, beda dari poin) dari Kiosk — real-time, langsung nembak ke ERP pusat lewat APIANDORDER (`backend/modules/apipos/membertopup`), **bukan** lewat jalur sync pull/push biasa. Alasannya: saldo itu duit beneran, harus akurat detik itu juga (nyegah double-spend), gak boleh nunggu batch sync kayak master data lain.

Logic di `App\Services\MemberBalanceServices` (Laravel) → APIANDORDER `POST /pos/member-topup/:branch_id` (dan `GET .../check-status/:reference_number`) → tabel `member_topup_online` + `member_balance_ledger` di database pusat (`db_sudocore_dev`).

## Request Topup

```
POST /api/kiosk/member/topup
Content-Type: application/json
```

Body:
```json
{
  "phone_number": "081234567890",
  "amount": 100000,
  "payment_method_id": 3,
  "terminal_id": 4
}
```

- **`phone_number`** — wajib. **BUKAN** `member_id` — sama alasan kayak `SaveOrder()` (`resolveMemberIdByPhone()`): `member_id` itu identitas internal, gak boleh dipercaya langsung dari client (resiko nambah saldo ke akun member lain kalau di-tamper). Server (APIANDORDER) yang resolve `phone_number → member_id` sendiri, query langsung ke `master_member` — Laravel/Kiosk gak pernah pegang `member_id` sama sekali di alur ini.
- **`amount`** — wajib, nominal top-up (> 0).
- **`payment_method_id`** — **wajib**, dan wajib payment method yang punya `payment_gateway_code` keisi — validasinya **SAMA PERSIS** `RequestPayment()` (`App\Services\MemberBalanceServices::TopupBalance()`, mirror `PaymentGatewayServices::RequestPayment()`): kalau `payment_method_id` gak dikirim, atau ditemuin tapi `payment_gateway_code`-nya kosong, langsung ditolak — **tidak ada fallback diam-diam ke tunai**.
- **`terminal_id`** — opsional (ditambahkan 2026-08-28), ID terminal Kiosk yang dipakai top-up (`mr_terminal.id`, device udah tau ID-nya sendiri, sama pola `SaveOrder()`). Diterusin apa adanya ke APIANDORDER, disimpan ke `member_topup_online.terminal_id` **dan** `member_balance_ledger.terminal_id` (sejajar `branch_id` yang udah ada di situ) buat traceability "top-up ini dari terminal mana" — di-echo balik lagi di response Check Status, dipakai buat trigger print struk (lihat section Print Struk di bawah).

Ditolak kalau `payment_method_id` kosong:
```json
{ "code": 100, "message": "phone_number, amount, dan payment_method_id wajib diisi" }
```

Ditolak kalau payment method ditemuin tapi gak punya `payment_gateway_code`:
```json
{ "code": 100, "message": "payment method tidak didukung" }
```

## Response — jalur gateway (QRIS)

```json
{
  "code": 0,
  "data": {
    "reference_number": "TUJKT202508140002",
    "status": "pending",
    "qr_string": "00020101021226...",
    "qr_url": "https://api.midtrans.com/v2/qris/...",
    "expired_at": "2026-08-14T15:30:00+07:00"
  }
}
```

Belum ada perubahan saldo di titik ini — tampilin QR ke customer, lanjut polling ke **Check Status** di bawah.

## Check Status (polling, jalur gateway)

```
GET /api/kiosk/member/topup/check-status/{reference_number}
```

Dipanggil berkala (mis. tiap beberapa detik) sambil QR ditampilin, pakai `reference_number` dari response Request Topup di atas.

Response (`pending`, masih nunggu):
```json
{ "code": 0, "data": { "reference_number": "TUJKT202508140002", "status": "pending" } }
```

Response (`paid`, ditambahkan 2026-08-28 — field enrichment buat print struk):
```json
{
  "code": 0,
  "data": {
    "reference_number": "TUJKT202508140002",
    "status": "paid",
    "balance_after": "250000.00",
    "terminal_id": 4,
    "amount": "100000.00",
    "member_name": "Budi Santoso",
    "member_phone_number": "081234567890",
    "payment_gateway_code": "MIDTRANS_QRIS",
    "paid_at": "2026-08-28T16:05:00+07:00"
  }
}
```

`status` — `pending` / `paid` / `expired` / `cancel` / `failed`.

- `pending` → tetep nunggu, terus polling. Field `terminal_id`/`amount`/`member_name`/dst **belum** keisi (`omitempty`, cuma muncul kalau `status="paid"`).
- `paid` → top-up sukses, `balance_after` udah keisi (saldo final) — lanjut ke halaman sukses. Field tambahan (`terminal_id`, `amount`, `member_name`, `member_phone_number`, `payment_gateway_code`, `paid_at`) ditarik LANGSUNG dari data yang udah di-load APIANDORDER (`member_topup_online` + join `master_member`), gak query ulang — dipakai Laravel (stateless, gak nyimpen ulang data topup) buat isi struk, lihat section **Print Struk** di bawah.
- `expired` / `cancel` / `failed` → nampilin "gagal, coba lagi" (Kiosk request topup baru dari awal — sama pola kayak payment order, gak ada retry-attempt nyambung ke `reference_number` lama, lihat catatan struktur `member_topup_online` yang sengaja 1:1 per percobaan).

## Alur di dalemnya

1. **Laravel** (`MemberBalanceServices::TopupBalance()`) — resolve `payment_method_id` lokal (`mr_payment_method`) → `payment_gateway_code`, tolak kalau kosong (lihat validasi di atas). `phone_number` diterusin apa adanya, **bukan** di-resolve ke `member_id` di sini.
2. **APIANDORDER** (`CreateTopup`) — validasi `phone_number`/`amount`/`source`, resolve `member_id` dari `phone_number` (query langsung `master_member`, sama query kayak `member.CheckByPhone()` — Laravel gak pernah pegang `member_id`).
3. Generate `reference_number` (prefix `TU`, format sama kayak semua reference number modul lain di sudocore2 — `PREFIX + kode_branch + tanggal + urutan 4-digit`, reset tiap hari per branch).
4. Karena `payment_gateway_code` udah dipastiin keisi dari langkah 1, jalur yang kepake dari Kiosk **selalu gateway**: insert `member_topup_online` (`status='pending'`), minta QR ke service `payment` (`POST /payment-gateway/qris`, `order_id = reference_number`), simpan `expired_at` dari response. (Service `CreateTopup` di APIANDORDER sendiri generic — masih support jalur tunai langsung kalau `payment_gateway_code` dikirim kosong, dipakai nanti buat POS; Kiosk gak pernah lewat situ karena validasi Laravel di langkah 1 udah nutup kemungkinan itu.)
5. **Check Status** — live-check ke service `payment`. Kalau `settlement` → confirm (insert `member_balance_ledger`, `balance_after` dihitung di SQL bukan Go float biar presisi `NUMERIC` gak keganggu, row `master_member` di-lock `FOR UPDATE` dulu biar operasi saldo per-member serial, idempotency guard biar gak dobel-insert kalau di-polling barengan). Kalau masih `pending` tapi `expired_at` lokal udah lewat → fallback: cancel ke gateway + treat `expired` (sama pola yang dipakai `PaymentGatewayServices::CheckStatus()` buat payment order — nyegah nyangkut `pending` selamanya kalau webhook Midtrans gak nyampe).

## Print Struk (ditambahkan 2026-08-28)

Struk top-up **otomatis** ke-trigger dari dalam `CheckTopupStatus()` (Laravel `KioskController`) begitu response APIANDORDER kedeteksi `status="paid"` — **bukan** endpoint terpisah yang harus dipanggil eksplisit sama Kiosk device.

Karena endpoint ini di-poll berkali-kali sambil nunggu QR di-scan, dan bakal terus balikin `status="paid"` di setiap polling SETELAH kebayar (idempotent by design), Laravel butuh **dedupe guard** biar gak nyetak struk berkali-kali: tabel lokal `tr_member_topup_print_log` (`reference_number` PK, `printed_at` nullable). Begitu 1 `reference_number` udah "diproses" (baik beneran print server-side maupun cuma ditandain buat jalur frontend), polling-polling berikutnya di-skip.

Tabel ini **cuma** nyimpen dedupe flag — **gak** nyimpen ulang data topup apapun (`amount`, `member_id`, dst). Semua data buat isi struk ditarik LIVE dari response APIANDORDER tiap kali (lihat field enrichment di response `paid` di atas) — Laravel di alur ini murni stateless soal data topup, sama filosofinya kayak `CheckTopupStatus()` yang cuma passthrough.

Alur di `KioskController::CheckTopupStatus()` → `TriggerTopupPrintIfNeeded()`:
1. Cek `tr_member_topup_print_log` — kalau `printed_at` udah keisi buat `reference_number` ini, skip semuanya (udah pernah diproses).
2. Insert/update baris dedupe (`created_at`, belum `printed_at`).
3. Resolve `mr_terminal` by `data.terminal_id` (dari response APIANDORDER) — dipakai buat cek `flag_printer_frontend`, **BUKAN** buat nentuin printer/station (lihat poin 5).
4. Resolve `payment_method_name` lokal dari `mr_payment_method` by `data.payment_gateway_code` (arah kebalik dari resolusi `payment_gateway_code` di `TopupBalance()`).
5. Kalau `mr_terminal.flag_printer_frontend == true` → **gak** print server-side (device kiosk sendiri yang tanggung jawab render/print browser, sama pola struk order) — cuma tandain dedupe `printed_at`. Kalau `false` (atau terminal gak ketemu) → panggil `PrintServices::PrintTopup($topupData)` server-side (ESC/POS), baru tandain `printed_at`.

**Penting soal station/printer**: `PrintServices::PrintTopup()` **mirror persis** `PrintPayment()` (struk order) — sumber printer-nya `SettingModel::first()->default_station` (1 station default buat SELURUH POS), **BUKAN** `mr_terminal.receipt_station_id`. Field `receipt_station_id` di `mr_terminal` (lihat `KIOSK TERMINAL DETAIL.md`) itu ada di skema & diekspos API, tapi **gak dipakai buat routing print manapun** di sistem ini saat ini — konsisten sama semua struk customer lain (order/bill), jadi struk top-up ngikutin pola yang sama, bukan bikin mekanisme routing baru.

Format struk (`PrintServices::PrintTopup()`, `app/Services/PrintServices.php`):
```
        [logo header]
      {printing_header}
--------------------------------
      TOP UP SALDO MEMBER
--------------------------------
No. Referensi : TUJKT202508140002
Tanggal       : 2026-08-28T16:05:00+07:00
Member        : Budi Santoso
No. HP        : 081234567890
Metode Bayar  : QRIS
--------------------------------
Nominal Top Up      :   Rp 100.000
Saldo Sekarang      :   Rp 250.000
--------------------------------
      {printing_footer}
```

## Catatan desain

- Jurnal akuntansi (`accounting_general_ledger` — "Hutang Saldo Member" liability, akun `2.1.07.01 MEMBER WALLET PAYABLE`) di-sweep async belakangan oleh proses background di sudocore2 (`backend/modules/memberbalancejurnal`, kolom `member_balance_ledger.jurnal_at` `NULL` = belum dijurnal), bukan bagian dari alur real-time ini.
- Topup dari ERP (admin manual) belum diimplementasi sama sekali — belum ada tabel/modul-nya.
