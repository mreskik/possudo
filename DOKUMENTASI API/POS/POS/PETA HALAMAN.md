# Peta Halaman → Endpoint

Dokumen cross-reference: tiap halaman/route di frontend `posv1-vue`, dan endpoint API apa saja yang dipakainya. Dibuat **terakhir**, setelah semua folder endpoint (AUTH/MASTER/ORDER/SALES/PAYMENT/DAYSHIFT/SETTING/MENU-MANAGEMENT/SETUP/SYNC/INTERNAL) selesai didokumentasikan, supaya semua link di bawah valid.

Path ditulis **relatif ke `/api`** (konsisten dengan `src/services/*`), misal `/auth/login` = `GET/POST {API_BASE}/api/auth/login`.

**Di luar scope dokumen ini**: Kiosk (sudah terdokumentasi di `DOKUMENTASI API/KIOSK/`), dan 3 halaman non-dashboard yang murni pendukung instalasi device (`Setup`, `Terminal Select`, `Display Customer`) — dicantumkan ringkas di bagian paling bawah untuk kelengkapan peta router, tapi endpoint detailnya sudah ada di folder masing-masing (SETUP/SYNC, MASTER).

## Lapisan Global (jalan terus, bukan milik 1 halaman)

Dipanggil dari `App.vue` (root) — aktif selama aplikasi dibuka, tidak terikat navigasi halaman manapun:

| Mekanisme | Endpoint | Keterangan |
|---|---|---|
| Polling order notif (5 detik) | `GET /order-notif/pending` | Lihat [`ORDER/ORDER NOTIF.md`](ORDER/ORDER%20NOTIF.md) |
| Konfirmasi notif | `POST /order-notif/confirm` | |
| Push data (8 endpoint) | `GET /push/data-dayshift`, `data-dayshift-detail`, `data-order`, `data-order-detail`, `data-order-detail-package`, `data-order-payment`, `data-remove-item-before-save`, `data-remove-item-before-save-package` | Lihat [`../INTERNAL/PUSH DATA.md`](../INTERNAL/PUSH%20DATA.md) — di app lama ini hampir seluruhnya **redundan** dengan background command `sync:push` (jalan tiap 120 detik) |

Guard router `CekStatusLogin` (dipasang di route induk `/dashboard`) memanggil **4 endpoint sekaligus** setiap kali masuk area dashboard — bukan milik 1 halaman tertentu, ini "bootstrap" state global (`globalstore`):
`GET /setting/load`, `GET /master/branch`, `GET /dayshift/get`, `GET /auth/info`, dan kondisional `GET /dayshift/detail-list/{ulid}` kalau ada dayshift aktif.

## Fitur lintas-halaman: Kirim Struk via WhatsApp (bukan endpoint API, tapi penting untuk migrasi)

Dipakai dari **Sales Page** dan **Payment Page** (composable bersama `src/composables/useSendWa.js`, diekstrak 2026-09-25 supaya tidak duplikasi logic). Satu-satunya panggilan ke backend POS adalah `GET /push/order-sync-all` (dipanggil dulu, **sebelum** dialog dibuka — dialog **tidak akan terbuka** kalau push ini gagal, supaya link struk yang dikirim tidak 404). Sisanya murni logic frontend + 2 integrasi eksternal:

