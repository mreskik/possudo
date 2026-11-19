# Kiosk Save Order

```
POST /api/kiosk/save-order
```

**1 endpoint buat create DAN edit**, dibedain dari `order_number` — sama persis konvensi POS (`OrderController::saveOrder()`, 1 route buat keduanya juga). Payload masuk **`snake_case`** (konsisten sama endpoint Kiosk lain).

- **`order_number` kosong/gak dikirim** → order baru. Wrapper tipis di `KioskController::SaveOrder()` — convert payload ke `camelCase` internal lewat `mapKioskOrderPayload()`, manggil `OrderServices::SaveOrder()` yang sama persis dipakai POS, gak ada logic ditulis ulang.
- **`order_number` diisi** → edit order yang udah ada. Ditangani `KioskController::editExistingOrder()`, **beda logic sama sekali** dari create (bukan lewat `OrderServices::SaveOrder()`) — lihat section "Edit order" di bawah.

## Create order (`order_number` kosong)

Selain convert case, ada 3 hal yang di-resolve/dipaksa di controller, gak dipercaya dari client:

1. **`order_source`** — di-hardcode `'kiosk'` (client gak bisa ngirim/override ini).
2. **`table_section_id`** — diambil dari `mr_terminal.table_section_id` berdasarkan `terminal_id` yang dikirim (device Kiosk pra-dikonfigurasi ke 1 table section tetap, user gak milih meja). Kalau `mr_terminal.table_section_id` kosong (`NULL`), request ditolak: `{"code":100,"message":"terminal ini belum dikonfigurasi table_section_id, hubungi admin"}` — **setiap terminal Kiosk wajib di-set `table_section_id`-nya dulu** sebelum bisa dipakai order beneran.
3. **`member_id`** — **gak dipercaya dari client sama sekali**, di-derive server-side dari `customer_phone_number`. Kalau `customer_phone_number` ada isinya, di-lookup ke `mr_member` **lokal** (bukan live ke ERP — beda dari `GET /api/kiosk/member/check/{phone_number}` yang live, di sini sengaja pakai data lokal biar nyimpen order gak gantung koneksi ke ERP). Nomor kosong/null atau gak ketemu member → `member_id` tetap `null`, order tetap kesimpen (jadi member bukan syarat wajib). **Field `member_id` gak perlu dikirim lagi** — dihapus dari payload.

## Field duit/rate/amount — kirim sebagai string

Semua field yang berhubungan sama uang, rate (persen pajak/diskon), atau nominal — **kirim sebagai string**, bukan number JSON mentah (`"14500.00"`, bukan `14500`). Ini standar buat endpoint Kiosk (baru, jadi bisa langsung distandarin dari awal) — beda dari POS yang masih campuran number/string di berbagai tempat (belum dirapiin, rencana nyusul belakangan, di luar scope Kiosk ini). Tervalidasi kirim string tetep kesimpen bener sebagai `decimal` di DB (MySQL/PDO gak masalah nerima string numerik).

Field yang termasuk kelompok ini: `sub_total`, `total_tax`, `total_service_charge`, `total_billing`, `total_discount`, `order_fee`, `delivery_cost` (level order), dan per item di `list_order[]`/`menu_package_list[]`: `price`, `discount_rate`, `discount_value`, `dpp`, `total`. (`tax_rate`/`service_charge_rate`/`tax_value` sudah gak relevan lagi — field mati, lihat "field MATI" di bawah.)

## ⚠️ Backend hitung ulang net_price/dpp/tax_value/service_charge_amount/total server-side, item & header (2026-11-18)

Field **nama**-nya gak berubah di kontrak request ini (sengaja dipertahankan apa adanya), tapi
backend **gak lagi percaya mentah-mentah** field turunan yang dikirim Kiosk — dihitung ulang
sendiri lewat `KioskController::recomputeKioskUnit()` per baris `list_order[]`/`menu_package_list[]`,
rumus **delegasi langsung** ke `OrderServices::CalculateLineItem()` (fungsi yang sama dipakai POS
`SaveOrder()`, 1 sumber kebenaran — lihat [`../ORDER/SAVE ORDER.md`](../ORDER/SAVE%20ORDER.md)
untuk detail lengkap rumus + simulasi angka):

