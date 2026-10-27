# Gerbang Awal Aplikasi — Detail Teknis (Lengkap)

Versi lengkap dari [GERBANG AWAL APLIKASI (SIMPLE).md](GERBANG%20AWAL%20APLIKASI%20%28SIMPLE%29.md) — behaviour akhirnya SAMA (3 gerbang linear: install → terminal → login), tapi **implementasinya tersebar di 3 titik kode berbeda** (bukan 1 router guard terpusat), dan ada **jalur "muter"** yang gak kelihatan dari behaviour doang. Dokumen ini buat developer yang bakal migrasi/refactor router — **analisa kode sudocore2 2026-10-27**, belum pernah diubah/disesuaikan selama analisa ini (murni observasi).

Semua rujukan file di bawah relatif ke repo `posv1-vue` (frontend POS, Vue 3 + Pinia), **bukan** `posv1-laravel` (backend) — dokumen ini taruh di sini (sejajar `URUTAN IMPLEMENTASI.md`) karena sifatnya cross-cutting alur aplikasi, bukan endpoint API spesifik.

## 3 Titik Keputusan (BUKAN 1 guard terpusat)

| # | Nama | Lokasi | Ngecek apa | Redirect kalau gagal |
|---|---|---|---|---|
| 1 | Install check | `src/modules/setup/SetupPage.vue`, `onMounted()` | `globalstore.branch?.branch_name != null` | **TIDAK ADA** redirect kalau belum install (user emang lagi di `/setup`, dibiarkan isi form) — yang ada redirect KALAU **sudah** install (lompat ke `list-table`, **bukan** ke `/terminal`) |
| 2 | Terminal check | `src/router/index.js`, `router.beforeEach()` (GLOBAL, semua route) | `localStorage.getItem('terminal_id')` | `{ name: 'terminal' }` — **kecuali** `to.name` ada di `bypass = ['setup', 'terminal']` |
| 3 | Login check | `src/router/index.js`, fungsi `CekStatusLogin()`, dipasang sebagai `beforeEnter` di route `dashboard` doang | `globalstore.user?.id == null` (setelah `await globalstore.loadData()`) | `{ name: 'lockscreen' }` |

**Ketiganya independen** — gak ada 1 fungsi yang orkestrasi urutannya. Urutan yang KELIHATAN linear (install → terminal → login) itu muncul dari KOMBINASI efek sampingan masing-masing titik, bukan desain terpusat.

**Data terminal disimpan di `localStorage` browser, BUKAN di Pinia store/server.** 3 key: `terminal_id`, `terminal_name`, `terminal_type` — ditulis sekali di `pilihTerminal()` (`TerminalPage.vue`), dibaca ulang tiap kali guard global (`router.beforeEach`) jalan. Konsekuensinya: data ini **per-browser-profile**, bukan per-device "beneran" — kalau user buka app ini di browser/profile lain di device yang SAMA, `terminal_id` dianggap kosong lagi (balik ke `/terminal`). Sebaliknya, kalau localStorage di-clear (manual, mode incognito, atau browser reset data), device itu "lupa" terminal-nya walau `branch_name` di backend tetap ada (install TIDAK ke-reset, cuma pilihan terminal-nya doang).

**Install check SEBELUMNYA tidak ada endpoint baca-balik** (cuma `flag_install_status` ditulis lewat `install_success/{status}`, tidak pernah dibaca ulang dari endpoint manapun — frontend pakai `branch_name` dari `master/branch` sebagai proxy). Ditambahkan **`GET /api/setup/install_status`** (2026-10-27, backend `posv1-laravel`) — `{ code: 0, data: { is_installed: true/false } }`, baca `setup_config.flag_install_status` (id=1, baris ini selalu ada sejak migration awal). **Murni backend, frontend BELUM diubah** untuk pakai endpoint ini — `SetupPage.vue` tetap cek `branch_name` seperti sebelumnya. Implementasi: `app/Services/ConfigService.php` (`GetInstallStatus()`), `app/Http/Controllers/SetupController.php` (`GetInstallStatus()`), route di `routes/api.php` grup `setup`. Tervalidasi live: `install_status` balik `true`/`false` sesuai nilai `flag_install_status` di DB setelah `install_success/1`/`install_success/0` dipanggil.

## Diagram (detail titik keputusan + efek sampingan)

```mermaid
flowchart TD
    Boot(["App boot<br/>(main.js: await globalstore.loadData())"]) --> Nav{User navigasi<br/>ke route apa?}

    Nav -->|/setup| SetupMount["SetupPage onMounted()<br/>cek: branch?.branch_name"]
    SetupMount -->|branch_name ADA<br/>(sudah install)| JumpListTable["router.push('list-table')<br/>⚠️ BUKAN ke /terminal"]
    SetupMount -->|branch_name KOSONG<br/>(belum install)| SetupForm[User isi form:<br/>company → branch → sync ±35 endpoint]
    SetupForm --> Finish["Finish(): POST /setup/install_success/1<br/>→ localStorage.removeItem('terminal_id'/'terminal_name')<br/>→ router.push('terminal')"]

    JumpListTable --> GlobalGuard
    Finish --> GlobalGuard
    Nav -->|route LAIN<br/>(bukan /setup)| GlobalGuard{"router.beforeEach (GLOBAL)<br/>bypass = ['setup','terminal']?"}

    GlobalGuard -->|to.name di bypass| SkipGlobal[Lanjut, skip cek ini]
    GlobalGuard -->|to.name BUKAN di bypass| CekTerminalId{"localStorage<br/>'terminal_id' ADA?"}

    CekTerminalId -->|TIDAK ADA| ForceTerminal["return { name: 'terminal' }"]
    CekTerminalId -->|ADA| CekTerminalType{"localStorage<br/>'terminal_type' == '3'?<br/>(Kiosk)"}
    CekTerminalType -->|Ya, Kiosk| HardRedirect["window.location.href = '/kiosk'<br/>(hard redirect, keluar SPA ini)"]
    CekTerminalType -->|Bukan Kiosk| LanjutRoute[Lanjut ke route tujuan]

    ForceTerminal --> TerminalPage["TerminalPage.vue<br/>GET /api/terminal (load list)"]
    TerminalPage --> Pilih["pilihTerminal():<br/>localStorage.setItem(terminal_id/name/type)<br/>router.push('dashboard')"]
    Pilih --> DashboardEnter

    LanjutRoute -->|to.name == 'dashboard'| DashboardEnter{"beforeEnter: CekStatusLogin()<br/>await loadData(); cek user?.id"}
    DashboardEnter -->|user.id NULL| ForceLogin["return { name: 'lockscreen' }"]
    DashboardEnter -->|user.id ADA| DashboardOk([Dashboard render])

    ForceLogin --> LockscreenPage["LockscreenPage.vue<br/>POST /api/auth/login (PIN)"]
    LockscreenPage --> DashboardOk

    SkipGlobal -.->|kalau to.name == 'dashboard' juga kena DashboardEnter| DashboardEnter
```