- **Link struk publik** — dibangun dari `globalstore.branch.setting.domain_receipt` (custom domain per branch) kalau terisi, fallback ke `API_BASE` kalau kosong, format akhir `{base}/receipt/SUDO/{order_number}`. Ini mengarah ke **endpoint publik sudocore2** (ERP), bukan endpoint POS — baca `pos_order` yang sudah di-push, bukan `tr_order` lokal.
- **Template pesan** — dari `globalstore.branch.setting.whatsapp_template_text` (per branch, dikonfigurasi di ERP), fallback ke template default hardcode kalau kosong. Placeholder yang didukung: `{{customer_name}}`, `{{order_number}}`, `{{branch_name}}`, `{{total_grand}}`, `{{total_subtotal}}`, `{{total_discount}}`, `{{total_tax}}`, `{{receipt_link}}`. Placeholder yang tidak dikenal **dibiarkan apa adanya** (tidak dihapus/error).
- **Prioritas nomor tujuan**: order punya `memberId` → **wajib** pakai `memberPhoneNumber` (walau kosong, **sengaja tidak fallback** ke `customerPhoneNumber`); order tanpa `memberId` → pakai `customerPhoneNumber`. Field tetap editable manual oleh kasir di dialog sebelum kirim.
- **Normalisasi nomor**: hanya nomor yang diawali `0` yang di-convert ke `62` di depan; format lain dibiarkan apa adanya.
- **Pengiriman aktual**: `window.open('https://wa.me/{nomor}?text={pesan}')` — **bukan** API call, cuma membuka WhatsApp Web/App dengan teks sudah terisi; pengguna **masih harus klik Send manual** di WhatsApp, POS tidak pernah benar-benar "mengirim" pesan.
- **Sumber**: `src/composables/useSendWa.js`, dikonsumsi `paymentPage.vue` dan `SalesPage.vue` (masing-masing meng-adapt bentuk data order ke camelCase yang sama sebelum dipakai), `src/component/SendWaDialog.vue` (UI dialog).

## Lockscreen (Login)

Route `/lockscreen` — `src/modules/lockscreen/LockscreenPage.vue`

| Endpoint | Dok |
|---|---|
| `GET /master/table-section`, `GET /master/table-section-table` | [`MASTER/TABLE SECTION.md`](MASTER/TABLE%20SECTION.md) |
| `GET /orderlist/by-tablesection/{id}`, `GET /orderlist/all-pending` | [`ORDER/ORDER LIST.md`](ORDER/ORDER%20LIST.md) |
| `GET /system/app-version` | [`../INTERNAL/SYSTEM.md`](../INTERNAL/SYSTEM.md) |
| `POST /auth/login` | [`AUTH/AUTH.md`](AUTH/AUTH.md) |
| `GET /auth/logout` | [`AUTH/AUTH.md`](AUTH/AUTH.md) — dipanggil otomatis saat idle timeout 180 detik (bukan cuma tombol manual) |

## List Table (halaman utama setelah login)

Route `/dashboard/list-table` — `src/modules/dashboard/list_table/listTablePage.vue`

| Endpoint | Dok |
|---|---|
| `GET /master/table-section`, `GET /master/table-section-table` | [`MASTER/TABLE SECTION.md`](MASTER/TABLE%20SECTION.md) |
| `GET /orderlist/by-tablesection/{id}`, `GET /orderlist/all-pending` | [`ORDER/ORDER LIST.md`](ORDER/ORDER%20LIST.md) |
| `GET /master/visit-purpose`, `GET /master/menu-list` | [`MASTER/VISIT PURPOSE.md`](MASTER/VISIT%20PURPOSE.md), [`MASTER/MENU LIST.md`](MASTER/MENU%20LIST.md) — di-cache ke IndexedDB (`tbVisitPurpose`/`tbMasterDataMenu`), dipanggil ulang saat dialog Book Table dibuka |
| `POST /order/save-order` | [`ORDER/SAVE ORDER.md`](ORDER/SAVE%20ORDER.md) — submit Book Table / Book & Order |

**Catatan**: sebagian data yang "terlihat" dipakai di halaman ini (visit purpose, tax, menu) sebenarnya dibaca dari **IndexedDB lokal** (hasil sync sebelumnya), bukan API call baru — jangan dihitung dobel sebagai traffic API live.

## Member

Route `/dashboard/member` — `src/modules/dashboard/member/MemberPage.vue`

| Endpoint | Dok |
|---|---|
| `GET /master/member-list` | [`MASTER/MEMBER LIST.md`](MASTER/MEMBER%20LIST.md) — satu-satunya call, halaman read-only |

## Order (POS kasir — halaman paling kompleks)

Route `/dashboard/order/:order_number?` — `src/modules/dashboard/order/orderPage.vue`