- **`tax_value`, `dpp`, `total` yang dikirim client DIABAIKAN** — backend
  hitung ulang dari `price`/`tax_rate`/`service_charge_rate`/`flag_inclusive_tax`/
  `discount_rate`/`discount_value`. Boleh tetap dikirim (gak dibaca), atau boleh dihilangkan
  dari payload — sama-sama gak masalah.
- **`tax_rate`/`service_charge_rate` yang dikirim client JUGA DIABAIKAN (2026-11-18, BARU)** —
  sebelumnya dipercaya dari client, sekarang di-**lookup server-side dari `mr_tax`** berdasar
  `tax_id`/`service_charge_id` (`OrderServices::ResolveTaxRate()`, 1 sumber kebenaran sama
  POS). Client cukup kirim `tax_id`/`service_charge_id`, rate-nya diurus backend. `tax_id`/
  `service_charge_id` kosong/`null` → rate `0` (valid, item tanpa pajak). **Id terisi tapi
  gak ketemu di `mr_tax`** → request DITOLAK (`{"code":100,"message":"tax/service charge
  dengan id {id} tidak ditemukan"}`), order gagal tersimpan sama sekali.
- **`discount_value` cuma dipercaya kalau `discount_rate == 0`** (promo nominal rupiah / gak ada
  promo). Kalau `discount_rate > 0` (promo persen), `discount_value` yang dikirim **diabaikan**,
  backend hitung ulang dari basis net price (`discount_rate% × netPrice`, bukan dari `price` gross).
- Urutan hitung ulangnya (5 langkah, sama persis POS): `netPrice` dilepas dulu dari `price`
  (pajak+service charge, kalau inclusive) → `dpp = netPrice - discountAmount` → `tax_value`
  (`dpp × taxRate%`, nama kolom DB `tax_amount`) → `service_charge_amount` (`dpp ×
  serviceChargeRate%`) → `total = dpp + tax_value + service_charge_amount`, dikali `qty` buat
  nilai yang beneran kesimpen.
- **Header juga dihitung ulang** (bukan cuma item) — `sub_total`/`total_discount`/`total_tax`/
  `total_service_charge`/`total_billing`/`total_item` yang dikirim client **DIABAIKAN**, di-
  overwrite oleh `OrderServices::RecalculateOrderTotals()` berdasar baris item yang baru
  disimpan. Exception (TETAP FE-trusted, gak di-recalculate): `order_fee`, `delivery_cost`.

Alasannya: app Kiosk (repo terpisah, di luar `posv1-laravel`) belum tentu ikut diupdate ke rumus
baru bareng — backend jadi otoritas terakhir buat angka finansial, gak nelen mentah-mentah hasil
hitungan client manapun.

Field angka biasa (**tetap number**, bukan uang/rate): `terminal_id`, `visit_purpose_id`, `price_list_id`, `member_id`, `order_pax`, `total_item`, `qty`, `tax_id`, `service_charge_id`, `promo_id`, `menu_pricelist_id`, `menu_id`, `menu_package_id` — ini id/quantity, bukan nominal.

## Contoh request

1 item tanpa package + 1 item dengan package terpilih:

