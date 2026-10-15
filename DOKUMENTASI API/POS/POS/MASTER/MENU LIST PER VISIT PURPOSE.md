# Menu List Per Visit Purpose

```
GET /api/master/menu-list/{visitPurposeId}
```

Versi **per-visit-purpose** dari [`MENU LIST.md`](./MENU%20LIST.md) (`GET /api/master/menu-list`) — balikin 1 baris visit purpose aja (bukan array semua visit purpose sekaligus), isinya sama persis (config pajak + pohon menu lengkap category → subcategory → item → package). Dibuat 2026-10-15 buat kasus FE yang cuma butuh 1 visit purpose spesifik (udah tau `visit_purpose_id`-nya dari Table Section yang dipilih kasir) tanpa perlu narik + filter manual dari array penuh di client.

`{visitPurposeId}` — `mr_branch_visit_purpose.id` / `nameVisitPurpose` id (sama `id` yang ada di tiap elemen array `data[]` endpoint `MENU LIST.md`).

**Reuse 100% logic yang sama** dengan `MENU LIST.md` — controller ini cuma manggil `MenuServices::GetMasterMenuList('pos')` (fungsi besar yang sama, channel yang sama), terus filter array hasilnya ke 1 baris yang `id`-nya cocok. **Tidak** ada reshape ke snake_case (beda dari versi Kiosk, `KIOSK BRANCH VISIT PURPOSE DETAIL.md`) — kontrak field-nya tetap camelCase, identik 1:1 dengan 1 elemen array `data[]` di `MENU LIST.md`. Konsekuensinya: field baru apa pun yang nanti ditambahkan ke `GetMasterMenuList()` (termasuk `sequence`, lihat di bawah) otomatis ikut muncul juga di sini tanpa perlu sentuh kode endpoint ini — satu sumber logic, dua pintu masuk.

## Response (struktur, bukan dump lengkap semua kolom)

```json
{
  "code": 0,
  "data": {
    "id": 7,
    "nameVisitPurpose": "Dine In",
    "serviceCharge": 9,
    "vat": 100,
    "pb1": 100,
    "orderFee": "0.00",
    "menuPriceListId": 12,
    "menuPriceList": [
      {
        "categoryId": 39,
        "categoryName": "MENU FOOD",
        "subCategoryData": [
          {
            "subCategoryId": 12,
            "SubCategoryName": "FOOD",
            "subCategoryIconSrc": null,
            "subCategoryBannerSrc": null,
            "sequence": 1,
            "menuList": [
              {
                "menuPricelistId": 93,
                "itemId": 120,
                "menuName": "PAKET MERCON",
                "menuPrice": "20000.00",
                "flagInclusiveTax": true,
                "taxType": "vat",
                "taxRate": "11.00",
                "notesMenu": ["Extra Pedas", "Less Ice"],
                "packageList": [
                  {
                    "packageId": 9,
                    "packageName": "PAKET 1",
                    "minQty": 1,
                    "maxQty": 1,
                    "menuPackageList": [
                      {
                        "menuPackageId": 210,
                        "itemId": 55,
                        "menuName": "NASI PUTIH",
                        "menuPrice": "0.00",
                        "defaultItem": true,
                        "notesMenu": []
                      }
                    ]
                  }
                ]
              }
            ]
          }
        ]
      }
    ]
  }
}
```

Kalau `{visitPurposeId}` gak ketemu (bukan visit purpose aktif branch ini, atau ID salah):

```json
{ "code": 100, "message": "visit purpose not found" }
```

- **Array terluar DIHAPUS** — beda dari `MENU LIST.md` yang array-nya 1 baris per visit purpose, di sini `data` langsung objek 1 visit purpose (sudah difilter server-side berdasar `{visitPurposeId}` di URL).
- Field-level, semuanya identik dengan `MENU LIST.md` — lihat bagian "Catatan" di situ buat penjelasan tiap field (`serviceCharge`/`vat`/`pb1` sebagai tax_id, `sequence`, `notesMenu`, `packageList[].menuPackageList[].defaultItem`, dkk). **Tidak diduplikasi di sini** supaya gak ada 2 sumber kebenaran yang bisa drift — kalau field baru ditambahkan di `GetMasterMenuList()`, cukup update catatan di `MENU LIST.md`.
- Nama key level objek tetap **`menuPriceList`** (bukan `categories`) dan **`subCategoryData`** (bukan `subcategories`) — sama persis penamaan asli di kode (`MenuServices::GetMasterMenuList()`), konsisten dengan `MENU LIST.md`.

## Alur Implementasi

1. `MasterController::GetMasterMenuListByVisitPurpose(Request $request, int $visitPurposeId)` — panggil `MenuServices::GetMasterMenuList('pos')` (channel POS, bukan Kiosk), dapat array SEMUA visit purpose, `foreach` cari yang `id`-nya cocok, balikin `404`-equivalent (`code: 100`) kalau gak ketemu.
2. **Belum dioptimasi** query-nya cuma buat 1 visit purpose — `GetMasterMenuList()` tetap hitung pohon menu + resolusi harga/pajak buat **semua** visit purpose branch ini, baru difilter ke satu di controller (sama persis trade-off yang sudah didokumentasikan di Kiosk, lihat "Catatan performa" di `KIOSK BRANCH VISIT PURPOSE DETAIL.md`). Aman untuk sekarang karena volume data per branch relatif kecil; kalau nanti jadi bottleneck, perlu ubah signature `GetMasterMenuList()` buat terima filter `visitPurposeId` dari awal (dampaknya ke 3 pemanggil: `MENU LIST.md` POS, `MENU LIST PER VISIT PURPOSE.md` ini, dan Kiosk).
3. Dirancang buat dipanggil **per kebutuhan** (misal pas kasir pindah ke Table Section dengan visit purpose beda, atau refresh 1 visit purpose spesifik) — beda dari `MENU LIST.md` yang dipanggil 1x di awal Dashboard load dan di-cache di state lokal FE. Belum ada integrasi FE (`orderPage.vue`) yang manggil endpoint ini per 2026-10-15 — murni backend endpoint baru, konsumsinya menyusul kalau dibutuhkan.

## Sumber

- `routes/api.php` — `Route::get('menu-list/{visitPurposeId}', [MasterController::class, 'GetMasterMenuListByVisitPurpose'])`.
- `app/Http/Controllers/MasterController.php` — `GetMasterMenuListByVisitPurpose()`.
- `app/Services/MenuServices.php` — `GetMasterMenuList()` (fungsi yang sama dipakai `MENU LIST.md` dan Kiosk, lihat dokumen itu buat detail lengkap).

## Status

Dibuat 2026-10-15. Backend (`routes/api.php`, `MasterController.php`) diverifikasi lewat `php -l` (lolos) dan `php artisan tinker` manual (panggil `MenuServices::GetMasterMenuList('pos')` langsung, konfirmasi struktur objek 1 visit purpose — `menuPriceList[].subCategoryData[].sequence` dkk — sesuai yang didokumentasikan di atas). **Belum** dites lewat HTTP request live (butuh server `php artisan serve` jalan + token Branch-Id valid), dan belum ada integrasi frontend yang memanggilnya.
