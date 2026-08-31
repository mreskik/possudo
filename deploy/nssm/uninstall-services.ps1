#Requires -RunAsAdministrator
<#
  Uninstall semua background job posv1-laravel yang diinstall lewat install-services.ps1.
  Daftar service HARUS sinkron sama $Jobs di install-services.ps1.

  Pemakaian:
    .\uninstall-services.ps1
    .\uninstall-services.ps1 -NssmPath "C:\tools\nssm.exe"
#>
param(
    [string]$NssmPath = "nssm"
)

$ErrorActionPreference = "Continue"

try {
    & $NssmPath 2>&1 | Out-Null
} catch {
    Write-Error "nssm.exe tidak ditemukan (path dicoba: '$NssmPath'). Pass -NssmPath '<lokasi nssm.exe>' kalau gak ada di PATH."
    exit 1
}

$Services = @(
    "POS-SendHeartbeat",
    "POS-PullMobileOrder",
    "POS-KioskCheckPendingPayment",
    "POS-SyncPush"
)

foreach ($svc in $Services) {
    & $NssmPath status $svc *> $null
    if ($LASTEXITCODE -eq 0) {
        Write-Host "Stop & remove: $svc"
        & $NssmPath stop $svc *> $null
        & $NssmPath remove $svc confirm *> $null
    } else {
        Write-Host "Skip (service gak ada): $svc"
    }
}

Write-Host "Selesai uninstall."