```json
POST /api/kiosk/save-order
Content-Type: application/json

{
  "order_name": "Kiosk Terminal 1 - 14:32",
  "customer_phone_number": "",
  "terminal_id": 4,
  "visit_purpose_id": 2,
  "price_list_id": 5,
  "order_pax": 1,
  "total_item": 2,
  "sub_total": "22500.00",
  "total_tax": "0.00",
  "total_service_charge": "0.00",
  "total_billing": "22500.00",
  "total_discount": "0.00",
  "order_fee": "0.00",
  "delivery_cost": "0.00",
  "list_order": [
    {
      "menu_pricelist_id": 49,
      "menu_id": 69,
      "qty": 1,
      "flag_inclusive_tax": 1,
      "price": "14500.00",
      "tax_id": null,
      "tax_type": "",
      "service_charge_id": 16,
      "promo_id": null,
      "is_free_item_promo": false,
      "discount_rate": "0.00",
      "discount_value": "0.00",
      "net_price": "14500.00",
      "dpp": "14500.00",
      "total": "14500.00",
      "notes": "",
      "menu_package_list": []
    },
    {
      "menu_pricelist_id": 62,
      "menu_id": 72,
      "qty": 1,
      "flag_inclusive_tax": 1,
      "price": "0.00",
      "tax_id": null,
      "tax_type": "",
      "service_charge_id": 16,
      "promo_id": null,
      "is_free_item_promo": false,
      "discount_rate": "0.00",
      "discount_value": "0.00",
      "net_price": "8000.00",
      "dpp": "8000.00",
      "total": "8000.00",
      "notes": "less ice",
      "menu_package_list": [
        {
          "menu_package_id": 17,
          "menu_id": 69,
          "qty": 1,
          "flag_inclusive_tax": 1,
          "price": "8000.00",
          "tax_id": null,
          "tax_type": "",
          "service_charge_id": 16,
          "promo_id": null,
          "discount_rate": "0.00",
          "discount_value": "0.00",
          "net_price": "8000.00",
          "total": "8000.00",
          "notes": ""
        }
      ]
    }
  ]
}
```

## Contoh response

Sukses:

```json
{ "code": 0, "data": { "order_number": "NO4TB20260810154732" } }
```

Gagal — `terminal_id` gak dikirim:

```json
{ "code": 100, "message": "terminal_id wajib diisi" }
```

Gagal — id di `terminal_id` gak ada di `mr_terminal`:

```json
{ "code": 100, "message": "terminal tidak ditemukan" }
```

Gagal — terminal ketemu tapi belum di-setup `table_section_id`-nya:

```json
{ "code": 100, "message": "terminal ini belum dikonfigurasi table_section_id, hubungi admin" }
```

Gagal — error lain dari `OrderServices::SaveOrder()` (stok habis, dayshift belum buka, dll — pesan aslinya diteruskan apa adanya):

```json
{ "code": 100, "message": "Stok yang tersedia untuk CARAME MACHIATO hanya 3" }
```

Gagal — `tax_id`/`service_charge_id` yang dikirim gak ketemu di `mr_tax` (2026-11-18, BARU, lihat "Backend hitung ulang" di atas):

```json
{ "code": 100, "message": "tax/service charge dengan id 999999 tidak ditemukan" }
```

## Field payload

| Field | Isi |
| --- | --- |
| `terminal_id` | id terminal Kiosk (dari [KIOSK TERMINAL DETAIL.md](./KIOSK%20TERMINAL%20DETAIL.md)) — **wajib**, dipakai buat resolve `table_section_id` |
| `order_name` | nama/label order (bebas, misal nomor antrian atau nama customer) |
| `customer_phone_number` | opsional — kalau diisi, dipakai server buat cari `member_id` (lihat poin 4 di atas). Kosongin (`""`) atau jangan dikirim kalau customer gak mau/gak punya nomor. |
| `visit_purpose_id` | dari [KIOSK BRANCH VISIT PURPOSE.md](./KIOSK%20BRANCH%20VISIT%20PURPOSE.md) (`visit_purpose_id`, bukan `id`) |
| `price_list_id` | `menu_pricelist_id` dari [KIOSK BRANCH VISIT PURPOSE DETAIL.md](./KIOSK%20BRANCH%20VISIT%20PURPOSE%20DETAIL.md) |
| `order_pax`, `total_item` | dihitung di frontend dari isi keranjang (number, bukan string) |
| `sub_total`, `total_tax`, `total_service_charge`, `total_billing`, `total_discount` | dihitung di frontend dari isi keranjang — **string**, tapi DIABAIKAN backend (dihitung ulang, lihat section di atas), boleh dikirim boleh gak |
| `order_fee`, `delivery_cost` | **FE-trusted** (TIDAK dihitung ulang backend) — manual input, default `0` kalau gak dikirim |
| `list_order[]` | isi keranjang, lihat mapping di bawah |

**Jangan kirim `order_source`/`table_section_id`/`member_id`** — kalaupun dikirim, bakal diabaikan/ketimpa server (lihat poin 1-3 di atas). `order_number` boleh gak dikirim (create) atau diisi (edit, lihat section di bawah).

