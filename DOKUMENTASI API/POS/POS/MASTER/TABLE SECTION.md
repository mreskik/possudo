# Table Section

## Get Table Section

```
GET /api/master/table-section
```

Daftar table section yang aktif.

Response:
```json
{
  "code": 0,
  "data": [
    {
      "id": 1,
      "branch_id": 1,
      "name": "Indoor",
      "tablechecker_station_id": 2,
      "mainchecker_station_id": 1,
      "print_category_setting_link": null,
      "layout_height": 600,
      "layout_width": 800,
      "layout_x": 0,
      "layout_y": 0,
      "layout_image_src": "/img/table-section/019f6a10-d2a1-71b3-bcfa-7c54d6f639bf.jpg",
      "is_active": true,
      "can_hold": true,
      "type": "dinein",
      "tables": [
        {
          "id": 10,
          "table_section_id": 1,
          "name": "Meja 1",
          "type": "square",
          "height": 80,
          "width": 80,
          "pos_x": 100,
          "pos_y": 120,
          "table_seat": 4,
          "minimum_billing": "50000.00",
          "available_for_book": true
        }
      ]
    }
  ]
}
```

- `TableSectionModel::where('is_active', true)->get()` — apa adanya, tanpa transformasi (`$guarded = []`, SEMUA kolom tabel `mr_table_section` ikut terkirim, bukan cuma `id`/`name`/`is_active`).
- **`tables` (2026-10-28, BARU)** — daftar meja (`mr_table`) milik section itu, disertakan langsung nested di tiap object section. Diambil lewat **1 query** untuk semua section sekaligus (`TableModel::whereIn('table_section_id', ...)`, dikelompokkan di PHP pakai `groupBy()`) — bukan N+1 per section. Section tanpa meja sama sekali → `tables: []` (array kosong, bukan `null`). Ditambahkan supaya FE gak perlu panggil `getTableSectionTable()` terpisah lagi — endpoint itu sekarang **DEPRECATED** (lihat bagian di bawah), **TIDAK dihapus/diubah** kodenya, tetap ada buat konsumer lama yang belum migrasi ke `tables[]` di sini.
- **`layout_x`/`layout_y` (2026-10-28, BARU)** — posisi/offset kanvas layout, sinkron dari `master_table_section.layout_x/layout_y` di ERP (lihat `MASTER TABLE SECTION.md` sudocore2). Kolom lokal `mr_table_section.layout_x`/`layout_y` ditambah lewat migration `2026_10_28_000000_add_layout_x_y_to_mr_table_section.php`.
- **`layout_image_src` (2026-10-28, diperbaiki)** — sekarang berisi **path lokal** hasil download (`/img/table-section/...`), bukan path mentah dari ERP. Sebelumnya field ini cuma ikut di-upsert sebagai string mentah tanpa pernah benar-benar punya file gambarnya di lokal — diperbaiki di `SetupServices::getTableSectionList()` (download via `downloadImage()`, subdir `table-section`, pola sama item/branch/master-image). Kalau field ini `null`/kosong, berarti table section itu memang belum ada gambar layout, bukan gagal sync.
- **Catatan bug kecil**: `catch` block di controller ini balikin `'code' => 0` (bukan `100`) saat terjadi exception — error PHP akan muncul sebagai response "sukses" dengan `data` kosong/null, bukan error eksplisit. Perlu ditangani benar di implementasi FE baru (jangan asumsikan `code === 0` berarti `data` valid tanpa cek lebih lanjut).

## Get Table Section Table (DEPRECATED — jangan pakai)

**DEPRECATED (2026-10-28)** — jangan dipakai lagi. `GET /api/master/table-section` sekarang sudah menyertakan `tables[]` nested langsung per section, jadi endpoint ini gak perlu dipanggil terpisah lagi. Endpoint ini **TIDAK dihapus** (tetap ada buat konsumer lama yang belum migrasi), tapi implementasi FE baru harus pakai `tables[]` dari `getTableSection()` saja.

```
GET /api/master/table-section-table
```

Semua meja (`mr_table`), **dikelompokkan per table_section_id** di level backend (`groupBy()` Laravel collection) — bukan flat array.

Response:
```json
{
  "code": 0,
  "data": {
    "1": [
      { "id": 10, "table_section_id": 1, "name": "Meja 1" },
      { "id": 11, "table_section_id": 1, "name": "Meja 2" }
    ],
    "2": [
      { "id": 20, "table_section_id": 2, "name": "Meja A" }
    ]
  }
}
```

- `data` adalah **object** (key = `table_section_id` sebagai string), bukan array — konsekuensi dari `groupBy()` Laravel yang di-serialize ke JSON.
- Sama bug `code => 0` di `catch` block seperti endpoint di atas.

## Alur Implementasi

**Implementasi lama** (dipertahankan untuk konsumer yang belum migrasi): dipanggil bersamaan saat halaman List Table dimuat (`getTableSection()` + `getTableSectionTable()`, `MasterServices.ts`) — section dipakai sebagai tab/filter, lalu meja di tiap section diambil dari object hasil `table-section-table` pakai key `section.id`.

**Implementasi baru (2026-10-28, disarankan)**: cukup panggil `getTableSection()` saja — meja tiap section sudah tersedia langsung di `tables[]`, tidak perlu panggil `getTableSectionTable()` lagi.

## Sumber

- `app/Http/Controllers/MasterController.php` — `getTableSection()`, `getTableSectionTable()`.
- Frontend: `src/services/MasterServices.ts`, `src/modules/dashboard/list_table/listTablePage.vue`.
- Data `mr_table_section` lokal diisi lewat sync dari ERP (sudocore2), bukan input langsung di POS — lihat `app/Services/SetupServices.php::getTableSectionList()` dan `app/Http/Controllers/SyncController.php::getTableSectionList()` (`GET /api/sync_pull/get_tablesection_list`), jembatan APIANDORDER `/pos/sync/get_tablesection_list/:branch_id`.

## Status

Didokumentasikan 2026-10-12, berdasar pembacaan kode langsung. Diupdate 2026-10-28:
- Field `layout_x`/`layout_y` baru + fix download `layout_image_src` — perubahan sync-nya sudah tervalidasi live (lihat `MASTER TABLE SECTION.md` di sudocore2, bagian Catatan Teknis). Field baru ini otomatis ikut di `getTableSection()` karena `$guarded = []`, bukan hasil perubahan kode di endpoint ini.
- Nested `tables` di `getTableSection()` — perubahan kode nyata di endpoint ini, **tervalidasi live** (`curl http://localhost:8000/api/master/table-section`): tiap section balikin `tables[]` sesuai meja yang benar-benar ada di `mr_table`, section tanpa meja dapat array kosong.
