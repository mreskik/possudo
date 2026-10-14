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
- `general_setting` — update singleton `SettingModel` (`id = 1`) — `defaultStation`/`useCustomerDisplay`/`useVirtualKeyboard`. Field yang tidak dikirim (`?? null`) akan **ditimpa jadi `null`** — ini **bukan** partial update per-field, kirim ulang section ini berarti kirim semua field-nya, bukan cuma yang berubah.
- `kiosk.terminal` — array terminal Kiosk, update `table_section_id`/`receipt_station_id` per terminal. Array **kosong** dianggap valid (no-op, bukan error) — sengaja, supaya form yang selalu mengirim section ini (walau branch belum punya terminal Kiosk) tidak ikut gagal. Setiap baris **wajib** punya `id` → kosong → `"terminal id is required in every kiosk.terminal row."`.

## Load

```
GET /api/setting/load
```

Response: `{ "code": 0, "data": { "id": 1, "default_station": 1, "use_customer_display": true, "use_virtual_keyboard": true } }`

Singleton `SettingModel::first()` apa adanya — snake_case (**beda konvensi** dari body `Save` yang camelCase di `general_setting`). Kolom `customer_display_left`/`customer_display_top`/`customer_display_name` **sengaja disembunyikan** dari response (`makeHidden()`) — dead field peninggalan fitur "pilih monitor untuk Customer Display" yang picker UI-nya (`<Select v-model="dataSetting.customerDisplay" :options="displayList">`) dan jalur `exec()`-nya sudah lama di-comment-out/dihapus (lihat [`TEST PRINT.md`](TEST%20PRINT.md)), tidak ada efek apapun ke perilaku aplikasi. Kolomnya sendiri masih ada di DB (tidak dihapus/migration baru), cuma tidak lagi diekspos lewat API maupun dibaca/ditulis dari frontend.

**Catatan untuk implementasi ke depan**: kolom-kolom ini **sengaja tidak dihapus dari DB** karena infrastrukturnya relevan kalau suatu saat mau diimplementasikan ulang fitur "Customer Display terbuka di monitor/posisi tertentu" (misal kasir punya 2 monitor fisik, dan Display Customer harus otomatis terbuka di monitor kedua, bukan ketindihan sama window kasir utama). Rencana aslinya (lihat sisa kode yang pernah ada sebelum dihapus/comment-out):
- `name`/`left`/`top` diisi dari [Multi-Screen Window Placement API](https://developer.mozilla.org/en-US/docs/Web/API/Window/getScreenDetails) browser (`window.getScreenDetails()`) — API ini mengembalikan daftar monitor fisik yang terpasang (label, posisi `left`/`top` dalam koordinat virtual desktop).
- User pilih salah satu monitor dari dropdown di halaman Setting → tersimpan ke `customer_display_name/left/top`.
- Saat buka Customer Display, **harus pindah dari `window.open()` biasa ke `window.open(url, '_blank', 'left=...,top=...')`** (atau pakai `Window.moveTo()` setelah window terbuka) supaya window baru itu otomatis lompat ke posisi monitor yang disimpan, bukan muncul di monitor yang sama dengan kasir.
- `window.getScreenDetails()` butuh **permission eksplisit dari user** (prompt browser "Allow site to manage windows on all your displays?") dan **HTTPS** (tidak jalan di `http://` biasa kecuali `localhost`) — ini kemungkinan alasan kenapa dulu belum selesai diimplementasikan/di-comment-out, bukan dihapus total.
- Kalau mau diaktifkan lagi: uncomment `<Select>` di `SettingPage.vue` (dekat `ToggleSwitch v-model="dataSetting.useCustomerDisplay"`), kembalikan `loadDisplay()`/`displayList`/`dataSetting.customerDisplay`, buka kembali field di `makeHidden()` (`SettingController.php`) dan tulis-baliknya di `SettingServices::Save()`, lalu sambungkan nilai posisi ke `customerDisplayOpen()` di `Navbar.vue`.

## Alur Implementasi

Dipanggil dari `SettingPage.vue` — `load()` saat halaman dibuka (isi form awal), `Save()` saat submit (section mana yang dikirim tergantung tab mana yang sedang diedit kasir, tapi body selalu include `kiosk.terminal` walau array kosong — lihat catatan di atas).

## Sumber

- `app/Http/Controllers/SettingController.php` — `save()`, `load()`.
- `app/Services/SettingServices.php` — `Save()`, `SaveKioskTerminal()`.
- Frontend: `src/services/SettingServices.ts` (`Save`, `load`), `src/modules/dashboard/setting/SettingPage.vue`.

## Status

Didokumentasikan 2026-10-12, berdasar pembacaan kode langsung (belum ada verifikasi ulang lewat HTTP request live di sesi ini).
