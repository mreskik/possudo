# Category & Subcategory

## Category List

```
GET /api/master/category-list
```

Response:
```json
{ "code": 0, "data": [ { "id": 39, "name": "MENU FOOD" } ] }
```

`CategoryModel::get()` apa adanya, tanpa filter aktif/tidak.

## Subcategory List

```
GET /api/master/subcategory-list
```

Response:
```json
{ "code": 0, "data": [ { "id": 12, "category_id": 39, "name": "FOOD" } ] }
```

`SubCategoryModel::get()` apa adanya, semua subcategory lintas kategori sekaligus (bukan per-`category_id`, filter dilakukan di FE).

## Alur Implementasi

Dipanggil bareng (`useCategory.js`/`useSubCategory.js`) buat filter tampilan menu di halaman Order — biasanya dipakai sebagai alternatif ringan dari [`MENU LIST.md`](MENU%20LIST.md) kalau cuma butuh daftar nama kategori (mis. buat dropdown filter), bukan pohon menu lengkap dengan harga.

## Sumber

- `app/Http/Controllers/MasterController.php` — `GetCategoryList()`, `GetSubCategoryList()`.
- Frontend: `src/composables/useCategory.js`, `src/composables/useSubCategory.js`.

## Status

Didokumentasikan 2026-10-12, berdasar pembacaan kode langsung (belum ada verifikasi ulang lewat HTTP request live di sesi ini).
