# Panduan API Kiosk (Self-Order)

## Aturan

- Base endpoint: `/api/kiosk/*`
- Route dikelompokkan pakai `Route::prefix('kiosk')` di `routes/api.php`.
- Controller: `App\Http\Controllers\KioskController` (baru, terpisah dari `OrderController`/`MasterController`).
- Device dianggap kiosk kalau `localStorage.terminal_type == '3'` (`mr_pos_type.device_type = "kiosk"`) — redirect ke bundle `/kiosk` diatur di `posv1-vue/src/router/index.js`.
- Endpoint kiosk **gak pakai token** — cukup dilindungi middleware `CheckAllowedIp` yang udah jalan global di semua `/api/*` (cek `POS_ALLOWED_IPS`).
- Semua field response pakai `snake_case` (beda dari endpoint lama di `/api/master/*` yang masih `camelCase`).

## Endpoint

- [KIOSK DAY STATUS.md](./KIOSK%20DAY%20STATUS.md) — `GET /api/kiosk/day-status`
- [KIOSK BRANCH VISIT PURPOSE.md](./KIOSK%20BRANCH%20VISIT%20PURPOSE.md) — `GET /api/kiosk/branch-visit-purpose`
- [KIOSK BRANCH VISIT PURPOSE DETAIL.md](./KIOSK%20BRANCH%20VISIT%20PURPOSE%20DETAIL.md) — `GET /api/kiosk/branch-visit-purpose/{id}` (config + rate pajak + pohon menu)
- [KIOSK TERMINAL DETAIL.md](./KIOSK%20TERMINAL%20DETAIL.md) — `GET /api/kiosk/terminal/{id}`
- [KIOSK SAVE ORDER.md](./KIOSK%20SAVE%20ORDER.md) — `POST /api/kiosk/save-order` — wrapper tipis ke `OrderServices::SaveOrder()` yang sama dipakai POS (`order_source` di-hardcode `kiosk`, `table_section_id` di-resolve dari `mr_terminal`). Print kitchen ditunda sampai payment, bukan pas save-order.
- [KIOSK ORDER DETAIL.md](./KIOSK%20ORDER%20DETAIL.md) — `GET /api/kiosk/order/{order_number}` — header order doang (`order_name`, `sub_total`, `total_tax`, `total_discount`, `total_billing`), belum termasuk list item.
- [KIOSK PAYMENT METHOD.md](./KIOSK%20PAYMENT%20METHOD.md) — `GET /api/kiosk/payment-method` — list payment method yang `payment_gateway_code`-nya keisi (belum difilter per visit purpose).

## Status

7 endpoint (day-status, branch-visit-purpose list+detail, terminal detail, save-order, order detail, payment method) udah kelar, tervalidasi live, dan terdokumentasi. Belum ada frontend Kiosk (`public/kiosk/` masih kosong) — endpoint-endpoint ini nunggu dipakai begitu bundle Vue Kiosk mulai dibangun.
