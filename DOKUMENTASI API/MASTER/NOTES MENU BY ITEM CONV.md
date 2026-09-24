# Notes Menu (embedded di Menu List)

**Endpoint on-demand `GET /api/master/notes-menu/{item_conv_id}` SUDAH DIHAPUS** (2026-09-24, sama seperti pasangannya `GET /api/kiosk/notes-menu/{item_conv_id}` yang juga sudah dihapus). Digantikan penuh: notes menu (`short_notes`) sekarang langsung di-embed sebagai field `notesMenu` di tiap baris item/sub-item package pada response `GET /api/master/menu-list` (`MasterController::GetMasterMenuList()` → `MenuServices::GetMasterMenuList('pos')`), gak perlu request terpisah lagi.

Pola ini sama persis dengan Kiosk (lihat [`KIOSK BRANCH VISIT PURPOSE DETAIL.md`](../KIOSK/KIOSK%20BRANCH%20VISIT%20PURPOSE%20DETAIL.md), section "Update (2026-09-24)") — bedanya cuma isi field-nya: POS pakai `short_notes` (field `notesMenu`), Kiosk pakai `full_notes` (field `notes_menu`, snake_case karena Kiosk reshape).

## Field `notesMenu`

Muncul di tiap item `menu-list` (level item utama, `mr_pricelist_detail` join `mr_item`) **dan** di tiap sub-item package (`menuPackageList[]`) — array string, isinya `short_notes` dari semua `mr_notes_menu_detail` yang `mr_notes_menu` induknya match ke `category_id`/`subcategory_id` item/sub-item itu:

```json
{
  "menuPricelistId": 93,
  "itemId": 120,
  "menuName": "PAKET MERCON",
  "categoryId": 39,
  "subCategoryId": 12,
  "notesMenu": ["Extra Pedas", "Less Pedas", "Less Ice", "Tanpa Gula"],
  "packageList": [
    {
      "packageId": 9,
      "packageName": "PAKET 1",
      "menuPackageList": [
        {
          "menuPackageId": 210,
          "itemId": 55,
          "menuName": "NASI PUTIH",
          "notesMenu": []
        }
      ]
    }
  ]
}
```

- Array kosong `[]` kalau gak ada notes menu yang match (bukan `null`).
- **Sub-item package** (`menuPackageList[].notesMenu`) matching-nya pakai `categoryId`/`subCategoryId` **milik sub-item itu sendiri**, bukan ikut item paket induknya — 1 package bisa berisi sub-item lintas kategori (mis. minuman di dalam package makanan).

## Logic matching

Sama rule-nya kayak sebelumnya waktu masih endpoint terpisah:

1. `mr_notes_menu` yang `applies_to = 'all_category'` → selalu ikut, semua item.
2. `applies_to = 'category'` → ikut kalau `category_id` item ada di `mr_notes_menu_categories`.
3. `applies_to = 'sub_category'` → ikut kalau `subcategory_id` item ada di `mr_notes_menu_subcategories`.
4. Kumpulkan `short_notes` dari `mr_notes_menu_detail` semua notes menu yang match, dedup.

Implementasi: `MenuServices::GetAllNotesMenuForMatching()` tarik semua baris notes menu (JOIN categories/subcategories/detail) **sekali** di awal `GetMasterMenuList()` — bukan query per item, biar gak N+1 pas loop seluruh pohon menu. Matching per item/sub-item di-lakuin in-memory lewat `MenuServices::ResolveNotesMenu($allNotesMenu, $categoryId, $subCategoryId, $field)`, `$field` beda per channel (`'shortNotes'` buat POS, `'fullNotes'` buat Kiosk — ditentukan dari parameter `$channel` yang sudah ada di `GetMasterMenuList()`).

## Sumber data

Sama seperti sebelumnya — tabel sync lokal POS (`mr_notes_menu`/`mr_notes_menu_categories`/`mr_notes_menu_subcategories`/`mr_notes_menu_detail`, ditarik dari ERP `master_notes_menu` dkk, lihat `MASTER NOTES MENU.md` di `sudocore2` buat skema aslinya & `SYNC/SYNC PULL.md` buat mekanisme tarik datanya) — bukan live lookup ke ERP.

## Konsumen

`posv1-vue` (`orderPage.vue`) — chip quick-pick muncul di 2 tempat, keduanya sekarang baca langsung dari data yang sudah ada (bukan fetch API lagi):

1. **Dialog Edit Order List** (klik item di cart) — `openEditOrderListDialog()` baca `itemOrder.notesMenu` (dibawa dari `itemConvRow.notesMenu` pas item di-push ke cart, lihat `addItemOrder()`).
2. **Dialog Add Order (package)** — `prefetchNotesMenuForPackage()` isi cache `notesMenuByItemId` langsung dari `sub.notesMenu` tiap sub-item package (sudah ada di `tbMasterDataMenu`), tanpa `Promise.all`/fetch lagi.

Klik chip **append** teks ke textarea notes yang ada (dipisah `, `), bukan gantiin isinya — skip kalau teks itu udah ada di dalamnya. Area chip dibatasi tinggi maks 2 baris, lebih dari itu scroll vertikal (`overflow-y-auto`).

## Kenapa dipindah dari endpoint on-demand ke embed

Awalnya dibangun sebagai endpoint terpisah (`GET /api/master/notes-menu/{item_conv_id}`, fetch on-demand pas dialog dibuka). Diputuskan pindah ke pola embed (2026-09-24) — sejalan dengan keputusan yang sama di Kiosk — karena datanya udah "gratis" nempel di `menu-list` yang emang selalu ditarik duluan sebelum kasir buka dialog apa pun; gak ada alasan bikin network roundtrip tambahan cuma buat data yang volumenya kecil (notes menu per tenant biasanya cuma belasan baris, bukan ribuan).

## Tervalidasi (2026-09-24)

- `php -l` lolos: `MenuServices.php`, `MasterController.php`, `KioskController.php`, `routes/api.php`.
- Build `posv1-vue` sukses tanpa error setelah `orderPage.vue`/`MasterServices.ts` dirombak (fungsi `getNotesMenuByItemConv()` di frontend dihapus, tidak ada pemanggil tersisa).
- Endpoint lama `GET /api/master/notes-menu/{item_conv_id}` dan `GET /api/kiosk/notes-menu/{item_conv_id}` sudah dihapus total dari `routes/api.php`, `MasterController.php`, `KioskController.php`.
