#Requires -RunAsAdministrator
<#
  Uninstall semua background job posv1-laravel yang diinstall lewat install-services.ps1.
  Daftar service HARUS sinkron sama $Jobs di install-services.ps1.

  Pemakaian:
    .\uninstall-services.ps1
    .\uninstall-services.ps1 -NssmPath "C:\tools\nssm.exe"

  Default -NssmPath: nssm.exe di folder yang sama kayak script ini (deploy/nssm/nssm.exe).
#>
param(
    [string]$NssmPath = (Join-Path $PSScriptRoot "nssm.exe")
)

$ErrorActionPreference = "Continue"

# Cek keberadaan file doang (Test-Path), BUKAN nyoba eksekusi nssm.exe -- lihat catatan di
# install-services.ps1 soal gotcha redirect stderr proses native di Windows PowerShell 5.1.
if (-not (Test-Path $NssmPath)) {
    Write-Error "nssm.exe tidak ditemukan di '$NssmPath'. Pass -NssmPath '<lokasi nssm.exe>' kalau lokasinya beda."
    exit 1
}

$Services = @(
    "POS-SendHeartbeat",
    "POS-PullMobileOrder",
    "POS-KioskCheckPendingPayment",
    "POS-SyncPush"
)

foreach ($svc in $Services) {
    # SENGAJA gak redirect stderr (sama alasan kayak install-services.ps1) -- cuma stdout yang
    # di-suppress, "Can't open service!" dari nssm buat service yang gak ada itu normal.
    & $NssmPath status $svc | Out-Null
    if ($LASTEXITCODE -eq 0) {
        Write-Host "Stop & remove: $svc"
        & $NssmPath stop $svc | Out-Null
        & $NssmPath remove $svc confirm | Out-Null
    } else {
        Write-Host "Skip (service gak ada): $svc"
    }
}

Write-Host "Selesai uninstall."
