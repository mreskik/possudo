# Kiosk Save Order

```
POST /api/kiosk/save-order
```

Wrapper tipis di `KioskController::SaveOrder()` — **manggil `OrderServices::SaveOrder()` yang sama persis dipakai POS**, gak ada logic order baru ditulis ulang. Payload masuk **`snake_case`** (konsisten sama endpoint Kiosk lain) — di-convert ke `camelCase` internal lewat `mapKioskOrderPayload()` sebelum diteruskan ke `OrderServices::SaveOrder()` (yang gak diubah, masih dipakai bareng POS yang `camelCase`).

Selain convert case, ada 4 hal yang di-resolve/dipaksa di controller, gak dipercaya dari client:

1. **`order_source`** — di-hardcode `'kiosk'` (client gak bisa ngirim/override ini).
2. **`table_section_id`** — diambil dari `mr_terminal.table_section_id` berdasarkan `terminal_id` yang dikirim (device Kiosk pra-dikonfigurasi ke 1 table section tetap, user gak milih meja). Kalau `mr_terminal.table_section_id` kosong (`NULL`), request ditolak: `{"code":100,"message":"terminal ini belum dikonfigurasi table_section_id, hubungi admin"}` — **setiap terminal Kiosk wajib di-set `table_section_id`-nya dulu** sebelum bisa dipakai order beneran.
3. **`order_number`** — dipaksa kosong (`''`), gak peduli apa yang dikirim client (kalaupun dikirim, diabaikan). Kiosk gak pernah edit order existing (beda dari POS yang bisa hold/reopen order lewat `order_number`), jadi selalu diperlakukan sebagai order baru. **Field ini gak perlu dikirim lagi** — dihapus dari payload (lihat "Update (2026-08-12)" di bawah).
4. **`member_id`** — **gak dipercaya dari client sama sekali**, di-derive server-side dari `customer_phone_number`. Kalau `customer_phone_number` ada isinya, di-lookup ke `mr_member` **lokal** (bukan live ke ERP — beda dari `GET /api/kiosk/member/check/{phone_number}` yang live, di sini sengaja pakai data lokal biar nyimpen order gak gantung koneksi ke ERP). Nomor kosong/null atau gak ketemu member → `member_id` tetap `null`, order tetap kesimpen (jadi member bukan syarat wajib). **Field `member_id` gak perlu dikirim lagi** — dihapus dari payload.

## Field duit/rate/amount — kirim sebagai string

Semua field yang berhubungan sama uang, rate (persen pajak/diskon), atau nominal — **kirim sebagai string**, bukan number JSON mentah (`"14500.00"`, bukan `14500`). Ini standar buat endpoint Kiosk (baru, jadi bisa langsung distandarin dari awal) — beda dari POS yang masih campuran number/string di berbagai tempat (belum dirapiin, rencana nyusul belakangan, di luar scope Kiosk ini). Tervalidasi kirim string tetep kesimpen bener sebagai `decimal` di DB (MySQL/PDO gak masalah nerima string numerik).

Field yang termasuk kelompok ini: `sub_total`, `total_tax`, `total_billing`, `total_discount` (level order), dan per item di `list_order[]`/`menu_package_list[]`: `price`, `tax_rate`, `tax_value`, `discount_rate`, `discount_value`, `after_discount`, `dpp`, `total`.

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

**Jangan kirim `order_source`/`table_section_id`/`order_number`/`member_id`** — kalaupun dikirim, bakal diabaikan/ketimpa server (lihat poin 1-4 di atas).

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
| — (dihitung di frontend: `menu_price * tax_rate / 100`) | `tax_value` |
| — (kosong kalau gak ada promo) | `promo_id`, `discount_rate`, `discount_value` |
| — | `is_free_item_promo` (opsional, default `false` kalau gak dikirim) |
| — (dihitung) | `total` |
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

## Known gap (belum digarap, sengaja ditunda)

`OrderlistServices::getOrderList()` (dipakai dashboard kasir buat nampilin antrian order **takeaway**) filter query-nya `WHERE order_source = 'pos'` — order dengan `order_source = 'kiosk'` **gak bakal muncul** di layar antrian kasir walau udah kesimpen & (abis dibayar) ke-print di dapur. Ini disadari pas audit, **sengaja ditunda dulu** (bukan lupa) — perlu diputusin nanti gimana staff mau lihat/tracking order Kiosk (fix filter-nya, atau bikin tampilan antrian terpisah khusus Kiosk).
