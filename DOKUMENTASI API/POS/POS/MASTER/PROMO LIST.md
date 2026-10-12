# Promo List

```
GET /api/master/promo-list/{visit_purpose_id}
```

Promo yang **eligible** buat 1 visit purpose saat ini (barrier struktural: aktif, periode, visit purpose, member type, tier, hari, jam, channel `pos`) — **bukan endpoint apply promo ke order**, itu bagian terpisah di alur Save Order (lihat [`../ORDER/SAVE ORDER.md`](../ORDER/SAVE%20ORDER.md)).

## Request

`{visit_purpose_id}` dari path. Query opsional:
- `member_type_id` — **nullable**, dikirim `null`/tidak dikirim dari halaman Order (member belum diketahui — baru dipilih saat pembayaran). Passing `null` **disengaja**, bukan bug: `type_member_id = NULL` tidak pernah match di SQL, jadi otomatis fallback ke promo yang `flag_all_type_members = true` saja. Halaman Payment (kalau sudah di-wire) bisa panggil ulang dengan `member_type_id` asli untuk memunculkan promo yang dibatasi tipe member tertentu.
- `tier_level` — sama semantiknya seperti `member_type_id` di atas (nullable, fallback ke `flag_all_tiers = true`).

## Response

```json
{
  "code": 0,
  "data": [
    {
      "id": 23,
      "name": "Diskon 20%",
      "code": "DISC20",
      "type": "percent",
      "type_rupiah_amount": "0.00",
      "type_percent_rate": "20.00",
      "type_percent_limit_amount": "50000.00",
      "type_freeitem_item_id": null,
      "promo_for": "category",
      "min_buy_amount": "0.00",
      "flag_include_package": true,
      "apply_limit_per_day": 10,
      "apply_limit_per_item": null,
      "period_start": null,
      "period_end": null,
      "targetIds": [39, 40],
      "used_today": 2
    }
  ]
}
```

- `targetIds` — isinya `category_id`/`sub_category_id`/`item_id` tergantung `promo_for` (resolve dari `mr_promo_categories`/`mr_promo_sub_categories`/`mr_promo_items`) — menentukan item/kategori mana di cart yang eligible untuk promo ini.
- `used_today` — jumlah order **distinct** hari ini yang sudah pakai promo ini (status `pending`/`hold`/`paid`, item belum dibatalkan) — dipakai FE untuk enforce `apply_limit_per_day` di sisi tampilan (bukan validasi final — validasi final tetap di server saat Save Order).
- **8 barrier struktural** yang dicek (semua harus lolos): `is_active`, `period_start`/`period_end` (periode berlaku), visit purpose (`flag_all_visit_purposes` atau eksplisit match), member type (`flag_all_type_members` atau match), tier (`flag_all_tiers` atau match), hari (`flag_all_days` atau match hari ini), jam (`flag_all_times` atau jam sekarang dalam rentang), channel (`flag_apply_to_all` atau eksplisit `apply_to = 'pos'`).
- Tidak ada promo yang eligible → `data: []`, bukan error.

## Alur Implementasi

1. Dipanggil saat halaman Order dibuka (`getPromoList(visit_purpose_id)`, **tanpa** `member_type_id` — member belum dipilih di titik ini) — hasil ditampilkan sebagai daftar promo yang bisa dipilih kasir untuk di-apply ke order.
2. Promo yang dipilih kasir dikirim sebagai bagian body `save-order`/`order/calculate` (field persis ada di [`../ORDER/SAVE ORDER.md`](../ORDER/SAVE%20ORDER.md)) — endpoint List ini **tidak** melakukan validasi final (cuma menyaring mana yang SECARA STRUKTURAL eligible untuk ditampilkan), validasi lengkap (termasuk cek `targetIds` match ke item di cart, `min_buy_amount`, limit harian) terjadi di server saat order benar-benar disimpan.
3. Kalau alur UI punya titik "pilih member dulu, baru promo" — panggil ulang endpoint ini dengan `member_type_id`/`tier_level` terisi untuk memunculkan promo tambahan yang tadinya tersembunyi.

## Sumber

- `app/Http/Controllers/MasterController.php` — `GetPromoList()`, `AttachPromoTargetIds()`, `AttachUsedToday()` (keduanya private).
- Frontend: `src/services/MasterServices.ts` (`getPromoList`).

## Status

Didokumentasikan 2026-10-12, berdasar pembacaan kode langsung (belum ada verifikasi ulang lewat HTTP request live di sesi ini). Alur apply/validasi final promo ke order akan ditelusuri lebih dalam saat menulis [`../ORDER/SAVE ORDER.md`](../ORDER/SAVE%20ORDER.md).