## Bentuk `list_order[]` — sumbernya dari respons [KIOSK BRANCH VISIT PURPOSE DETAIL.md](./KIOSK%20BRANCH%20VISIT%20PURPOSE%20DETAIL.md)

Item di keranjang Kiosk (dari endpoint detail, field-nya udah `snake_case`) tinggal dikirim balik apa adanya — server yang convert ke `camelCase` internal (`KioskController::mapKioskOrderPayload()`):

| Kiosk (`items[]`, dari endpoint detail) | `list_order[]` (payload save-order) |
| --- | --- |
| `detail_pricelist_id` | `menu_pricelist_id` |
| `item_id` | `menu_id` |
| — (qty dipilih user) | `qty` |
| `flag_inclusive_tax` | `flag_inclusive_tax` |
| `menu_price` | `price` |
| `tax_id` | `tax_id` — jadi dasar lookup rate server-side (lihat "Backend hitung ulang" di atas); kalau diisi, harus id yang beneran ada di `mr_tax` (gak ketemu = request ditolak). `null`/kosong = item tanpa pajak (valid, rate dianggap `0`) |
| `tax_type` | `tax_type` |
| `service_charge_id` (2026-11-18, BARU — lihat [KIOSK BRANCH VISIT PURPOSE DETAIL.md](./KIOSK%20BRANCH%20VISIT%20PURPOSE%20DETAIL.md)), **boleh `null`** (item tanpa service charge, valid — rate dianggap `0`) — kalau diisi, harus id yang beneran ada di `mr_tax` | `service_charge_id` |
| — (kosong kalau gak ada promo) | `promo_id`, `discount_rate`, `discount_value` (`discount_value` cuma dipercaya kalau `discount_rate == 0`) |
| — | `is_free_item_promo` (opsional, default `false` kalau gak dikirim) |
| — (dihitung di frontend, tapi **diabaikan backend** — basis kalkulasi `net_price`/`dpp`/`total` dijelaskan lengkap di "Backend hitung ulang" di atas) | `net_price`, `dpp`, `total` |
| — (opsional dari user) | `notes` |
| `package_list[]` (kalau ada) | `menu_package_list[]` — boleh dikosongin `[]` kalau item gak punya package, server yang isi default kalau field ini gak ada sama sekali |

**`tax_rate`, `service_charge_rate`, `tax_value` — field MATI, boleh dihilangkan total dari payload (2026-11-18)**: ketiganya **tidak dibaca sama sekali** oleh backend (bukan sekadar "diabaikan nilainya", tapi gak pernah diakses dari array request) — `tax_rate`/`service_charge_rate` di-lookup dari `mr_tax` lewat `tax_id`/`service_charge_id` (lihat "Backend hitung ulang" di atas), `tax_value` sudah mati lebih lama (lihat riwayat field basi lain). Boleh tetap dikirim kalau FE belum sempat dibersihkan (gak error), tapi gak ada gunanya — contoh JSON di dokumen ini sudah **sengaja gak menyertakan ketiganya** per hari ini, biar gak menyesatkan field mana yang masih hidup.

`menu_package_list[]` per baris (kalau item punya package terpilih) field-nya sama kayak `menu_package_list[]` di respons detail, MINUS `tax_rate`/`service_charge_rate` (field mati, lihat di atas), PLUS `net_price`/`dpp` (opsional, sama alasan level item di atas — diabaikan backend): `menu_package_id`, `menu_id`, `qty`, `flag_inclusive_tax`, `price`, `tax_id`, `tax_type`, `service_charge_id`, `promo_id`, `discount_rate`, `discount_value`, `net_price`, `dpp`, `total`, `notes`.

## Print kitchen ditunda sampai payment (bukan pas save-order)

Beda dari POS, order Kiosk **belum tentu jadi dibayar** begitu tersimpan (bisa aja user batal di tengah jalan sebelum bayar). Jadi print ke dapur (`PrintTableChecker2`, `PrintMainChecker2`, `PrintPriparationStation`) **sengaja gak jalan** pas `SaveOrder()` kalau `order_source === 'kiosk'` — `tr_order_detail.done_print` tetap `false` sampai order itu **beneran dibayar** (`POST /api/payment/save-payment`, endpoint yang sama dipakai POS). `PaymentServices::SavePayment()` yang trigger 3 print itu kalau order yang dibayar sumbernya kiosk, abis print struk pembayaran (`PrintPayment`) — baru `done_print` jadi `true`.

