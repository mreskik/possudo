# Kiosk Payment Method List

```
GET /api/kiosk/payment-method
```

List payment method yang boleh dipakai di Kiosk. Filter sekarang: **`payment_gateway_code` keisi (bukan `NULL`/string kosong `''`)** — Kiosk self-service (gak ada kasir yang megang uang/kartu manual), jadi cuma nawarin payment method yang integrasi otomatis lewat gateway (misal QRIS Midtrans), bukan metode manual kayak cash.

Ini filter sementara — belum difilter per `visit_purpose_id` (bandingkan sama `MasterController::GetPaymentMethod`, yang join ke `mr_payment_method_visit_purposes`). Nyusul kalau dibutuhin.

Query langsung ke `mr_payment_method`, gak ada join. Response cuma 3 field minimal: `id`, `name`, `code` — field lain (mdr, printer_count, dll) gak relevan buat Kiosk, biar ringan.

## Response

```json
{
  "code": 0,
  "data": [
    { "id": 1, "name": "QRIS", "code": "QRA" }
  ]
}
```

Kalau gak ada payment method yang `payment_gateway_code`-nya keisi: `{"code":0,"data":[]}` (bukan error).

## Tervalidasi live (2026-08-10)

Set 1 payment method `payment_gateway_code='MIDTRANS_QRIS'` dan 1 lagi `=''` (string kosong) — cuma yang `MIDTRANS_QRIS` yang muncul (`{"id":1,"name":"QRIS","code":"QRA"}`), yang `NULL` dan yang `''` keduanya ke-filter keluar. Data test udah direvert.
