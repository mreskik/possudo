#Requires -RunAsAdministrator
<#
  Install semua background job posv1-laravel sebagai Windows Service lewat NSSM.
  Lihat DOKUMENTASI BACKGROUND JOB/POLA UMUM.md buat penjelasan tiap job.

  Prasyarat:
    - nssm.exe -- default-nya udah nyari di folder yang sama kayak script ini
      (deploy/nssm/nssm.exe), taruh nssm.exe di situ atau pass -NssmPath kalau lokasinya beda
    - Dijalankan sebagai Administrator (bikin Windows Service butuh privilege ini)
    - php.exe bisa diakses (default asumsi ada di PATH, kalau enggak pass -PhpPath)

  Pemakaian:
    .\install-services.ps1
    .\install-services.ps1 -PhpPath "C:\xampp\php\php.exe"
    .\install-services.ps1 -NssmPath "C:\tools\nssm.exe"

  Idempotent: kalau service dengan nama sama udah ada, di-stop+remove dulu baru diinstall ulang
  (jadi aman dijalanin ulang buat update config/path).
#>
param(
    [string]$PhpPath = "php",
    [string]$ProjectPath = (Resolve-Path (Join-Path $PSScriptRoot "..\..")),
    [string]$NssmPath = (Join-Path $PSScriptRoot "nssm.exe")
)

$ErrorActionPreference = "Stop"

# Cek keberadaan file doang (Test-Path), BUKAN nyoba eksekusi nssm.exe -- nssm.exe tanpa
# argumen balikin exit code non-zero + nulis ke stderr, yang kalau di-redirect (2>&1/2>$null/*>)
# di bawah $ErrorActionPreference="Stop" bikin PowerShell nganggep itu error fatal walau
# file-nya valid (gotcha redirect stderr proses native di Windows PowerShell 5.1).
if (-not (Test-Path $NssmPath)) {
    Write-Error "nssm.exe tidak ditemukan di '$NssmPath'. Download dari https://nssm.cc/download, taruh nssm.exe di situ atau jalankan script ini dengan -NssmPath '<lokasi nssm.exe>'."
    exit 1
}

$LogDir = Join-Path $ProjectPath "storage\logs"
if (-not (Test-Path $LogDir)) {
    New-Item -ItemType Directory -Path $LogDir -Force | Out-Null
}

# 1 baris = 1 background job (samain sama tabel "Daftar Job" di POLA UMUM.md).
# Tambah job baru di sini kalau ada Artisan Command baru yang perlu jalan terus di production.
$Jobs = @(
    @{ Service = "POS-SendHeartbeat";           Command = "heartbeat:send" },
    @{ Service = "POS-PullMobileOrder";         Command = "mobile-order:pull" },
    @{ Service = "POS-KioskCheckPendingPayment"; Command = "kiosk:check-pending-payment" },
    @{ Service = "POS-SyncPush";                Command = "sync:push" }
)

foreach ($job in $Jobs) {
    $svc = $job.Service
    $cmd = $job.Command

    Write-Host "== $svc ($cmd) =="

    # SENGAJA gak redirect stderr (lihat catatan di atas soal gotcha-nya) -- kalau service
    # belum ada, nssm nulis "Can't open service!" ke stderr, itu NORMAL/informatif, bukan error
    # yang perlu disembunyikan (cuma stdout yang di-suppress lewat Out-Null).
    & $NssmPath status $svc | Out-Null
    if ($LASTEXITCODE -eq 0) {
        Write-Host "  Service udah ada -> stop & remove dulu"
        & $NssmPath stop $svc | Out-Null
        & $NssmPath remove $svc confirm | Out-Null
    }

    & $NssmPath install $svc $PhpPath "artisan $cmd" | Out-Null
    & $NssmPath set $svc AppDirectory $ProjectPath | Out-Null
    & $NssmPath set $svc DisplayName "A SUDOPOS BG - $cmd" | Out-Null
    & $NssmPath set $svc Description " background job ($cmd). Dikelola lewat deploy/nssm/install-services.ps1." | Out-Null

    # Log ganda: file di sini buat stdout/stderr proses PHP, tambahan di luar Log::error()
    # Laravel yang udah jalan sendiri ke storage/logs/laravel.log (lihat POLA UMUM.md).
    & $NssmPath set $svc AppStdout (Join-Path $LogDir "$svc.out.log") | Out-Null
    & $NssmPath set $svc AppStderr (Join-Path $LogDir "$svc.err.log") | Out-Null
    & $NssmPath set $svc AppRotateFiles 1 | Out-Null
    & $NssmPath set $svc AppRotateOnline 1 | Out-Null
    & $NssmPath set $svc AppRotateBytes 10485760 | Out-Null

    # Auto-start pas boot + auto-restart kalau proses mati (crash atau di-kill).
    & $NssmPath set $svc Start SERVICE_AUTO_START | Out-Null
    & $NssmPath set $svc AppExit Default Restart | Out-Null
    & $NssmPath set $svc AppRestartDelay 5000 | Out-Null

    & $NssmPath start $svc | Out-Null
    Write-Host "  OK - started`n"
}

Write-Host "Selesai. Cek status: services.msc, atau 'nssm status <ServiceName>' per service di atas."
