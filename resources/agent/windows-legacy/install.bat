@echo off
setlocal

net session >nul 2>&1
if errorlevel 1 (
  echo MirvMon installation failed: run install.bat as Administrator.
  exit /b 1
)

set "INSTALLER_DIR=%~dp0"
set "INSTALLER_PS1=%INSTALLER_DIR%mirvmon-install-legacy.ps1"
if not exist "%INSTALLER_PS1%" (
  echo MirvMon installation failed: mirvmon-install-legacy.ps1 is missing.
  exit /b 1
)

powershell.exe -NoLogo -NoProfile -NonInteractive -ExecutionPolicy Bypass -File "%INSTALLER_PS1%"
set "INSTALL_EXIT_CODE=%errorlevel%"
exit /b %INSTALL_EXIT_CODE%
