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

Field yang termasuk kelompok ini: `sub_total`, `total_tax`, `total_billing`, `total_discount` (level order), dan per item di `list_order[]`/`menu_package_list[]`: `price`, `tax_rate`, `tax_value`, `discount_rate`, `discount_value`, `after_discount`, `dpp`, `total`.

## ⚠️ Backend hitung ulang tax_value/dpp/total server-side (2026-08-20)

Field **nama**-nya gak berubah (kontrak request ini sengaja dipertahankan apa adanya), tapi sejak
restrukturisasi kolom harga/diskon/pajak, backend **gak lagi percaya mentah-mentah** field
turunan yang dikirim Kiosk — dihitung ulang sendiri lewat `KioskController::recomputeKioskUnit()`
per baris `list_order[]`/`menu_package_list[]`:

- **`tax_value`, `after_discount`, `dpp`, `total` yang dikirim client DIABAIKAN** — backend
  hitung ulang dari `price`/`tax_rate`/`flag_inclusive_tax`/`discount_rate`/`discount_value`.
  Boleh tetap dikirim (gak dibaca), atau boleh dihilangkan dari payload — sama-sama gak masalah.
- **`discount_value` cuma dipercaya kalau `discount_rate == 0`** (promo nominal rupiah / gak ada
  promo). Kalau `discount_rate > 0` (promo persen), `discount_value` yang dikirim **diabaikan**,
  backend hitung ulang dari basis DPP (`discount_rate% × dpp`, bukan dari `price` gross).
- Urutan hitung ulangnya: pajak dilepas dulu dari `price` → `dpp`, **baru** diskon dipotong dari
  `dpp` (bukan dari `price` gross) → `net_dpp`, baru pajak final dihitung dari `net_dpp` →
  `tax_value` (nama kolom DB-nya `tax_amount`, response tetap kayak biasa). `total` per baris
  yang beneran kesimpen = `qty × (net_dpp + tax_value)`.

Alasannya: app Kiosk (repo terpisah, di luar `posv1-laravel`) belum tentu ikut diupdate ke rumus
baru bareng — backend jadi otoritas terakhir buat angka finansial, gak nelen mentah-mentah hasil
hitungan client manapun. Detail lengkap logic & simulasi angka:
[PERHITUNGAN PAJAK INCLUSIVE & DISKON.md](../../../posv1-vue/DOKUMENTASI/PERHITUNGAN%20PAJAK%20INCLUSIVE%20%26%20DISKON.md)
(`posv1-vue`).

Field angka biasa (**tetap number**, bukan uang/rate): `terminal_id`, `visit_purpose_id`, `price_list_id`, `member_id`, `order_pax`, `total_item`, `qty`, `tax_id`, `promo_id`, `menu_pricelist_id`, `menu_id`, `menu_package_id` — ini id/quantity, bukan nominal.

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
  "total_billing": "22500.00",
  "total_discount": "0.00",
  "list_order": [
    {
      "menu_pricelist_id": 49,
      "menu_id": 69,
      "qty": 1,
      "flag_inclusive_tax": 1,
      "price": "14500.00",
      "tax_id": null,
      "tax_type": "",
      "tax_rate": "0.00",
      "tax_value": "0.00",
      "promo_id": null,
      "is_free_item_promo": false,
      "discount_rate": "0.00",
      "discount_value": "0.00",
      "after_discount": "14500.00",
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
      "tax_rate": "0.00",
      "tax_value": "0.00",
      "promo_id": null,
      "is_free_item_promo": false,
      "discount_rate": "0.00",
      "discount_value": "0.00",
      "after_discount": "8000.00",
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
          "tax_rate": "0.00",
          "tax_value": "0.00",
          "promo_id": null,
          "discount_rate": "0.00",
          "discount_value": "0.00",
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

## Field payload

| Field | Isi |
| --- | --- |
| `terminal_id` | id terminal Kiosk (dari [KIOSK TERMINAL DETAIL.md](./KIOSK%20TERMINAL%20DETAIL.md)) — **wajib**, dipakai buat resolve `table_section_id` |
| `order_name` | nama/label order (bebas, misal nomor antrian atau nama customer) |
| `customer_phone_number` | opsional — kalau diisi, dipakai server buat cari `member_id` (lihat poin 4 di atas). Kosongin (`""`) atau jangan dikirim kalau customer gak mau/gak punya nomor. |
| `visit_purpose_id` | dari [KIOSK BRANCH VISIT PURPOSE.md](./KIOSK%20BRANCH%20VISIT%20PURPOSE.md) (`visit_purpose_id`, bukan `id`) |
| `price_list_id` | `menu_pricelist_id` dari [KIOSK BRANCH VISIT PURPOSE DETAIL.md](./KIOSK%20BRANCH%20VISIT%20PURPOSE%20DETAIL.md) |
| `order_pax`, `total_item` | dihitung di frontend dari isi keranjang (number, bukan string) |
| `sub_total`, `total_tax`, `total_billing`, `total_discount` | dihitung di frontend dari isi keranjang — **string** (lihat bagian "Field duit/rate/amount" di atas) |
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
| `tax_id` | `tax_id` |
| `tax_type` | `tax_type` |
| `tax_rate` | `tax_rate` |
| — (dihitung di frontend, tapi **diabaikan backend** — lihat "Backend hitung ulang" di atas) | `tax_value` |
| — (kosong kalau gak ada promo) | `promo_id`, `discount_rate`, `discount_value` (`discount_value` cuma dipercaya kalau `discount_rate == 0`) |
| — | `is_free_item_promo` (opsional, default `false` kalau gak dikirim) |
| — (dihitung di frontend, tapi **diabaikan backend**) | `total` |
| — (opsional dari user) | `notes` |
| `package_list[]` (kalau ada) | `menu_package_list[]` — boleh dikosongin `[]` kalau item gak punya package, server yang isi default kalau field ini gak ada sama sekali |

`menu_package_list[]` per baris (kalau item punya package terpilih) field-nya sama kayak `menu_package_list[]` di respons detail (`menu_package_id`, `menu_id`, `qty`, `flag_inclusive_tax`, `price`, `tax_id`, `tax_type`, `tax_rate`, `tax_value`, `promo_id`, `discount_rate`, `discount_value`, `total`, `notes`).

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
  "total_billing": "33300.00",
  "total_discount": "0.00",
  "list_order": [
    {
      "menu_pricelist_id": 82,
      "menu_id": 97,
      "qty": 2,
      "flag_inclusive_tax": 1,
      "price": "10000.00",
      "tax_id": 100,
      "tax_type": "vat",
      "tax_rate": "11.00",
      "tax_value": "2200.00",
      "discount_rate": "0.00",
      "discount_value": "0.00",
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
      "tax_rate": "0.00",
      "tax_value": "0.00",
      "discount_rate": "0.00",
      "discount_value": "0.00",
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
          "tax_rate": "0.00",
          "tax_value": "0.00",
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
          "tax_rate": "0.00",
          "tax_value": "0.00",
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

**Field yang gak dipakai buat edit** (dikirim juga gak apa-apa, diabaikan): `terminal_id`, `visit_purpose_id`, `price_list_id` — `table_section_id`/`visit_purpose_id` order gak berubah abis dibuat. `customer_phone_number` **tetap dipakai** (beda dari 3 field di atas) — ikut di-update ke `tr_order`, dan `member_id` di-re-derive ulang darinya (kalau mau ganti nomor HP, kirim yang baru; kalau gak dikirim/dikosongin, `customer_phone_number`/`member_id` jadi `null`).

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
