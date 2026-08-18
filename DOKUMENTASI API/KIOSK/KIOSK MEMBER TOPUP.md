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
  "payment_method_id": 3
}
```

- **`phone_number`** — wajib. **BUKAN** `member_id` — sama alasan kayak `SaveOrder()` (`resolveMemberIdByPhone()`): `member_id` itu identitas internal, gak boleh dipercaya langsung dari client (resiko nambah saldo ke akun member lain kalau di-tamper). Server (APIANDORDER) yang resolve `phone_number → member_id` sendiri, query langsung ke `master_member` — Laravel/Kiosk gak pernah pegang `member_id` sama sekali di alur ini.
- **`amount`** — wajib, nominal top-up (> 0).
- **`payment_method_id`** — **wajib**, dan wajib payment method yang punya `payment_gateway_code` keisi — validasinya **SAMA PERSIS** `RequestPayment()` (`App\Services\MemberBalanceServices::TopupBalance()`, mirror `PaymentGatewayServices::RequestPayment()`): kalau `payment_method_id` gak dikirim, atau ditemuin tapi `payment_gateway_code`-nya kosong, langsung ditolak — **tidak ada fallback diam-diam ke tunai**.

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

Response:
```json
{ "code": 0, "data": { "reference_number": "TUJKT202508140002", "status": "paid", "balance_after": "250000.00" } }
```

`status` — `pending` / `paid` / `expired` / `cancel` / `failed`.

- `pending` → tetep nunggu, terus polling.
- `paid` → top-up sukses, `balance_after` udah keisi (saldo final) — lanjut ke halaman sukses.
- `expired` / `cancel` / `failed` → nampilin "gagal, coba lagi" (Kiosk request topup baru dari awal — sama pola kayak payment order, gak ada retry-attempt nyambung ke `reference_number` lama, lihat catatan struktur `member_topup_online` yang sengaja 1:1 per percobaan).

## Alur di dalemnya

1. **Laravel** (`MemberBalanceServices::TopupBalance()`) — resolve `payment_method_id` lokal (`mr_payment_method`) → `payment_gateway_code`, tolak kalau kosong (lihat validasi di atas). `phone_number` diterusin apa adanya, **bukan** di-resolve ke `member_id` di sini.
2. **APIANDORDER** (`CreateTopup`) — validasi `phone_number`/`amount`/`source`, resolve `member_id` dari `phone_number` (query langsung `master_member`, sama query kayak `member.CheckByPhone()` — Laravel gak pernah pegang `member_id`).
3. Generate `reference_number` (prefix `TU`, format sama kayak semua reference number modul lain di sudocore2 — `PREFIX + kode_branch + tanggal + urutan 4-digit`, reset tiap hari per branch).
4. Karena `payment_gateway_code` udah dipastiin keisi dari langkah 1, jalur yang kepake dari Kiosk **selalu gateway**: insert `member_topup_online` (`status='pending'`), minta QR ke service `payment` (`POST /payment-gateway/qris`, `order_id = reference_number`), simpan `expired_at` dari response. (Service `CreateTopup` di APIANDORDER sendiri generic — masih support jalur tunai langsung kalau `payment_gateway_code` dikirim kosong, dipakai nanti buat POS; Kiosk gak pernah lewat situ karena validasi Laravel di langkah 1 udah nutup kemungkinan itu.)
5. **Check Status** — live-check ke service `payment`. Kalau `settlement` → confirm (insert `member_balance_ledger`, `balance_after` dihitung di SQL bukan Go float biar presisi `NUMERIC` gak keganggu, row `master_member` di-lock `FOR UPDATE` dulu biar operasi saldo per-member serial, idempotency guard biar gak dobel-insert kalau di-polling barengan). Kalau masih `pending` tapi `expired_at` lokal udah lewat → fallback: cancel ke gateway + treat `expired` (sama pola yang dipakai `PaymentGatewayServices::CheckStatus()` buat payment order — nyegah nyangkut `pending` selamanya kalau webhook Midtrans gak nyampe).

## Catatan desain

- Jurnal akuntansi (`accounting_general_ledger` — "Hutang Saldo Member" liability, akun `2.1.07.01 MEMBER WALLET PAYABLE`) di-sweep async belakangan oleh proses background di sudocore2 (`backend/modules/memberbalancejurnal`, kolom `member_balance_ledger.jurnal_at` `NULL` = belum dijurnal), bukan bagian dari alur real-time ini.
- Topup dari ERP (admin manual) belum diimplementasi sama sekali — belum ada tabel/modul-nya.
