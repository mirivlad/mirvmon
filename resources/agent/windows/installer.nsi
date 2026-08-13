Unicode true
!include LogicLib.nsh
ManifestSupportedOS all
RequestExecutionLevel admin
SetCompressor /SOLID lzma
SilentInstall normal
AutoCloseWindow true
ShowInstDetails show

!ifndef PAYLOAD_DIR
  !error "PAYLOAD_DIR is required"
!endif
!ifndef OUTPUT_FILE
  !error "OUTPUT_FILE is required"
!endif

Name "MirvMon Agent"
OutFile "${OUTPUT_FILE}"
InstallDir "$PROGRAMFILES64\MirvMon\Agent"
BrandingText "MirvMon"

Section "Install MirvMon Agent"
  SetShellVarContext all
  SetOutPath "$PLUGINSDIR"
  File /oname=mirvmon-install.ps1 "${PAYLOAD_DIR}/mirvmon-install.ps1"
  File /oname=mirvmon-agent-modern.exe "${PAYLOAD_DIR}/mirvmon-agent-modern.exe"
  File /oname=mirvmon-agent-legacy.exe "${PAYLOAD_DIR}/mirvmon-agent-legacy.exe"
  File /oname=bootstrap.json "${PAYLOAD_DIR}/bootstrap.json"

  nsExec::ExecToLog '"$SYSDIR\WindowsPowerShell\v1.0\powershell.exe" -NoLogo -NoProfile -NonInteractive -ExecutionPolicy Bypass -File "$PLUGINSDIR\mirvmon-install.ps1"'
  Pop $0
  ${If} $0 != 0
    MessageBox MB_ICONSTOP "MirvMon Agent installation failed. Review the installation log above."
    SetErrorLevel $0
    Abort
  ${EndIf}
  SetErrorLevel 0
SectionEnd
