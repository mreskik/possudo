@echo off
:: Double-click buat uninstall semua service yang diinstall install.bat. Auto-minta admin (UAC
:: prompt) kalau belum elevated. Isinya cuma pemanggil uninstall-services.ps1.
net session >nul 2>&1
if %errorLevel% == 0 goto :run

echo Minta hak Administrator (bakal muncul prompt UAC)...
powershell -NoProfile -Command "Start-Process -FilePath '%~f0' -Verb RunAs"
exit /b

:run
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0uninstall-services.ps1"
echo.
pause
