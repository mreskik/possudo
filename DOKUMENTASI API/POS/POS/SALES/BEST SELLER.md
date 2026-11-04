# Best Seller

Endpoint standalone buat liat menu paling laku dalam rentang tanggal tertentu — **BUKAN** bagian dari sistem Report (dayshift report), gak ada hubungan sama `DayShiftServices`/`REPORT AND HISTORY.md`. Ada 3 versi: global, by visit purpose, by table section.

**Yang dihitung**: cuma item utama dari `tr_order_detail` — sub-item di dalam package (`tr_order_detail_package`) **TIDAK** diikutkan (beda dari `sales_by_menu` di Report V2 yang menggabungkan keduanya buat tujuan laporan omzet). Status order yang dihitung cuma `paid` (transaksi closed), bukan `pending`/`hold`. Item yang dibatalkan (`tod.cancel_at IS NOT NULL`) tidak dihitung.

Diurutkan `total_qty DESC` (paling laku di atas) langsung di query database.

## 1. Global

```
GET /api/best-seller/menu?date_start=2026-11-01&date_end=2026-11-04
```

## 2. By Visit Purpose

```
GET /api/best-seller/menu-by-visit-purpose/{visit_purpose_id}?date_start=2026-11-01&date_end=2026-11-04
```

## 3. By Table Section

```
GET /api/best-seller/menu-by-table-section/{tablesection_id}?date_start=2026-11-01&date_end=2026-11-04
```

## Response (sama shape di ketiga versi)

```json
{
  "code": 0,
  "data": [
    {
      "item_id": 139,
      "item_name": "CAPPUCCINO",
      "item_code": "123",
      "item_image": "/img/item/01a03c1a-838f-7020-83d2-b66fec957e6f.jpg",
      "category_id": 39,
      "category_name": "TONAKO - BEVERAGE",
      "subcategory_id": 19,
      "subcategory_name": "BARISTA COFFEE",
      "total_qty": "2",
      "total_sales": "60000.00"
    }
  ]
}
```

(Contoh di atas REAL, dari data dev lewat `GET /api/best-seller/menu?date_start=2020-01-01&date_end=2030-01-01`.)

- `item_id`/`item_name`/`item_code`/`item_image` — resolve dari `mr_item` (via JOIN `mr_item_conv` ke `tr_order_detail.menu_id`, lihat catatan penamaan di bawah).
- `category_name`/`subcategory_name` — `LEFT JOIN` ke `mr_category`/`mr_subcategory`, `null` kalau item gak punya kategori/subkategori.
- `total_qty`/`total_sales` — `SUM(tod.qty)` / `SUM(tod.total)`, digabung per item (`GROUP BY mi.id`) lintas semua order yang match filter.
- Rentang tanggal tanpa hasil, atau `visit_purpose_id`/`tablesection_id` yang gak ada order cocok → `"data": []` (bukan error), dikonfirmasi lewat test live.

## Catatan penamaan `item_id` vs `menu_id`

`tr_order_detail.menu_id` itu secara data isinya **`mr_item_conv.id`**, bukan `mr_item.id` langsung (lihat catatan penamaan panjang di `MenuServices.php`: "tidak ada tabel `mr_menu`", semua yang disebut "menu" itu alias `mr_item_conv`). Field `item_id` di response endpoint ini SENGAJA diambil dari `mr_item.id` (hasil JOIN `mr_item_conv.item_id`), supaya gak ambigu — dipakai nama `item_id`, bukan `menu_id`, biar jelas ini ID item asli, bukan ID item_conv.

## Query inti

```sql
SELECT
  mi.id as item_id,
  mi.name as item_name,
  mi.code as item_code,
  mi.image as item_image,
  mi.category_id,
  mcc.name as category_name,
  mi.subcategory_id,
  mscc.name as subcategory_name,
  SUM(tod.qty) as total_qty,
  SUM(tod.total) as total_sales
FROM tr_order_detail tod
JOIN tr_order tro ON tro.order_number = tod.order_number
JOIN mr_item_conv mic ON mic.id = tod.menu_id
JOIN mr_item mi ON mi.id = mic.item_id
LEFT JOIN mr_category mcc ON mcc.id = mi.category_id
LEFT JOIN mr_subcategory mscc ON mscc.id = mi.subcategory_id
WHERE tod.cancel_at IS NULL
  AND tro.status = 'paid'
  AND tro.order_date BETWEEN ? AND ?
  -- AND tro.visit_purpose_id = ?      (versi by visit purpose)
  -- AND tro.table_section_id = ?      (versi by table section)
GROUP BY mi.id
ORDER BY total_qty DESC
```

## branch_id

Gak ada filter/parameter `branch_id` — pola yang sama dengan endpoint `orderlist` lain, karena POS adalah instalasi lokal per cabang (`mr_branch` lokal cuma 1 baris), jadi seluruh data di DB itu otomatis sudah scoped ke 1 cabang.

## Sumber

- `app/Services/BestSellerServices.php` — `getBestSellerMenu()`, `getBestSellerMenuByVisitPurpose()`, `getBestSellerMenuByTableSection()`.
- `app/Http/Controllers/BestSellerController.php` — `getBestSellerMenu()`, `getBestSellerMenuByVisitPurpose()`, `getBestSellerMenuByTableSection()`.
- `routes/api.php` — group `best-seller`.

## Status

**Tervalidasi live (2026-11-04)**: dites via `curl` ke POS Laravel lokal (`php artisan serve --port=8098`).
- `GET best-seller/menu?date_start=2020-01-01&date_end=2030-01-01` → 1 item real (CAPPUCCINO, qty 2) muncul benar, semua field terisi.
- `GET best-seller/menu-by-visit-purpose/2?...` (visit_purpose_id dari order paid yang ada) → hasil sama, filter visit_purpose bekerja.
- `GET best-seller/menu-by-table-section/18?...` (table_section_id dari order paid yang ada) → hasil sama, filter table_section bekerja.
- `GET best-seller/menu-by-table-section/999?...` (table_section_id gak ada datanya) → `"data": []`, tidak error.
- `GET best-seller/menu?date_start=2099-01-01&date_end=2099-01-02` (rentang tanggal tanpa order) → `"data": []`, tidak error.
- `php -l` bersih di 3 file yang dibuat/diedit.