Order dari POS (`order_source='pos'`) **gak berubah** — tetep print langsung pas `SaveOrder()`, gak nunggu payment (tervalidasi lewat regression test).

## Tervalidasi live (2026-08-10)

- Validasi: tanpa `terminal_id` → error. Terminal gak ketemu → error. Terminal ada tapi `table_section_id` `NULL` → error.
- Happy path (payload `snake_case`): `save-order` → `order_source='kiosk'`, `table_section_id` ke-resolve otomatis dari terminal, `order_type` ikut kederivasi (`takeaway`), `done_print=0` (print ke-skip). Lanjut `save-payment` → sukses, `done_print` berubah jadi `1` (print kitchen ke-trigger di titik ini).
- Regression: order `order_source='pos'` lewat `/api/order/save-order` (masih `camelCase`, gak lewat wrapper Kiosk) tetep `done_print=1` langsung abis `SaveOrder()`, gak kepengaruh perubahan ini.

## Update (2026-08-12)

2 field dihapus dari payload yang perlu dikirim client:

- **`order_number`** — dulu client kirim `""`, sekarang gak perlu dikirim sama sekali (server selalu paksa `''`, apapun yang dikirim diabaikan).
- **`member_id`** — dulu client yang nentuin (biasanya `null` karena belum ada fitur login member), sekarang **selalu di-derive server-side** dari `customer_phone_number` lewat lookup ke `mr_member` lokal (`WHERE phone_number = ? AND is_active = 1`). Sengaja pakai data lokal (bukan live ke ERP kayak `GET /api/kiosk/member/check/{phone_number}`) karena ini jalur kritis nyimpen order — gak boleh gagal/lambat cuma gara-gara APIANDORDER lagi bermasalah.

Tervalidasi live: kirim `order_number`/`member_id` palsu di payload (`"SHOULD_BE_IGNORED"` dan `999`) — server tetap generate `order_number` baru sendiri dan resolve `member_id` yang bener dari `customer_phone_number` (bukan nilai yang dikirim). Kirim `customer_phone_number: ""` → `member_id` tersimpan `NULL`, order tetap sukses. Data test dibersihin abis verifikasi.

## Edit order (`order_number` diisi)

Buat kasus customer nambah/ubah item **sebelum bayar** (order masih `pending`/`hold`). Payload **sama persis** kayak create, plus `order_number` wajib.

**Cara kerjanya `REPLACE-ALL`** — bukan merge/diff: semua baris `tr_order_detail`+`tr_order_detail_package` lama punya order ini **dihapus**, terus **di-insert ulang full** dari `list_order[]` yang dikirim. Konsekuensinya: **client wajib kirim seluruh isi keranjang tiap kali edit** (item yang gak diubah pun tetep harus ikut dikirim), bukan cuma delta-nya — kalau item lama gak ikut dikirim, dianggap dihapus dari order.

Kenapa replace-all (bukan reuse branch edit `OrderServices::SaveOrder()` yang `merge-by-ulid`, dipakai POS): logic POS itu sengaja preserve item yang **udah keprint ke dapur** (`done_print=true`), gak boleh dihapus asal. Kiosk beda kondisi — print kitchen **selalu ditunda sampai payment sukses** (lihat section di atas), jadi gak ada baris yang perlu dijaga selama order masih `pending`/`hold`. Bonus: gak perlu client tracking `ulid` per item (gak dikirim balik lagi di [KIOSK ORDER DETAIL.md](./KIOSK%20ORDER%20DETAIL.md)), dan gak ada bug field `total` per-item gak ke-update kayak kalau reuse logic POS.

