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
    { "id": 1, "name": "Indoor", "is_active": true }
  ]
}
```

- `TableSectionModel::where('is_active', true)->get()` — apa adanya, tanpa transformasi.
- **Catatan bug kecil**: `catch` block di controller ini balikin `'code' => 0` (bukan `100`) saat terjadi exception — error PHP akan muncul sebagai response "sukses" dengan `data` kosong/null, bukan error eksplisit. Perlu ditangani benar di implementasi FE baru (jangan asumsikan `code === 0` berarti `data` valid tanpa cek lebih lanjut).

## Get Table Section Table

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

Dipanggil bersamaan saat halaman List Table dimuat (`getTableSection()` + `getTableSectionTable()`, `MasterServices.ts`) — section dipakai sebagai tab/filter, lalu meja di tiap section diambil dari object hasil `table-section-table` pakai key `section.id`.

## Sumber

- `app/Http/Controllers/MasterController.php` — `getTableSection()`, `getTableSectionTable()`.
- Frontend: `src/services/MasterServices.ts`, `src/modules/dashboard/list_table/listTablePage.vue`.

## Status

Didokumentasikan 2026-10-12, berdasar pembacaan kode langsung (belum ada verifikasi ulang lewat HTTP request live di sesi ini).
