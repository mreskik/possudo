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
  "terminal": [
    { "id": 5, "table_section_id": 2, "receipt_station_id": 1 }
  ]
}
```

**Response:**
```json
{ "code": 0, "message": "Success" }
```

- **Deteksi section via `$request->has('general_setting')`/`$request->has('terminal')`** — kirim body kosong sama sekali (tidak ada section manapun) → `{ "code": 100, "message": "No section was sent (general_setting/terminal)." }`.
- `general_setting` — update singleton `SettingModel` (`id = 1`) — `defaultStation`/`useCustomerDisplay`/`useVirtualKeyboard`. Field yang tidak dikirim (`?? null`) akan **ditimpa jadi `null`** — ini **bukan** partial update per-field, kirim ulang section ini berarti kirim semua field-nya, bukan cuma yang berubah.
- `terminal` — array **semua terminal** langsung (semua `pos_type`, bukan cuma Kiosk — key section-nya dulu bernama `kiosk.terminal` nested, sudah direname & diratakan 2026-10-14 karena menyesatkan: tab UI-nya ("Terminal Setting") dan datanya memang untuk semua jenis terminal, dan nesting `{ list: [...] }` tidak perlu karena section-nya sendiri sudah bernama `terminal`), update `table_section_id`/`receipt_station_id` per terminal. Array **kosong** dianggap valid (no-op, bukan error) — sengaja, supaya form yang selalu mengirim section ini (walau branch belum punya terminal) tidak ikut gagal. Setiap baris **wajib** punya `id` → kosong → `"terminal id is required in every terminal row."`.

## Load

```
GET /api/setting/load
```

**⚠️ Dipakai GLOBAL STATE, bukan khusus halaman Setting** — `globalstore.js`, di-bootstrap guard router `CekStatusLogin` setiap kali masuk area dashboard (lihat `PETA HALAMAN.md`), dibaca **flat** di banyak komponen (contoh: `Navbar.vue` → `globalstore.setting?.use_customer_display`). **Strukturnya TIDAK boleh diubah jadi nested/section-based** — akan merusak semua pemakai `globalstore.setting` di seluruh app. Kalau butuh bentuk section (simetris dengan `Save`), pakai [`Load Detail`](#load-detail) di bawah.

Response: `{ "code": 0, "data": { "id": 1, "default_station": 1, "use_customer_display": true, "use_virtual_keyboard": true } }`

Singleton `SettingModel::first()` apa adanya — snake_case (**beda konvensi** dari body `Save` yang camelCase di `general_setting`). Kolom `customer_display_left`/`customer_display_top`/`customer_display_name` **sengaja disembunyikan** dari response (`makeHidden()`) — dead field peninggalan fitur "pilih monitor untuk Customer Display" yang picker UI-nya (`<Select v-model="dataSetting.customerDisplay" :options="displayList">`) dan jalur `exec()`-nya sudah lama di-comment-out/dihapus (lihat [`TEST PRINT.md`](TEST%20PRINT.md)), tidak ada efek apapun ke perilaku aplikasi. Kolomnya sendiri masih ada di DB (tidak dihapus/migration baru), cuma tidak lagi diekspos lewat API maupun dibaca/ditulis dari frontend.

**Catatan untuk implementasi ke depan**: kolom-kolom ini **sengaja tidak dihapus dari DB** karena infrastrukturnya relevan kalau suatu saat mau diimplementasikan ulang fitur "Customer Display terbuka di monitor/posisi tertentu" (misal kasir punya 2 monitor fisik, dan Display Customer harus otomatis terbuka di monitor kedua, bukan ketindihan sama window kasir utama). Rencana aslinya (lihat sisa kode yang pernah ada sebelum dihapus/comment-out):
- `name`/`left`/`top` diisi dari [Multi-Screen Window Placement API](https://developer.mozilla.org/en-US/docs/Web/API/Window/getScreenDetails) browser (`window.getScreenDetails()`) — API ini mengembalikan daftar monitor fisik yang terpasang (label, posisi `left`/`top` dalam koordinat virtual desktop).
- User pilih salah satu monitor dari dropdown di halaman Setting → tersimpan ke `customer_display_name/left/top`.
- Saat buka Customer Display, **harus pindah dari `window.open()` biasa ke `window.open(url, '_blank', 'left=...,top=...')`** (atau pakai `Window.moveTo()` setelah window terbuka) supaya window baru itu otomatis lompat ke posisi monitor yang disimpan, bukan muncul di monitor yang sama dengan kasir.
- `window.getScreenDetails()` butuh **permission eksplisit dari user** (prompt browser "Allow site to manage windows on all your displays?") dan **HTTPS** (tidak jalan di `http://` biasa kecuali `localhost`) — ini kemungkinan alasan kenapa dulu belum selesai diimplementasikan/di-comment-out, bukan dihapus total.
- Kalau mau diaktifkan lagi: uncomment `<Select>` di `SettingPage.vue` (dekat `ToggleSwitch v-model="dataSetting.useCustomerDisplay"`), kembalikan `loadDisplay()`/`displayList`/`dataSetting.customerDisplay`, buka kembali field di `makeHidden()` (`SettingController.php`) dan tulis-baliknya di `SettingServices::Save()`, lalu sambungkan nilai posisi ke `customerDisplayOpen()` di `Navbar.vue`.