**Item & header dihitung ulang server-side, sama persis create** (2026-11-18) — `editExistingOrder()` manggil `recomputeKioskUnit()` per baris (delegasi `OrderServices::CalculateLineItem()`) buat `net_price`/`dpp`/`tax_value`/`service_charge_amount`/`total` per item, lalu `OrderServices::RecalculateOrderTotals()` buat header (`sub_total`/`total_discount`/`total_tax`/`total_service_charge`/`total_billing`/`total_item`) — field2 itu di request **diabaikan** sama persis "Backend hitung ulang" di atas. `order_fee`/`delivery_cost` tetap FE-trusted, di-passthrough ke `tr_order` (default nilai lama kalau gak dikirim).

Sebelum replace-all dijalanin:

1. **Guard status `pending`/`hold`** — order yang udah `paid`/`cancel`/`expired`/dll ditolak jelas, gak sempat masuk logic edit sama sekali.
2. **Cancel payment attempt pending (kalau ada)** — kalau order ini masih punya QR aktif (`tr_kiosk_payment_request.status = pending`), attempt itu di-cancel dulu ke Midtrans (`PaymentGatewayServices::CancelPendingAttempt()`, reuse yang sama dipakai [KIOSK CANCEL ORDER.md](./KIOSK%20CANCEL%20ORDER.md) & [KIOSK CANCEL PAYMENT.md](./KIOSK%20CANCEL%20PAYMENT.md)) — QR lama itu digenerate buat nominal **lama**, begitu diedit `total_billing` bisa berubah, QR lama jadi gak valid lagi. Customer perlu manggil [KIOSK PAYMENT REQUEST.md](./KIOSK%20PAYMENT%20REQUEST.md) lagi abis edit buat dapet QR baru sesuai nominal terbaru.
   - **Race guard**: `CancelPendingAttempt()` live-check ke Midtrans dulu sebelum cancel — kalau ternyata attempt-nya udah `settlement` (customer sempet bayar PERSIS pas mau diedit), edit-nya **dibatalin** (`"order sudah dibayar, tidak bisa diedit lagi"`), order jadi `paid`, item/nominal yang udah dibayar gak ketimpa isi edit yang baru. Detail lengkap di [KIOSK CANCEL PAYMENT.md](./KIOSK%20CANCEL%20PAYMENT.md).

### Contoh request

Order awalnya 1 item, diedit jadi 2 item (qty item pertama berubah 1→2, plus 1 item paket baru) — **seluruh isi keranjang dikirim ulang**, bukan cuma yang berubah:

```json
POST /api/kiosk/save-order
Content-Type: application/json

{
  "order_number": "NO4TB20260813121339",
  "order_name": "ReplaceAllTest-Edited",
  "order_pax": 2,
  "total_item": 3,
  "sub_total": "30000.00",
  "total_tax": "3300.00",
  "total_service_charge": "0.00",
  "total_billing": "33300.00",
  "total_discount": "0.00",
  "order_fee": "0.00",
  "delivery_cost": "0.00",
  "list_order": [
    {
      "menu_pricelist_id": 82,
      "menu_id": 97,
      "qty": 2,
      "flag_inclusive_tax": 1,
      "price": "10000.00",
      "tax_id": 100,
      "tax_type": "vat",
      "service_charge_id": 16,
      "discount_rate": "0.00",
      "discount_value": "0.00",
      "net_price": "20000.00",
      "total": "20000.00",
      "notes": null,
      "menu_package_list": []
    },
    {
      "menu_pricelist_id": 62,
      "menu_id": 72,
      "qty": 1,
      "flag_inclusive_tax": 1,
      "price": "10000.00",
      "tax_id": null,
      "tax_type": "",
      "service_charge_id": 16,
      "discount_rate": "0.00",
      "discount_value": "0.00",
      "net_price": "10000.00",
      "total": "10000.00",
      "notes": "item paket baru",
      "menu_package_list": [
        {
          "menu_package_id": 16,
          "menu_id": 68,
          "qty": 1,
          "flag_inclusive_tax": 1,
          "price": "6000.00",
          "tax_id": null,
          "tax_type": "",
          "service_charge_id": 16,
          "discount_rate": "0.00",
          "discount_value": "0.00",
          "total": "6000.00",
          "notes": null
        },
        {
          "menu_package_id": 17,
          "menu_id": 69,
          "qty": 1,
          "flag_inclusive_tax": 1,
          "price": "8000.00",
          "tax_id": null,
          "tax_type": "",
          "service_charge_id": 16,
          "discount_rate": "0.00",
          "discount_value": "0.00",
          "total": "8000.00",
          "notes": null
        }
      ]
    }
  ]
}
```