## Jalur "Muter" yang Perlu Diperhatikan

1. **Install check TIDAK pernah redirect ke `/terminal` secara langsung.** Kalau device sudah install (`branch_name` ada) tapi user entah bagaimana nyasar ke `/setup` (manual ketik URL, bookmark lama, dst), `SetupPage` lempar ke `list-table` dulu — **bukan** `/terminal`. Baru setelah itu, **guard global #2** yang nangkep (`terminal_id` kosong kalau emang belum pernah pilih terminal) dan lempar ke `/terminal`. Behaviour akhirnya SAMA kayak yang di dokumentasi Simple, tapi lewat 2 loncatan, bukan 1.
2. **`Finish()` di SetupPage SENGAJA hapus `terminal_id`/`terminal_name`** dari localStorage sebelum push ke `/terminal` (baris 191-192, `SetupPage.vue`) — walaupun device ini mungkin PERNAH punya `terminal_id` tersimpan dari sesi lama (kasus device di-install ulang/reset data). Ini jaga-jaga biar user WAJIB pilih ulang terminal setelah install ulang, gak ke-skip gara-gara localStorage lama yang nyangkut.
3. **Guard terminal (`router.beforeEach`) jalan buat SEMUA route kecuali 2 nama** (`setup`, `terminal`) — termasuk `lockscreen` dan `display_customer`. Jadi SECARA TEKNIS, kalau `terminal_id` somehow kehapus manual (DevTools, dst) SAAT user udah di tengah-tengah pakai app (misal lagi di halaman order), navigasi APAPUN berikutnya bakal kelempar balik ke `/terminal` — bukan cuma di awal boot.
4. **Login check (`CekStatusLogin`) HANYA terpasang di route `dashboard`** (parent route, bukan di tiap child-nya) — child route (`order`, `payment`, `list-table`, dst) punya guard SENDIRI (`kesiapan`, cek day-shift), **TIDAK** ngecek ulang status login. Login check gak keulang di child karena Vue Router child route otomatis warisin `beforeEnter` parent-nya.
5. **`terminal_type === '3'` (Kiosk) dicek SETELAH lolos cek `terminal_id`**, bukan sebelum — kalau device-nya memang terminal Kiosk, dia tetap kena alur Setup → Terminal Select dulu (sama kayak device POS biasa), baru SETELAH terminal_type ketahuan Kiosk, langsung di-hard-redirect (`window.location.href`) ke bundle `/kiosk` yang beda JS sepenuhnya (bukan route SPA dalam bundle `posv1-vue` ini).

## File yang Relevan

| File | Peran |
|---|---|
| `src/main.js` | `await globalstore.loadData()` dipanggil SEKALI di bootstrap, SEBELUM router mulai — ini kenapa `globalstore.branch` udah keisi duluan pas `SetupPage` di-mount |
| `src/stores/globalstore.js` | `loadData()` — isi `branch`/`dayshift`/`user`/`shiftlist` dari 4 endpoint lokal (`master/branch`, `dayshift/get`, `auth/info`, dkk) |
| `src/modules/setup/SetupPage.vue` | Install check (`onMounted`) + `Finish()` (trigger `install_success`, hapus terminal lama, push ke `/terminal`) |
| `src/modules/setup/TerminalPage.vue` | List terminal (`GET /api/terminal`) + `pilihTerminal()` (tulis localStorage, push ke `dashboard`) |
| `src/router/index.js` | Guard global (`beforeEach`, cek `terminal_id`+`terminal_type`) + `CekStatusLogin` (`beforeEnter` khusus `dashboard`) + `kesiapan` (`beforeEnter` child route, cek day-shift — DI LUAR scope dokumen ini) |
| `src/modules/lockscreen/LockscreenPage.vue` | Form PIN, `POST /api/auth/login` |

## Referensi

- [GERBANG AWAL APLIKASI (SIMPLE).md](GERBANG%20AWAL%20APLIKASI%20%28SIMPLE%29.md) — versi behaviour, tanpa detail kode.
- [`SETUP/SETUP WIZARD.md`](SETUP/SETUP%20WIZARD.md), [`MASTER/TERMINAL LIST.md`](MASTER/TERMINAL%20LIST.md), [`AUTH/AUTH.md`](AUTH/AUTH.md) — dokumentasi endpoint backend masing-masing gerbang.