| Endpoint | Dok |
|---|---|
| `GET /master/visit-purpose`, `GET /master/menu-list` | refresh cache IndexedDB saat halaman dibuka |
| `GET /master/member-list` | [`MASTER/MEMBER LIST.md`](MASTER/MEMBER%20LIST.md) — dialog Select Member |
| `GET /master/promo-list/{visit_purpose_id}?member_type_id=` | [`MASTER/PROMO LIST.md`](MASTER/PROMO%20LIST.md) — dialog Apply Promo, baca [`ORDER/SAVE ORDER.md`](ORDER/SAVE%20ORDER.md) untuk alur lengkap kalkulasi+split-remainder |
| `GET /order/view-order/{order_number}` | [`ORDER/VIEW ORDER.md`](ORDER/VIEW%20ORDER.md) — mode edit order existing |
| `POST /order/save-order` | [`ORDER/SAVE ORDER.md`](ORDER/SAVE%20ORDER.md) — tombol Save Order → redirect ke Payment |
| `POST /order/cancel-order` | [`ORDER/CANCEL ORDER.md`](ORDER/CANCEL%20ORDER.md) |
| `POST /order/cancel-item` | [`ORDER/CANCEL ITEM.md`](ORDER/CANCEL%20ITEM.md) — item yang sudah punya `ulid` |
| `POST /order/remove-item-before-save` | [`ORDER/REMOVE ITEM BEFORE SAVE.md`](ORDER/REMOVE%20ITEM%20BEFORE%20SAVE.md) — item yang **belum** punya `ulid`, fire-and-forget, juga di `beforeunload` |
| `GET /order/print-bill` | [`ORDER/PRINT BILL.md`](ORDER/PRINT%20BILL.md) |
| `GET /master/table-section` → `GET /order/list-table-by-section/{id}` → `POST /order/save-move-table` | [`ORDER/MOVE TABLE AND MOVE ITEM.md`](ORDER/MOVE%20TABLE%20AND%20MOVE%20ITEM.md) — fitur Move Table |
| `GET /master/table-section` → `GET /order/list-table-by-section-all/{id}` → `GET /order/view-order/{order_number}` → `POST /order/save-move-item` | [`ORDER/MOVE TABLE AND MOVE ITEM.md`](ORDER/MOVE%20TABLE%20AND%20MOVE%20ITEM.md) — fitur Move Item |

## Payment

Route `/dashboard/payment/:order_number?/:action?` — `src/modules/dashboard/payment/paymentPage.vue`

**Catatan**: composable `usePayment.ts`/`usePaymentMethod.ts` di folder ini **kosong/tidak dipakai** — semua logic ada langsung di `paymentPage.vue`.

| Endpoint | Dok |
|---|---|
| `GET /order/view-order/{order_number}` | [`ORDER/VIEW ORDER.md`](ORDER/VIEW%20ORDER.md) |
| `GET /master/payment-method/{visit_purpose_id}` | [`MASTER/PAYMENT METHOD.md`](MASTER/PAYMENT%20METHOD.md) |
| `GET /master/member-list` | [`MASTER/MEMBER LIST.md`](MASTER/MEMBER%20LIST.md) |
| `GET /payment/view-payment/{order_number}` | [`PAYMENT/SAVE EDIT VIEW PAYMENT.md`](PAYMENT/SAVE%20EDIT%20VIEW%20PAYMENT.md) — hanya mode `action=edit`, atau refresh pasca QRIS sukses |
| `POST /payment/save-payment` | [`PAYMENT/SAVE EDIT VIEW PAYMENT.md`](PAYMENT/SAVE%20EDIT%20VIEW%20PAYMENT.md) |
| `POST /payment/edit-payment` | [`PAYMENT/SAVE EDIT VIEW PAYMENT.md`](PAYMENT/SAVE%20EDIT%20VIEW%20PAYMENT.md) — mode edit, redirect ke Sales setelah sukses |
| `POST /payment-gateway/request`, `GET /payment-gateway/check-status/{order_number}` (polling 3 detik), `POST /payment-gateway/cancel` | [`PAYMENT/PAYMENT GATEWAY.md`](PAYMENT/PAYMENT%20GATEWAY.md) — via Pinia store `gatewayPayment.ts`; **ingat efek samping**: `check-status` bisa langsung finalize payment |
| `GET /push/order-sync-all` | sebelum dialog Send WA dibuka |

## Sales List

Route `/dashboard/sales` — `src/modules/dashboard/sales/SalesPage.vue`