**Field yang gak dipakai buat edit** (dikirim juga gak apa-apa, diabaikan): `terminal_id`, `visit_purpose_id`, `price_list_id` — `table_section_id`/`visit_purpose_id` order gak berubah abis dibuat. `sub_total`/`total_tax`/`total_service_charge`/`total_billing`/`total_discount`/`total_item` juga diabaikan (dihitung ulang server-side, lihat di atas). `customer_phone_number` **tetap dipakai** (beda dari field2 di atas) — ikut di-update ke `tr_order`, dan `member_id` di-re-derive ulang darinya (kalau mau ganti nomor HP, kirim yang baru; kalau gak dikirim/dikosongin, `customer_phone_number`/`member_id` jadi `null`). `order_fee`/`delivery_cost` **tetap FE-trusted** — kirim nilai baru kalau mau diubah, gak dikirim = tetap pakai nilai lama.

### Response

Sukses (sama format kayak create):

```json
{ "code": 0, "data": { "order_number": "NO4TB20260813121339" } }
```

`order_number` gak ketemu:

```json
{ "code": 100, "message": "order tidak ditemukan" }
```

Order udah bukan `pending`/`hold`:

```json
{ "code": 100, "message": "order sudah tidak bisa diedit (bukan status pending/hold)" }
```

Race — attempt payment-nya ternyata udah `settlement` pas mau diedit (lihat "Race guard" di atas):

```json
{ "code": 100, "message": "order sudah dibayar, tidak bisa diedit lagi" }
```

### Known gap khusus edit (di luar scope, sengaja gak digarap)

**Validasi/potong stok (`mr_item.stok_qty`) di-skip total pas edit** — beda dari create yang selalu cek & potong stok. Alasannya: kalau tetep dilakuin buat SEMUA item di `list_order[]` (termasuk yang sebenernya udah ada dari sebelum diedit), stok bakal **double-potong** tiap kali order diedit ulang (item lama udah pernah motong stok pas create, potong lagi pas edit = salah). Fix yang bener butuh hitung delta qty (lama vs baru) yang lebih rumit dari scope sekarang — apalagi `OrderServices::CancelOrder()` sendiri **juga belum ngembaliin stok** pas order dibatalin, jadi tracking stok emang udah gak fully-consistent di flow ini secara keseluruhan. Gak diperbaiki di sini, tapi juga sengaja gak diperparah (mending skip daripada double-potong).

## Known gap (belum digarap, sengaja ditunda)

`OrderlistServices::getOrderList()` (dipakai dashboard kasir buat nampilin antrian order **takeaway**) filter query-nya `WHERE order_source = 'pos'` — order dengan `order_source = 'kiosk'` **gak bakal muncul** di layar antrian kasir walau udah kesimpen & (abis dibayar) ke-print di dapur. Ini disadari pas audit, **sengaja ditunda dulu** (bukan lupa) — perlu diputusin nanti gimana staff mau lihat/tracking order Kiosk (fix filter-nya, atau bikin tampilan antrian terpisah khusus Kiosk).

## Tervalidasi live (2026-08-13) — edit order (`order_number` diisi)

- Order dibuat (1 item) → diedit kirim `order_number` + 2 item baru (1 item biasa qty 2, 1 item paket dengan 2 sub-item) → `items[]` hasil replace-all sesuai persis yang dikirim (item lama gak nyisa, gak dobel), `total` per-item **akurat** ngikutin qty baru, header (`order_name`/`order_pax`/`sub_total`/`total_tax`/`total_billing`/`total_item`) semua ke-update.
- `order_number` gak ketemu → `"order tidak ditemukan"`.
- Order yang statusnya `cancel` → ditolak `"order sudah tidak bisa diedit..."`.
- Order dengan QR `pending` aktif (`payment/request` dipanggil dulu) → diedit → attempt itu dicek langsung `GET /payment-gateway/{order_id}` ke service `payment`, hasilnya beneran `status: cancel` di Midtrans (bukan cuma klaim lokal).
- Create (`order_number` kosong) dites ulang abis perubahan ini → tetap jalan normal, gak kepengaruh.

