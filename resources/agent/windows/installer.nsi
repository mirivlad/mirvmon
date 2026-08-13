Unicode true
!include LogicLib.nsh
!include WinVer.nsh
!include x64.nsh
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
!ifndef EXPECTED_VERSION
  !error "EXPECTED_VERSION is required"
!endif
!ifndef MODERN_SHA256
  !error "MODERN_SHA256 is required"
!endif
!ifndef MODERN_SIZE
  !error "MODERN_SIZE is required"
!endif
!ifndef LEGACY_SHA256
  !error "LEGACY_SHA256 is required"
!endif
!ifndef LEGACY_SIZE
  !error "LEGACY_SIZE is required"
!endif

Name "MirvMon Agent"
OutFile "${OUTPUT_FILE}"
InstallDir "$PROGRAMFILES64\MirvMon\Agent"
BrandingText "MirvMon"

Var SelectedAgent
Var SelectedArtifact
Var SelectedSHA256
Var SelectedSize
Var PrivatePayload

Section "Install MirvMon Agent"
  SetShellVarContext all
  InitPluginsDir
  StrCpy $PrivatePayload "$PLUGINSDIR\payload"
  CreateDirectory "$PrivatePayload"
  nsExec::ExecToStack /OEM '"$SYSDIR\icacls.exe" "$PrivatePayload" /inheritance:r /grant:r *S-1-5-18:(OI)(CI)F *S-1-5-32-544:(OI)(CI)F'
  Pop $0
  Pop $1
  ${If} $0 != 0
    MessageBox MB_ICONSTOP "MirvMon Agent cannot protect its temporary payload."
    SetErrorLevel $0
    Abort
  ${EndIf}
  SetOutPath "$PrivatePayload"
  File /oname=mirvmon-agent-modern.exe "${PAYLOAD_DIR}/mirvmon-agent-modern.exe"
  File /oname=mirvmon-agent-legacy.exe "${PAYLOAD_DIR}/mirvmon-agent-legacy.exe"
  File /oname=bootstrap.json "${PAYLOAD_DIR}/bootstrap.json"

  ${IfNot} ${RunningX64}
    MessageBox MB_ICONSTOP "MirvMon Agent supports only x64 Windows."
    SetErrorLevel 2
    Abort
  ${EndIf}

  ${If} ${AtLeastWin10}
    StrCpy $SelectedAgent "$PrivatePayload\mirvmon-agent-modern.exe"
    StrCpy $SelectedArtifact "windows-amd64"
    StrCpy $SelectedSHA256 "${MODERN_SHA256}"
    StrCpy $SelectedSize "${MODERN_SIZE}"
  ${ElseIf} ${AtLeastWin7}
    StrCpy $SelectedAgent "$PrivatePayload\mirvmon-agent-legacy.exe"
    StrCpy $SelectedArtifact "windows-legacy-amd64"
    StrCpy $SelectedSHA256 "${LEGACY_SHA256}"
    StrCpy $SelectedSize "${LEGACY_SIZE}"
  ${Else}
    MessageBox MB_ICONSTOP "This Windows version is not supported by MirvMon Agent."
    SetErrorLevel 2
    Abort
  ${EndIf}

  nsExec::ExecToLog /OEM '"$SelectedAgent" install-windows --bootstrap "$PrivatePayload\bootstrap.json" --expected-version "${EXPECTED_VERSION}" --expected-artifact "$SelectedArtifact" --expected-sha256 "$SelectedSHA256" --expected-size "$SelectedSize"'
  Pop $0
  ${If} $0 != 0
    MessageBox MB_ICONSTOP "MirvMon Agent installation failed. Review the installation log above."
    SetErrorLevel $0
    Abort
  ${EndIf}
  SetErrorLevel 0
SectionEnd