## Load Detail

```
GET /api/setting/detail
```

**Khusus halaman Setting** (`SettingPage.vue`) — bentuk response-nya **simetris dengan body `Save`** (section `general_setting` + `terminal`), supaya 1 call sekaligus isi form general setting **dan** tabel Terminal Setting. Gantiin kombinasi `load()` + `GetTerminalList()` (`/master/terminal-list`) yang sebelumnya 2 request terpisah. **Tidak dipakai modul lain** — beda dari [`Load`](#load) di atas yang dipakai global state, jangan dicampur.

Response:
```json
{
  "code": 0,
  "data": {
    "general_setting": { "id": 1, "default_station": 0, "use_customer_display": true, "use_virtual_keyboard": false },
    "terminal": [
      {
        "id": 1, "name": "TERMINAL 11", "branch_id": 14, "device_id": "", "pos_type_id": 2,
        "is_active": true, "is_used": false, "table_section_id": null, "receipt_station_id": null,
        "flag_printer_frontend": false, "pos_type_name": "POS Desktop", "pos_type_device_type": "mobile"
      }
    ]
  }
}
```

- `general_setting` — `SettingModel::first()`, sama persis seperti response `Load` (termasuk `makeHidden()` untuk 3 kolom Customer Display dead di atas).
- `terminal` — query **duplikat** dari `MasterController::GetTerminalList()` (`/master/terminal-list`): semua terminal `is_active`, join `pos_type_name`/`pos_type_device_type` dari `mr_pos_type`. Sengaja diduplikasi (bukan reuse controller lain) biar section ini independen, gampang dikembangkan terpisah dari endpoint master generic kalau kebutuhan halaman Setting berubah nanti.

## Alur Implementasi

Dipanggil dari `SettingPage.vue` — `loadDetail()` (`GET /setting/detail`) saat halaman dibuka (isi form general setting + tabel Terminal Setting sekaligus), `Save()` saat submit (section mana yang dikirim tergantung tab mana yang sedang diedit kasir, tapi body selalu include `terminal` walau array kosong — lihat catatan di atas). Endpoint `GET /setting/load` (flat) **tidak dipanggil** dari halaman ini — itu jalur `globalstore.js`.

## Sumber

- `app/Http/Controllers/SettingController.php` — `save()`, `load()`, `loadDetail()` (route `GET /setting/detail`).
- `app/Services/SettingServices.php` — `Save()`, `SaveTerminalSetting()`.
- Frontend: `src/services/SettingServices.ts` (`Save`, `load`, `loadDetail`), `src/modules/dashboard/setting/SettingPage.vue` (`terminalSettingList`, `loadsetting()`, `saveAllHandler()`).

## Status

Didokumentasikan 2026-10-12, berdasar pembacaan kode langsung (belum ada verifikasi ulang lewat HTTP request live di sesi ini). Diupdate 2026-10-14: (1) section `kiosk.terminal` (nested array) direname & diratakan jadi `terminal` (array langsung) di `Save` — termasuk `SaveKioskTerminal()` → `SaveTerminalSetting()` — penamaan & nesting lama menyesatkan karena fitur ini untuk semua jenis terminal, bukan cuma Kiosk; (2) ditambah endpoint baru `GET /setting/detail` (`loadDetail()`) khusus `SettingPage.vue`, simetris dengan `Save`, menggantikan kombinasi `load()` + `GetTerminalList()` — `load()` lama dipertahankan apa adanya karena dipakai global state (`globalstore.js`). Sudah dites hit API live, kedua endpoint (`load` dan `detail`) mengembalikan struktur sesuai dokumentasi.