| Endpoint | Dok |
|---|---|
| `GET /sales/get-sales-list` | [`SALES/SALES LIST.md`](SALES/SALES%20LIST.md) |
| `GET /sales/view/{order_number}` | [`SALES/VIEW SALES.md`](SALES/VIEW%20SALES.md) |
| `GET /sales/reprint/{order_number}` | [`SALES/REPRINT.md`](SALES/REPRINT.md) |
| `POST /sales/void` | [`SALES/VOID.md`](SALES/VOID.md) — ingat bug null-check order di Void() |
| `GET /push/order-sync-all` | sebelum dialog Send WA |

Tombol "Edit Payment" di dialog detail hanya navigasi ke route `payment?action=edit`, tidak memanggil API langsung dari halaman ini.

## Start Day

Route `/dashboard/start-day` — `src/modules/dashboard/daystart/DayStartPage.vue`

| Endpoint | Dok |
|---|---|
| `POST /dayshift/start` | [`DAYSHIFT/START END DAY.md`](DAYSHIFT/START%20END%20DAY.md) — sukses → `globalstore.loadData()` ulang → redirect `list-table` |

## Day Start/End Detail (Shift Detail / End Day)

Route `/dashboard/day-start-end/:id?` — `src/modules/dashboard/daystart_end/DayStartEndPage.vue`

| Endpoint | Dok |
|---|---|
| `GET /dayshift/report/{id}` (v1), `GET /dayshift/report-v2/{id}` | [`DAYSHIFT/REPORT AND HISTORY.md`](DAYSHIFT/REPORT%20AND%20HISTORY.md) — dipanggil paralel saat buka halaman |
| `GET /dayshift/end-shift/{id}` | [`DAYSHIFT/START END DAY.md`](DAYSHIFT/START%20END%20DAY.md) — tombol End Shift, lalu refresh report v1+v2 |
| `GET /dayshift/print-report-shift/{id}`, `GET /dayshift/print-current-shift-report/{ulid}`, `GET /dayshift/print-report-dayshift/{ulid}` | [`DAYSHIFT/PRINT AND DETAIL LIST.md`](DAYSHIFT/PRINT%20AND%20DETAIL%20LIST.md) |
| `GET /dayshift/pending-order` | [`DAYSHIFT/REPORT AND HISTORY.md`](DAYSHIFT/REPORT%20AND%20HISTORY.md) — dicek sebelum dialog End Day dibuka |
| `POST /dayshift/end-day` | [`DAYSHIFT/START END DAY.md`](DAYSHIFT/START%20END%20DAY.md) — timeout 60 detik, sukses → `globalstore.loadData()` + redirect `/dashboard` |

**Catatan**: `GET /dayshift/print-report/{id}` (endpoint fungsional-dead, lihat [`DAYSHIFT/PRINT AND DETAIL LIST.md`](DAYSHIFT/PRINT%20AND%20DETAIL%20LIST.md)) tidak terpanggil dari template halaman ini — fungsi composable ada tapi tidak dipakai, konsisten dengan status dead-nya.

## Shift Log

Route `/dashboard/shift-log` — `src/modules/dashboard/shiftlog/ShiftLogPage.vue`

| Endpoint | Dok |
|---|---|
| `GET /dayshift/dayshift-list` | [`DAYSHIFT/REPORT AND HISTORY.md`](DAYSHIFT/REPORT%20AND%20HISTORY.md) — satu-satunya call; klik row → navigasi `day-start-end/:id` |

## Setting

Route `/dashboard/setting` — `src/modules/dashboard/setting/SettingPage.vue`

| Tab | Endpoint | Dok |
|---|---|---|
| General | `GET /setting/load`, `GET /master/station-list`, `GET /system/app-version` | [`SETTING/SETTING.md`](SETTING/SETTING.md) |
| Synchronize | `GET /sync-group/branch-setting`, `branch-setting-images`, `member`, `promotion`, `table`, `master-setting`, `menu`, `menu-images`, `sales`, `user` — satu per satu sesuai centang, urutan tetap | [`../SYNC/SYNC GROUP.md`](../SYNC/SYNC%20GROUP.md) — **jalur BEDA** dari `/sync_pull/get_*` yang dipakai halaman Setup (lihat catatan di bawah) |
| Terminal | `GET /master/terminal-list`, `GET /master/table-section` | [`MASTER/TERMINAL LIST.md`](MASTER/TERMINAL%20LIST.md) |
| Save (General+Terminal gabung) | `POST /setting/save` | [`SETTING/SETTING.md`](SETTING/SETTING.md) — sukses → `globalstore.loadData()` |
| Test Print | `GET /setting/test-print-all`, `GET /setting/test-print/{id}` | [`SETTING/TEST PRINT.md`](SETTING/TEST%20PRINT.md) |

