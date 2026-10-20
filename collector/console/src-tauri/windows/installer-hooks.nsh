!include "LogicLib.nsh"

; Embedded in the native NSIS installer. No PowerShell required.
!define NEXA_DEFAULT_CONFIG "${__FILEDIR__}\..\..\..\config.example.json"

; Record only the failure stage for unattended installer diagnostics.
!macro NEXA_DIAGNOSTIC CODE
  FileOpen $9 "$TEMP\NexaCollector-installer-error.txt" w
  FileWrite $9 "${CODE}$\r$\n"
  FileClose $9
!macroend

!macro NSIS_HOOK_PREINSTALL
  ; Refuse to install over a service that is running, marked for deletion,
  ; or cannot be inspected. Never overwrite a customer's existing binding.
  nsExec::ExecToStack '"$SYSDIR\sc.exe" query NexaCollector'
  Pop $0
  Pop $1
  ${If} $0 == "0"
    MessageBox MB_ICONSTOP|MB_OK "O servico NexaCollector ja existe. Desinstale a versao anterior primeiro. Os dados do cliente serao preservados." /SD IDOK
    SetErrorLevel 10
    !insertmacro NEXA_DIAGNOSTIC 10
    IfSilent 0 +2
      Quit
    Abort "Existing NexaCollector Windows service"
  ${ElseIf} $0 != "1060"
    MessageBox MB_ICONSTOP|MB_OK "Nao foi possivel verificar o servico NexaCollector (codigo $0).$\r$\n$1" /SD IDOK
    SetErrorLevel 11
    !insertmacro NEXA_DIAGNOSTIC 11
    IfSilent 0 +2
      Quit
    Abort "Nexa Collector service state unavailable"
  ${EndIf}
!macroend

