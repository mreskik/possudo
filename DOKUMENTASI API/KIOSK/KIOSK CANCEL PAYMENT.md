# Kiosk Cancel Payment

```
POST /api/kiosk/payment/cancel
```

Cancel QR/attempt payment yang lagi aktif **tanpa nyentuh status order** (order tetap `pending`) — beda dari [KIOSK CANCEL ORDER.md](./KIOSK%20CANCEL%20ORDER.md) yang niatnya emang batalin ordernya sekalian. Dipakai kalau customer cuma mau batalin QR doang (mis. ganti pikiran soal metode bayar), tapi masih niat lanjut bayar/edit order-nya nanti — abis ini tinggal panggil [KIOSK PAYMENT REQUEST.md](./KIOSK%20PAYMENT%20REQUEST.md) lagi kapan aja buat dapet QR baru.

Logic di `App\Services\PaymentGatewayServices::CancelPendingAttempt()` — method yang sama juga dipakai internal oleh [KIOSK EDIT ORDER](./KIOSK%20SAVE%20ORDER.md#edit-order-order_number-diisi) dan [KIOSK CANCEL ORDER.md](./KIOSK%20CANCEL%20ORDER.md).

## Request

```json
{ "order_number": "NO4TB20260813123301" }
```

## Guard

Cuma bisa dipanggil kalau:
- `order_number` ketemu di `tr_order`.
- `tr_order.status === 'pending'`.
- `tr_order.payment_number === null` (belum pernah dibayar).

Order dengan status lain (`hold`/`paid`/`cancel`/`expired`/dst) ditolak duluan, gak sempat nyoba cancel apa-apa ke Midtrans.

## Race guard (penting)

`CancelPendingAttempt()` **live-check ke Midtrans dulu** (`GET /payment-gateway/{order_id}`) sebelum mutusin cancel — bukan blind-cancel. Alasannya: sistem ini polling (bukan webhook), jadi ada window kecil dimana customer sempet **beneran scan & bayar** QR itu **PERSIS** pas endpoint ini dipanggil, sebelum Laravel sempat tau statusnya udah berubah.

- Attempt masih **beneran** `pending` (atau live-check-nya gagal, mis. network) di Midtrans → di-cancel, order tetap `pending`.
- Attempt udah **`expired`/`cancel`/`failed`** duluan di Midtrans (bukan gara-gara endpoint ini) → gak perlu cancel lagi (Midtrans bakal nolak), status lokal disinkronin aja, order tetap `pending`.
- Attempt ternyata **udah `settlement`** (race beneran kejadian) → **JANGAN di-cancel** — `confirmPayment()` dipanggil malah (sama logic yang dipakai [KIOSK PAYMENT CHECK STATUS.md](./KIOSK%20PAYMENT%20CHECK%20STATUS.md)), order jadi `paid` beneran, `tr_order_payment` ke-insert. Response ngasih tau ini terjadi, **bukan** pura-pura sukses cancel.

Ini nutup celah: kalau endpoint ini blind-cancel aja tanpa live-check, duit yang **udah beneran kebayar** di Midtrans bisa "ke-orphan" — order nyangkut gak sinkron sama status pembayaran aslinya (Midtrans udah nerima uang, tapi POS gak pernah nyatet `paid`).

## Response

Sukses, attempt beneran di-cancel:

```json
{ "code": 0, "message": "payment berhasil di-cancel" }
```

Sukses, tapi gak ada attempt aktif yang perlu di-cancel (no-op):

```json
{ "code": 0, "message": "tidak ada payment aktif buat di-cancel" }
```

`order_number` gak dikirim:

```json
{ "code": 100, "message": "order_number wajib diisi" }
```

`order_number` gak ketemu:

```json
{ "code": 100, "message": "order tidak ditemukan" }
```

Order bukan `pending` / udah pernah dibayar:

```json
{ "code": 100, "message": "payment order ini tidak bisa di-cancel (bukan status pending / sudah dibayar)" }
```

Race — ternyata udah kebayar pas mau di-cancel:

```json
{ "code": 100, "message": "order ternyata sudah dibayar, tidak jadi di-cancel" }
```

## Tervalidasi live (2026-08-13)

- Order `pending` dengan QR aktif → cancel sukses, `tr_order.status` **tetap `pending`** (gak berubah), dicek langsung `GET /payment-gateway/{order_id}` ke service `payment` → beneran `status: cancel` di Midtrans (bukan cuma klaim lokal).
- Dipanggil lagi abis itu (gak ada attempt aktif) → `"tidak ada payment aktif buat di-cancel"`, bukan error.
- `order_number` gak dikirim / gak ketemu / order status `cancel` → ketolak sesuai pesan masing-masing.
- **Race condition** — attempt di-set `settlement` manual di Postgres (simulasi customer bayar tepat sebelum cancel diproses) → endpoint balikin `"order ternyata sudah dibayar, tidak jadi di-cancel"`, dan `tr_order` beneran ke-update jadi `status: paid` + `payment_number` keisi + `tr_order_payment` ke-insert (1 baris) — **bukan** ke-cancel padahal duitnya udah masuk. Dipanggil lagi abis itu (order udah `paid`) → ketolak guard normal.

## Catatan: `CancelPendingAttempt()` sekarang live-check-aware buat semua pemanggil

Perubahan ini bukan cuma buat endpoint ini — `PaymentGatewayServices::CancelPendingAttempt()` diupgrade dari blind-cancel jadi live-check-aware, jadi [KIOSK EDIT ORDER](./KIOSK%20SAVE%20ORDER.md#edit-order-order_number-diisi) dan [KIOSK CANCEL ORDER.md](./KIOSK%20CANCEL%20ORDER.md) ikut kebagian proteksi yang sama:

- **Edit order**: kalau race kejadian (attempt settlement pas mau diedit), edit **dibatalin** (`"order sudah dibayar, tidak bisa diedit lagi"`), item/nominal yang udah dibayar gak ketimpa isi edit yang baru.
- **Cancel order**: kalau race kejadian, order otomatis kejadi `paid` di dalam `CancelPendingAttempt()`, terus `OrderServices::CancelOrder()` otomatis nolak (guard `status IN ('pending','hold')`-nya, order udah bukan itu lagi) — gak perlu guard tambahan eksplisit di situ.