**Penting**: `/sync-group/*` (dipakai di sini, untuk sinkron ulang berkala/manual) dan `/sync_pull/get_*` (dipakai di halaman Setup, instalasi awal) adalah **2 jalur berbeda** untuk tujuan serupa — jangan disamakan saat migrasi.

## Menu Management

Route `/dashboard/menu-management` — `src/modules/dashboard/menu_management/MenuManagementPage.vue`

| Endpoint | Dok |
|---|---|
| `GET /branch-menu/load` | [`MENU-MANAGEMENT/MENU MANAGEMENT.md`](MENU-MANAGEMENT/MENU%20MANAGEMENT.md) |
| `GET /master/category-list`, `GET /master/subcategory-list` | [`MASTER/CATEGORY AND SUBCATEGORY.md`](MASTER/CATEGORY%20AND%20SUBCATEGORY.md) |
| `POST /branch-menu/save-stokqty`, `POST /branch-menu/save-soldout` | [`MENU-MANAGEMENT/MENU MANAGEMENT.md`](MENU-MANAGEMENT/MENU%20MANAGEMENT.md) — tiap edit langsung diikuti reload `load` |
| `GET /push/sold-out` | [`MENU-MANAGEMENT/MENU MANAGEMENT.md`](MENU-MANAGEMENT/MENU%20MANAGEMENT.md) — tombol manual Push |

## Halaman pendukung instalasi device (di luar fokus migrasi utama, dicantumkan untuk kelengkapan)

Ketiganya bukan bagian dari alur kasir harian — hanya dipakai sekali saat setup device baru, atau untuk tampilan customer-facing pasif. Endpoint sudah tercakup di folder SETUP/SYNC/MASTER.

- **Setup** (`/setup`, `SetupPage.vue`) — wizard: `POST /setup/get_branch_list` → `POST /setup/get_data_branch/{id}` → rentetan `GET /sync_pull/get_*` (±35 endpoint, berurutan) → `GET /setup/install_success/1`. Lihat [`../SETUP/SETUP WIZARD.md`](../SETUP/SETUP%20WIZARD.md) dan [`../SYNC/SYNC PULL.md`](../SYNC/SYNC%20PULL.md).
- **Terminal Select** (`/terminal`, `TerminalPage.vue`) — `GET /master/branch`, `GET /master/terminal-list`, `GET /sync_pull/get_terminal`.
- **Display Customer** (`/display_customer`, `DisplayCustomerPage.vue`) — customer-facing pasif, baca status payment gateway via cross-tab `localStorage` (bukan API call baru di sisi Display), dibuka murni dari FE (`window.open()` di `Navbar.vue`) — tidak ada endpoint backend sama sekali (jalur lama `exec()` shell command sudah dihapus 2026-10-14).

## Catatan migrasi tambahan

- Endpoint `/master/notes-menu/:item_conv_id` yang mungkin masih disebut di komentar kode lama **sudah dihapus** (2026-09-24) — `notesMenu` sekarang dibawa langsung dari payload `GET /master/menu-list`, bukan endpoint terpisah.
- `GET /dayshift/print-report/{id}` (lihat [`DAYSHIFT/PRINT AND DETAIL LIST.md`](DAYSHIFT/PRINT%20AND%20DETAIL%20LIST.md)) — dead baik di backend (commented-out) maupun di pemanggilan frontend (tidak ada di template). Jangan diport.
- Banyak data referensi (visit purpose, menu, tax) dibaca dari IndexedDB lokal hasil sync, bukan API call langsung — saat menghitung beban request API real per halaman, jangan ikut hitung baca IndexedDB sebagai traffic API.

## Status

Didokumentasikan 2026-10-12, berdasar pembacaan `src/router/index.js` dan seluruh file halaman dashboard secara langsung (belum ada verifikasi ulang lewat HTTP request live di sesi ini). Dokumen ini ditulis **terakhir** setelah seluruh folder endpoint selesai, sehingga seluruh link relatif di atas sudah mengarah ke file yang benar-benar ada.
