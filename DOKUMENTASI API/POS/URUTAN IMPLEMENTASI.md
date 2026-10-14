# Urutan Implementasi (per halaman)

Urutan ini disusun berdasarkan **flow aplikasi riil** (`src/router/index.js` + [`PETA HALAMAN.md`](POS/PETA%20HALAMAN.md)), bukan urutan folder dokumentasi — tujuannya supaya kalau migrasi/rebuild dari nol, tiap tahap sudah punya dependency (data/endpoint) yang dibutuhkan tahap sebelumnya sudah selesai duluan.

**Cara baca**: tiap nomor = 1 halaman/fase. Dalam 1 fase, baca dokumen yang didaftarkan dengan urutan yang sama seperti yang tercantum.

## Fase 0 — Device baru, benar-benar dari nol (sekali per device)

Urutan **persis** mengikuti apa yang device baru alami sebelum kasir bisa login sama sekali — belum ada data lokal apapun di titik ini.

1. **Setup** (`/setup`) — [`SETUP/SETUP WIZARD.md`](POS/SETUP/SETUP%20WIZARD.md): wizard pilih company → pilih branch → `install_success`.
2. **Sync Pull** (dipanggil wizard di atas, bukan halaman sendiri) — [`SYNC/SYNC PULL.md`](POS/SYNC/SYNC%20PULL.md): ±35 endpoint `sync_pull/get_*` berurutan, ini yang benar-benar mengisi data lokal pertama kali.
3. **Terminal Select** (`/terminal`) — [`MASTER/TERMINAL LIST.md`](POS/MASTER/TERMINAL%20LIST.md): pilih terminal device ini, baru bisa lanjut ke Lockscreen.
4. **Display Customer** (`/display_customer`, opsional, kalau branch ini pakai 2nd screen) — dibuka murni dari FE (`window.open()` di `Navbar.vue`), tidak ada endpoint backend; cross-tab via `localStorage`/Pinia `persist`, lihat [`PETA HALAMAN.md`](POS/PETA%20HALAMAN.md).

Setelah Fase 0 selesai, device sudah punya data lokal + terminal terpilih — baru masuk ke Fase 1 (Lockscreen/Login) dan seterusnya di alur harian normal.

## Fase 1 — Lapisan global & auth

Jalan terus selama app dibuka, dibutuhkan semua halaman berikutnya.

4. [`AUTH/AUTH.md`](POS/AUTH/AUTH.md) — login/logout, dipakai di Lockscreen.
5. [`INTERNAL/SYSTEM.md`](POS/INTERNAL/SYSTEM.md) — app-version check.
6. [`INTERNAL/PUSH DATA.md`](POS/INTERNAL/PUSH%20DATA.md) — 8 endpoint push + background job `sync:push`, jalan terus di belakang.
7. [`ORDER/ORDER NOTIF.md`](POS/ORDER/ORDER%20NOTIF.md) — polling notif 5 detik, global.
8. Guard router `CekStatusLogin` (bootstrap 4 endpoint sekaligus — `/setting/load`, `/master/branch`, `/dayshift/get`, `/auth/info`): lihat [`SETTING/SETTING.md`](POS/SETTING/SETTING.md), [`DAYSHIFT/START END DAY.md`](POS/DAYSHIFT/START%20END%20DAY.md) untuk detail masing-masing endpoint.

## Fase 2 — Master data (dasar untuk semua transaksi)

Harus ada sebelum halaman transaksi (List Table, Order, Payment) bisa jalan.

9. [`MASTER/TABLE SECTION.md`](POS/MASTER/TABLE%20SECTION.md)
10. [`MASTER/VISIT PURPOSE.md`](POS/MASTER/VISIT%20PURPOSE.md)
11. [`MASTER/MENU LIST.md`](POS/MASTER/MENU%20LIST.md)
12. [`MASTER/CATEGORY AND SUBCATEGORY.md`](POS/MASTER/CATEGORY%20AND%20SUBCATEGORY.md)
13. [`MASTER/MEMBER LIST.md`](POS/MASTER/MEMBER%20LIST.md)
14. [`MASTER/PROMO LIST.md`](POS/MASTER/PROMO%20LIST.md)
15. [`MASTER/PAYMENT METHOD.md`](POS/MASTER/PAYMENT%20METHOD.md)
16. [`MASTER/STATION LIST.md`](POS/MASTER/STATION%20LIST.md)
17. [`MASTER/BRANCH DETAIL.md`](POS/MASTER/BRANCH%20DETAIL.md)
18. [`MASTER/BRAND DETAIL.md`](POS/MASTER/BRAND%20DETAIL.md)
19. [`MASTER/BANNER IMAGE CUSTOMER DISPLAY.md`](POS/MASTER/BANNER%20IMAGE%20CUSTOMER%20DISPLAY.md)

