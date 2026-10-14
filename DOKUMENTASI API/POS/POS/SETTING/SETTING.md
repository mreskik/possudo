# Setting

## Save

```
POST /api/setting/save
```

**1 endpoint untuk semua tab halaman Setting** — body dipecah per section, **tiap section opsional**, cuma yang dikirim yang diproses.

**Request:**
```json
{
  "general_setting": {
    "defaultStation": 1,
    "useCustomerDisplay": true,
    "customerDisplay": { "name": "Display 1" },
    "useVirtualKeyboard": true
  },
  "kiosk": {
    "terminal": [
      { "id": 5, "table_section_id": 2, "receipt_station_id": 1 }
    ]
  }
}
```

**Response:**
```json
{ "code": 0, "message": "Success" }
```

- **Deteksi section via `$request->has('general_setting')`/`$request->has('kiosk.terminal')`** — kirim body kosong sama sekali (tidak ada section manapun) → `{ "code": 100, "message": "No section was sent (general_setting/kiosk)." }`.
- `general_setting` — update singleton `SettingModel` (`id = 1`) — `defaultStation`/`useCustomerDisplay`/`customerDisplay.name`/`useVirtualKeyboard`. Field yang tidak dikirim (`?? null`) akan **ditimpa jadi `null`** — ini **bukan** partial update per-field, kirim ulang section ini berarti kirim semua field-nya, bukan cuma yang berubah.
- `kiosk.terminal` — array terminal Kiosk, update `table_section_id`/`receipt_station_id` per terminal. Array **kosong** dianggap valid (no-op, bukan error) — sengaja, supaya form yang selalu mengirim section ini (walau branch belum punya terminal Kiosk) tidak ikut gagal. Setiap baris **wajib** punya `id` → kosong → `"terminal id is required in every kiosk.terminal row."`.

## Load

```
GET /api/setting/load
```

Response: `{ "code": 0, "data": { "id": 1, "default_station": 1, "use_customer_display": true, "customer_display_name": "Display 1", "use_virtual_keyboard": true } }`

Singleton `SettingModel::first()` apa adanya — snake_case (**beda konvensi** dari body `Save` yang camelCase di `general_setting`). Kolom `customer_display_left`/`customer_display_top` **sengaja disembunyikan** dari response (`makeHidden()`) — dead field peninggalan jalur `exec()` lama yang sudah dihapus (lihat [`TEST PRINT.md`](TEST%20PRINT.md)), tidak ada UI untuk mengeditnya lagi dan tidak ada efek apapun ke perilaku aplikasi. Kolomnya sendiri masih ada di DB (tidak dihapus/migration baru), cuma tidak lagi diekspos lewat API.

## Alur Implementasi

Dipanggil dari `SettingPage.vue` — `load()` saat halaman dibuka (isi form awal), `Save()` saat submit (section mana yang dikirim tergantung tab mana yang sedang diedit kasir, tapi body selalu include `kiosk.terminal` walau array kosong — lihat catatan di atas).

## Sumber

- `app/Http/Controllers/SettingController.php` — `save()`, `load()`.
- `app/Services/SettingServices.php` — `Save()`, `SaveKioskTerminal()`.
- Frontend: `src/services/SettingServices.ts` (`Save`, `load`), `src/modules/dashboard/setting/SettingPage.vue`.

## Status

Didokumentasikan 2026-10-12, berdasar pembacaan kode langsung (belum ada verifikasi ulang lewat HTTP request live di sesi ini).