!macro NSIS_HOOK_POSTINSTALL
  IfFileExists "$INSTDIR\nexa-collector-daemon.exe" nexa_daemon_ok 0
    MessageBox MB_ICONSTOP|MB_OK "O executavel do servico nao foi incluido no instalador." /SD IDOK
    SetErrorLevel 12
    !insertmacro NEXA_DIAGNOSTIC 12
    IfSilent 0 +2
      Quit
    Abort "Missing Nexa Collector daemon"
  nexa_daemon_ok:

  ; $COMMONPROGRAMDATA is the NSIS known-folder constant (%ProgramData%).
  ; The former $COMMONAPPDATA was not an NSIS constant and was passed
  ; literally to icacls, causing the installation to abort.
  ; State survives uninstall, under a controlled ProgramData ACL.
  CreateDirectory "$COMMONPROGRAMDATA\NexaCollector"
  CreateDirectory "$COMMONPROGRAMDATA\NexaCollector\data"
  CreateDirectory "$COMMONPROGRAMDATA\NexaCollector\credentials"

  ; SIDs: SYSTEM/Administrators full; LocalService RX; Users RX for config/UI.
  ; The service runs as LocalService. Never grant Users write access to secrets.
  ExecWait '"$SYSDIR\icacls.exe" "$COMMONPROGRAMDATA\NexaCollector" /inheritance:r /grant:r "*S-1-5-18:(OI)(CI)F" "*S-1-5-32-544:(OI)(CI)F" "*S-1-5-19:(OI)(CI)RX" "*S-1-5-32-545:(OI)(CI)RX"' $0
  ${If} $0 != "0"
    MessageBox MB_ICONSTOP|MB_OK "Falha ao proteger a pasta do Nexa Collector." /SD IDOK
    SetErrorLevel 13
    !insertmacro NEXA_DIAGNOSTIC 13
    IfSilent 0 +2
      Quit
    Abort "Nexa Collector root ACL failure"
  ${EndIf}

  ; Keep SQLite/WAL/SHM and secrets private, and allow service data writes.
  ExecWait '"$SYSDIR\icacls.exe" "$COMMONPROGRAMDATA\NexaCollector\data" /inheritance:r /grant:r "*S-1-5-18:(OI)(CI)F" "*S-1-5-32-544:(OI)(CI)F" "*S-1-5-19:(OI)(CI)M"' $0
  ${If} $0 != "0"
    MessageBox MB_ICONSTOP|MB_OK "Falha ao proteger a fila local de medicoes." /SD IDOK
    SetErrorLevel 14
    !insertmacro NEXA_DIAGNOSTIC 14
    IfSilent 0 +2
      Quit
    Abort "Nexa Collector data ACL failure"
  ${EndIf}

  ExecWait '"$SYSDIR\icacls.exe" "$COMMONPROGRAMDATA\NexaCollector\credentials" /inheritance:r /grant:r "*S-1-5-18:(OI)(CI)F" "*S-1-5-32-544:(OI)(CI)F" "*S-1-5-19:(OI)(CI)R"' $0
  ${If} $0 != "0"
    MessageBox MB_ICONSTOP|MB_OK "Falha ao proteger as credenciais do agente." /SD IDOK
    SetErrorLevel 15
    !insertmacro NEXA_DIAGNOSTIC 15
    IfSilent 0 +2
      Quit
    Abort "Nexa Collector credential ACL failure"
  ${EndIf}

  ; Create example config only on clean install; never overwrite binding.
  IfFileExists "$COMMONPROGRAMDATA\NexaCollector\config.json" nexa_config_exists 0
    SetOutPath "$COMMONPROGRAMDATA\NexaCollector"
    File /oname=config.json "${NEXA_DEFAULT_CONFIG}"
    SetOutPath "$INSTDIR"
  nexa_config_exists:

  ; Fail closed when SCM rejects the request. Collect the operating system
  ; error code and sc.exe output so support can distinguish access denied,
  ; invalid account, incorrect arguments, or an orphaned existing service.
  ; The command contains no authentication tokens or customer secrets.
  nsExec::ExecToStack '"$SYSDIR\sc.exe" create NexaCollector type= own binPath= "$\"$INSTDIR\nexa-collector-daemon.exe$\" --service" start= demand obj= "NT AUTHORITY\LocalService" DisplayName= "Nexa Collector"'
  Pop $0
  Pop $1
  ${If} $0 != "0"
    DetailPrint "SCM create returned exit code $0: $1"
    MessageBox MB_ICONSTOP|MB_OK "Falha ao registrar NexaCollector (codigo $0).$\r$\n$1$\r$\nDados do cliente preservados. Informe o codigo de erro ao suporte." /SD IDOK
    SetErrorLevel 16
    !insertmacro NEXA_DIAGNOSTIC 16
    ; The command contains no authentication material; retain only SCM's
    ; return code and output for an unattended installation failure.
    FileOpen $9 "$TEMP\NexaCollector-installer-error.txt" a
    FileSeek $9 0 END
    FileWrite $9 "SCM exit: $0$\r$\nSCM output: $1$\r$\n"
    FileClose $9
    IfSilent 0 +2
      Quit
    Abort "Nexa Collector registration failure"
  ${EndIf}

  ; Inspect the registration before configuring recovery. On failure,
  ; remove only the service just created by this same installer.
  nsExec::ExecToStack '"$SYSDIR\sc.exe" qc NexaCollector'
  Pop $0
  Pop $1
  ${If} $0 != "0"
    ExecWait '"$SYSDIR\sc.exe" delete NexaCollector' $2
    MessageBox MB_ICONSTOP|MB_OK "Nao foi possivel verificar o servico registrado (codigo $0).$\r$\n$1" /SD IDOK
    SetErrorLevel 17
    !insertmacro NEXA_DIAGNOSTIC 17
    IfSilent 0 +2
      Quit
    Abort "Nexa Collector registration verification failure"
  ${EndIf}

  ExecWait '"$SYSDIR\sc.exe" failure NexaCollector reset= 86400 actions= restart/15000/restart/60000/none/0' $0
  StrCmp $0 "0" nexa_recovery_configured
    ExecWait '"$SYSDIR\sc.exe" delete NexaCollector' $1
    MessageBox MB_ICONSTOP|MB_OK "Falha ao configurar recuperacao do servico." /SD IDOK
    SetErrorLevel 18
    !insertmacro NEXA_DIAGNOSTIC 18
    IfSilent 0 +2
      Quit
    Abort "Nexa Collector recovery failure"
  nexa_recovery_configured:

  ExecWait '"$SYSDIR\sc.exe" failureflag NexaCollector 1' $0
  StrCmp $0 "0" nexa_installed
    ExecWait '"$SYSDIR\sc.exe" delete NexaCollector' $1
    MessageBox MB_ICONSTOP|MB_OK "Falha ao configurar recuperacao do servico." /SD IDOK
    SetErrorLevel 19
    !insertmacro NEXA_DIAGNOSTIC 19
    IfSilent 0 +2
      Quit
    Abort "Nexa Collector failure flag error"
  nexa_installed:
  DetailPrint "NexaCollector instalado com inicio manual ate vinculacao."
!macroend

!macro NSIS_HOOK_PREUNINSTALL
  ; Delete the SCM entry before Tauri removes the service binary.
  nsExec::ExecToStack '"$SYSDIR\sc.exe" query NexaCollector'
  Pop $0
  Pop $1
  ${If} $0 == "1060"
    Goto nexa_uninstall_no_service
  ${ElseIf} $0 != "0"
    MessageBox MB_ICONSTOP|MB_OK "Nao foi possivel verificar o servico NexaCollector (codigo $0).$\r$\n$1" /SD IDOK
    SetErrorLevel 20
    !insertmacro NEXA_DIAGNOSTIC 20
    IfSilent 0 +2
      Quit
    Abort "Nexa Collector uninstall service state unavailable"
  ${EndIf}
  ExecWait '"$SYSDIR\sc.exe" stop NexaCollector' $1
  Sleep 2000
  ExecWait '"$SYSDIR\sc.exe" delete NexaCollector' $1
  StrCmp $1 "0" nexa_uninstall_no_service
    MessageBox MB_ICONSTOP|MB_OK "O servico NexaCollector nao pode ser removido. Feche o agente e tente novamente." /SD IDOK
    SetErrorLevel 21
    !insertmacro NEXA_DIAGNOSTIC 21
    IfSilent 0 +2
      Quit
    Abort "Nexa Collector service removal failed"
  nexa_uninstall_no_service:
!macroend

!macro NSIS_HOOK_POSTUNINSTALL
  ; Preserve existing encrypted queue, config, client binding and credentials.
  DetailPrint "Dados do Nexa Collector preservados em ProgramData."
!macroend