## Status

**Diselaraskan dengan POS (2026-11-18)**: `recomputeKioskUnit()` dirombak jadi delegasi langsung ke `OrderServices::CalculateLineItem()` (dipakai bareng `SaveOrder()`, 1 sumber kebenaran) — nama field hasil kalkulasi internal ikut rename (`net_price`/`dpp`, sebelumnya `dpp`/`net_dpp`), tambah `service_charge_id`/`service_charge_rate`/`service_charge_amount` di item & sub-item package (sebelumnya gak ada sama sekali). `editExistingOrder()` juga sekarang manggil `OrderServices::RecalculateOrderTotals()` buat header, konsisten sama create — sebelumnya header edit cuma percaya apa adanya dari request FE. Kontrak **nama field request/response gak berubah** (tetap `tax_value`/`dpp`/`total` dst, lihat "Field duit/rate/amount"), cuma `service_charge_id`/`service_charge_rate` yang BARU ditambahkan.

**Tervalidasi live end-to-end (2026-11-18)**: dites via `curl` ke POS Laravel lokal (`php artisan serve --port=8098`), pakai data test sementara (tax rate service charge diset 5%, 1 visit purpose & 1 terminal diaktifkan buat kiosk, dibalikin lagi abis test):

- **Create**: `price=30000` (inclusive), `taxRate=10%`, `serviceChargeRate=5%`, qty 2, tanpa diskon → `net_price=26086.96`, `dpp=26086.96` (sama karena diskon 0), `tax_amount=2608.70`, `service_charge_amount=1304.35` — semua cocok hitungan manual `30000/1.15=26086.96` dst. Header: `sub_total=52173.92`, `total_tax=5217.40`, `total_service_charge=2608.70`, `total_billing=60000.00` (2×qty, semua konsisten).
- **Edit**: order yang sama diedit — qty jadi 3, `discount_rate=10%` dikirim bareng `discount_value=999999.00` (sentinel sengaja salah) → backend **mengabaikan** `discount_value` dan hitung ulang `discountAmount=10%×netPrice=2608.70` (BUKAN dari sentinel), `dpp=23478.26`, `tax_amount=2347.83`, `service_charge_amount=1173.91`. Header ikut ter-recalculate: `sub_total=78260.88`, `total_discount=7826.10`, `total_tax=7043.49`, `total_service_charge=3521.73`. `order_fee="2000.00"`/`delivery_cost="3000.00"` yang dikirim **ter-passthrough persis** (FE-trusted, gak diabaikan) → `total_billing=86000.00` (`81000` dari item + `2000+3000`).
- Data test (order + master data yang diubah sementara) dibersihkan abis verifikasi.

**`tax_rate`/`service_charge_rate` resolve server-side dari `mr_tax` (2026-11-18, LANJUTAN)**: `recomputeKioskUnit()` diupdate — `tax_rate`/`service_charge_rate` yang dikirim client **tidak lagi dipakai**, diganti hasil `OrderServices::ResolveTaxRate($tax_id)`/`ResolveTaxRate($service_charge_id)` (fungsi yang sama dipakai POS, dibuat `public`). **Tervalidasi live (2 skenario via curl)**: (1) create order kirim `tax_rate="999.00"`/`service_charge_rate="999.00"` (sentinel sengaja salah) dengan `tax_id=10` (rate asli 10%)/`service_charge_id=16` (rate diset 5% sementara buat test) → kolom DB tersimpan `tax_rate=10.00`/`service_charge_rate=5.00` (bukan `999`), seluruh hasil kalkulasi (`tax_amount=2608.70`, `service_charge_amount=1304.35`, `dpp=26086.96`) cocok hitungan manual pakai rate asli; (2) kirim `tax_id=999999` (gak ada di `mr_tax`) → `{"code":100,"message":"tax/service charge dengan id 999999 tidak ditemukan"}`, order gagal tersimpan. Data test & master data yang diubah sementara dikembalikan ke semula setelah verifikasi.
