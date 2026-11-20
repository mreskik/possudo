# Perubahan Field — Rounding (2026-11-20)

**Breaking change API publik POS Laravel (termasuk Kiosk).** Field baru `grand_total` dan
`rounding_amount` di level order (header), menyusul fitur rounding (pembulatan nominal order)
yang sekarang diterapkan. **Request TIDAK berubah di endpoint manapun** — cuma field response
yang berubah, lihat tabel di bawah.

`grand_total` = akumulasi item + `total_service_charge` + `order_fee` + `delivery_cost`, SEBELUM
dibulatkan (ini yang SEBELUMNYA namanya `total_billing`). `rounding_amount` sudah lama ada sebagai
kolom (sejak migration `2026_09_30_031500`), tapi baru sekarang beneran dihitung — selisih
pembulatan, tergantung setting rounding branch (bisa `"0.00"`). `total_billing` TETAP ADA, TETAP
nilai final yang harus ditagih/ditampilkan ke customer — cuma nilainya sekarang `grand_total +
rounding_amount`, bisa beda dari `grand_total` kalau branch-nya pakai rounding.

## Daftar perubahan field

| Path Dokumentasi | Endpoint | Field Sebelum | Field Sesudah |
|---|---|---|---|
| [`POS/ORDER/SAVE ORDER.md`](POS/ORDER/SAVE%20ORDER.md) | `POST /api/order/save-order` | `totalBilling` (header) | `grandTotal`, `roundingAmount`, `totalBilling` (header) |
| [`POS/ORDER/CANCEL ITEM.md`](POS/ORDER/CANCEL%20ITEM.md) | `POST /api/order/cancel-item` | `total_billing` (header, hasil recalculate) | `grand_total`, `rounding_amount`, `total_billing` (header, hasil recalculate) |
| [`POS/ORDER/MOVE TABLE AND MOVE ITEM.md`](POS/ORDER/MOVE%20TABLE%20AND%20MOVE%20ITEM.md) | `POST /api/order/move-table`, `/move-item` | `sub_total`/`total_billing` (header, hasil recalculate) | ditambah `grand_total`/`rounding_amount` (header, hasil recalculate) |
| [`POS/ORDER/ORDER LIST.md`](POS/ORDER/ORDER%20LIST.md) | `GET /api/orderlist/by-tablesection-new/{id}`, `/by-tablesection/{id}` | `sub_total`, `total_discount`, `total_tax`, `total_billing` (per order, `SELECT *` generic) | ditambah `grand_total` (per order — `rounding_amount` sudah ada lebih dulu dari sebelumnya) |
| [`POS/SALES/VIEW SALES.md`](POS/SALES/VIEW%20SALES.md) | `GET /api/sales/view/{order_number}` | `sub_total`, `total_discount`, `total_tax`, `total_billing` | ditambah `grand_total`, `rounding_amount` |
| [`POS/DAYSHIFT/START END DAY.md`](POS/DAYSHIFT/START%20END%20DAY.md) | `POST /api/dayshift/start`, `/end` | `sub_total`, `total_discount`, `total_tax`, `total_billing` (per order, laporan dayshift) | ditambah `grand_total`, `rounding_amount` |
| [`POS/MASTER/MEMBER.md`](POS/MASTER/MEMBER.md) | `GET /api/member/...` (riwayat order member) | `sub_total`, `total_discount`, `total_tax`, `total_billing` | ditambah `grand_total`, `rounding_amount` |
| [`KIOSK/KIOSK SAVE ORDER.md`](KIOSK/KIOSK%20SAVE%20ORDER.md) | `POST /api/kiosk/save-order` | `sub_total`, `total_tax`, `total_service_charge`, `total_billing`, dst (header, hasil recalculate) | ditambah `grand_total`, `rounding_amount` (header, hasil recalculate) |
| [`KIOSK/KIOSK PRINT DATA.md`](KIOSK/KIOSK%20PRINT%20DATA.md) | `GET /api/kiosk/print-data/{order_number}` | `sub_total`, `total_discount`, `total_tax`, `total_billing` | ditambah `grand_total`, `rounding_amount` |
| [`KIOSK/KIOSK ORDER DETAIL.md`](KIOSK/KIOSK%20ORDER%20DETAIL.md) | `GET /api/kiosk/order/{order_number}` | `sub_total`, `total_tax`, `total_discount`, `total_service_charge`, `order_fee`, `delivery_cost`, `total_billing` | ditambah `grand_total`, `rounding_amount` |
| [`KIOSK/PANDUAN KIOSK.md`](KIOSK/PANDUAN%20KIOSK.md) | (daftar ringkasan endpoint) | — | daftar field `KIOSK ORDER DETAIL.md` diupdate menyebut `grand_total`/`rounding_amount` |

**Request**: tidak berubah di semua endpoint di atas — client tetap kirim payload yang sama
(`order_fee`/`delivery_cost` tetap FE-trusted apa adanya, field turunan lain tetap diabaikan server).
`grand_total`/`rounding_amount` dihitung server dari `mr_branch_setting.flag_rounding`/
`rounding_nearst_value` milik branch order ini, bukan dari input client.

**Tidak terdampak** (field-nya tidak berubah sama sekali, sengaja tidak masuk tabel di atas —
`total_billing` tetap satu-satunya field finansial yang dipilih, nilainya tetap benar sebagai
nilai final karena sudah termasuk rounding):
[`POS/SALES/SALES LIST.md`](POS/SALES/SALES%20LIST.md),
[`POS/ORDER/ORDER NOTIF.md`](POS/ORDER/ORDER%20NOTIF.md),
[`KIOSK/KIOSK ORDER HISTORY.md`](KIOSK/KIOSK%20ORDER%20HISTORY.md),
[`POS/PAYMENT/SAVE EDIT VIEW PAYMENT.md`](POS/PAYMENT/SAVE%20EDIT%20VIEW%20PAYMENT.md),
[`POS/PAYMENT/PAYMENT GATEWAY.md`](POS/PAYMENT/PAYMENT%20GATEWAY.md),
[`KIOSK/KIOSK PAYMENT REQUEST.md`](KIOSK/KIOSK%20PAYMENT%20REQUEST.md) — semuanya cuma baca/validasi
`total_billing` apa adanya (nilai final sudah termasuk rounding), tidak perlu tahu breakdown
`grand_total`/`rounding_amount`.

## Detail lengkap (formula, hasil live-test)

Lihat [`POS/ORDER/SAVE ORDER.md`](POS/ORDER/SAVE%20ORDER.md#formula-rounding-2026-11-20) sebagai
sumber kebenaran utama — semua dokumen lain di tabel atas tinggal merujuk ke situ untuk detail
formula perhitungan (`OrderServices::RecalculateOrderTotals()`).
