@echo off
:: Double-click buat install semua service (heartbeat:send, mobile-order:pull,
:: kiosk:check-pending-payment, sync:push) lewat NSSM. Auto-minta admin (UAC prompt) kalau
:: belum elevated. Isinya cuma pemanggil install-services.ps1 -- lihat file itu buat detail/opsi
:: (-PhpPath, -NssmPath) kalau butuh override default.
net session >nul 2>&1
if %errorLevel% == 0 goto :run

echo Minta hak Administrator (bakal muncul prompt UAC)...
powershell -NoProfile -Command "Start-Process -FilePath '%~f0' -Verb RunAs"
exit /b

:run
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0install-services.ps1"
echo.
pause
