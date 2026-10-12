# System

Base path: `/api/system`

2 endpoint — info versi aplikasi dan status kesehatan background job. Tanpa auth, tanpa relasi ke `mr_branch` (bisa dipanggil kapan saja, termasuk sebelum install).

## App Version

```
GET /api/system/app-version
```

Baca langsung dari `.env` (`APP_VERSION`/`APP_RELEASE_DATE`) — tidak ada query DB/service terpisah.

Response:
```json
{
  "code": 0,
  "data": {
    "version": "1.4.2",
    "release_date": "2026-10-01"
  }
}
```

- `version` — fallback `"unknown"` kalau `APP_VERSION` tidak di-set.
- Dipakai di: Lockscreen + footer sidebar dashboard (`useAppVersion.js`), sekadar menampilkan versi POS yang sedang berjalan ke user.

## Jobs Health

```
GET /api/system/jobs-health
```

Status kesehatan 4 background job/command — dibaca dari `sys_job_health` (diisi `JobHealthReporter` dari dalam loop tiap job, bukan di-query real-time ke proses yang berjalan).

Response:
```json
{
  "code": 0,
  "data": {
    "overall_status": "ok",
    "checked_at": "2026-10-12T14:30:00+07:00",
    "jobs": [
      {
        "job_name": "heartbeat:send",
        "status": "ok",
        "last_tick_at": "2026-10-12T14:29:45+07:00",
        "last_success_at": "2026-10-12T14:29:45+07:00",
        "seconds_since_last_tick": 15,
        "expected_interval_seconds": 30,
        "last_error_at": null,
        "last_error_message": null
      },
      {
        "job_name": "kiosk:check-pending-payment",
        "status": "ok",
        "expected_interval_seconds": 60,
        "...": "..."
      },
      {
        "job_name": "mobile-order:pull",
        "status": "ok",
        "expected_interval_seconds": null,
        "...": "..."
      },
      {
        "job_name": "sync:push",
        "status": "ok",
        "expected_interval_seconds": 120,
        "...": "..."
      }
    ]
  }
}
```

### 4 job yang dimonitor

| `job_name` | Interval diharapkan | Catatan |
|---|---|---|
| `heartbeat:send` | 30 detik | Kirim heartbeat ke sudomobile — lihat `DOKUMENTASI BACKGROUND JOB/SEND HEARTBEAT.md` |
| `kiosk:check-pending-payment` | 60 detik | |
| `mobile-order:pull` | `null` (tidak ada interval tetap) | **Event-driven** (WebSocket), bukan polling — statusnya cuma dibedakan `ok` (pernah tick) vs `unknown` (belum pernah tick), tidak pernah `stale` |
| `sync:push` | `null` di konstanta, **di-resolve dari `.env`** (`SYNC_PUSH_INTERVAL_SECONDS`, default 120) | Lihat [`PUSH DATA.md`](PUSH%20DATA.md) |

### Status per job — 4 kemungkinan

| Status | Kapan |
|---|---|
| `ok` | Tick terakhir dalam batas wajar, tidak ada kegagalan terbaru |
| `degraded` | Proses **hidup** (masih tick), tapi kegagalan **terakhir** lebih baru dari sukses terakhir — berarti putaran-putaran belakangan gagal terus. **Ini yang tidak terdeteksi NSSM** (NSSM cuma cek PID alive, bukan isi hasil kerjanya) |
| `stale` | `seconds_since_last_tick > expected_interval × 3` — job kemungkinan macet/mati, sudah lama tidak tick sama sekali |
| `unknown` | Belum pernah ada row di `sys_job_health` buat job ini (baru install / job belum pernah jalan) |

`overall_status` di root = status **terburuk** dari keempat job (severity: `stale` > `degraded` > `unknown` > `ok`).

## Alur Implementasi

Dipakai di `JobsHealthStatus.vue` (badge "Jobs" di navbar, icon HeartPulse) — fetch sekali saat halaman dimuat (`onMounted`), badge warna sesuai `overallStatus` (dot merah/kuning/hijau/abu). Klik badge buka popover detail per-job, dengan tombol refresh manual. Gagal fetch (network error dkk) → fallback diam-diam ke status `unknown` (bukan toast error) — ini murni indikator sekunder, tidak boleh mengganggu alur kerja utama kasir.

## Sumber

- `app/Http/Controllers/SystemController.php` — `AppVersion()`, `JobsHealth()`.
- `app/Services/JobHealthReporter.php` — `EXPECTED_INTERVAL_SECONDS`, penulisan `sys_job_health`.
- `routes/api.php` — group `Route::prefix('system')`.
- Frontend: `src/composables/useAppVersion.js`, `src/component/JobsHealthStatus.vue`.

## Status

Didokumentasikan 2026-10-12, berdasar pembacaan kode langsung (belum ada verifikasi ulang lewat HTTP request live di sesi ini).