## Fase 3 — Dayshift (prasyarat sebelum order bisa dibuat)

Order butuh dayshift aktif (`pos_order.dayshift_ulid`).

20. [`DAYSHIFT/START END DAY.md`](POS/DAYSHIFT/START%20END%20DAY.md) — Start Day & End Day.
21. [`DAYSHIFT/REPORT AND HISTORY.md`](POS/DAYSHIFT/REPORT%20AND%20HISTORY.md)
22. [`DAYSHIFT/PRINT AND DETAIL LIST.md`](POS/DAYSHIFT/PRINT%20AND%20DETAIL%20LIST.md)

## Fase 4 — List Table & Order (halaman kasir utama)

23. [`ORDER/LIST TABLE BY SECTION.md`](POS/ORDER/LIST%20TABLE%20BY%20SECTION.md)
24. [`ORDER/ORDER LIST.md`](POS/ORDER/ORDER%20LIST.md)
25. [`ORDER/SAVE ORDER.md`](POS/ORDER/SAVE%20ORDER.md) — paling kompleks, baca duluan sebelum yang di bawah karena jadi rujukan kalkulasi.
26. [`ORDER/VIEW ORDER.md`](POS/ORDER/VIEW%20ORDER.md)
27. [`ORDER/CANCEL ORDER.md`](POS/ORDER/CANCEL%20ORDER.md)
28. [`ORDER/CANCEL ITEM.md`](POS/ORDER/CANCEL%20ITEM.md)
29. [`ORDER/REMOVE ITEM BEFORE SAVE.md`](POS/ORDER/REMOVE%20ITEM%20BEFORE%20SAVE.md)
30. [`ORDER/MOVE TABLE AND MOVE ITEM.md`](POS/ORDER/MOVE%20TABLE%20AND%20MOVE%20ITEM.md)
31. [`ORDER/PRINT BILL.md`](POS/ORDER/PRINT%20BILL.md)

## Fase 5 — Payment

32. [`PAYMENT/SAVE EDIT VIEW PAYMENT.md`](POS/PAYMENT/SAVE%20EDIT%20VIEW%20PAYMENT.md)
33. [`PAYMENT/PAYMENT GATEWAY.md`](POS/PAYMENT/PAYMENT%20GATEWAY.md)

## Fase 6 — Sales (setelah payment selesai, order jadi riwayat)

34. [`SALES/SALES LIST.md`](POS/SALES/SALES%20LIST.md)
35. [`SALES/VIEW SALES.md`](POS/SALES/VIEW%20SALES.md)
36. [`SALES/REPRINT.md`](POS/SALES/REPRINT.md)
37. [`SALES/VOID.md`](POS/SALES/VOID.md)

## Fase 7 — Setting & sinkronisasi berkala

Setup awal (Fase 0) sudah pakai `sync_pull/*`; fase ini untuk sinkron ulang manual setelah app jalan.

38. [`SETTING/SETTING.md`](POS/SETTING/SETTING.md)
39. [`SETTING/TEST PRINT.md`](POS/SETTING/TEST%20PRINT.md)
40. [`SYNC/SYNC GROUP.md`](POS/SYNC/SYNC%20GROUP.md) — jalur kedua (beda dari `sync_pull/*` Fase 0), dipakai tab Synchronize.

## Fase 8 — Menu Management (admin, terakhir karena bergantung ke Master Menu)

41. [`MENU-MANAGEMENT/MENU MANAGEMENT.md`](POS/MENU-MANAGEMENT/MENU%20MANAGEMENT.md)

## Fase 9 — Cross-reference (dibuat/dibaca paling akhir)

42. [`PETA HALAMAN.md`](POS/PETA%20HALAMAN.md) — rangkuman semua halaman → endpoint, valid dibaca setelah fase 0–8 selesai.

---

**Catatan**: fitur Kirim Struk via WhatsApp (lintas Sales & Payment page, lihat [`PETA HALAMAN.md`](POS/PETA%20HALAMAN.md) bagian "Kirim WA") tidak punya dokumen sendiri — baca setelah Fase 5 & 6 selesai, karena dia reuse endpoint `push/order-sync-all` dari keduanya.
