!include "LogicLib.nsh"

; Embedded in the native NSIS installer. No PowerShell required.
!define NEXA_DEFAULT_CONFIG "${__FILEDIR__}\..\..\..\config.example.json"

!macro NSIS_HOOK_PREINSTALL
  nsExec::ExecToStack '"$SYSDIR\sc.exe" query NexaCollector'
  Pop $0
  Pop $1
  StrCmp $0 "0" 0 nexa_service_not_installed
    MessageBox MB_ICONSTOP|MB_OK "O serviço NexaCollector ja existe. Revise a instalacao anterior antes de atualizar; os dados serao preservados."
    Abort "Existing NexaCollector Windows service"
  nexa_service_not_installed:
!macroend

!macro NSIS_HOOK_POSTINSTALL
  IfFileExists "$INSTDIR\nexa-collector-daemon.exe" nexa_daemon_ok 0
    MessageBox MB_ICONSTOP|MB_OK "O executavel do servico nao foi incluido no instalador."
    Abort "Missing Nexa Collector daemon"
  nexa_daemon_ok:

  ; State survives uninstall, under a controlled ProgramData ACL.
  CreateDirectory "$COMMONAPPDATA\NexaCollector"
  CreateDirectory "$COMMONAPPDATA\NexaCollector\data"
  CreateDirectory "$COMMONAPPDATA\NexaCollector\credentials"

  ; SIDs: SYSTEM/Administrators full; LocalService RX; Users RX for config/UI.
  ExecWait '"$SYSDIR\icacls.exe" "$COMMONAPPDATA\NexaCollector" /inheritance:r /grant:r "*S-1-5-18:(OI)(CI)F" "*S-1-5-32-544:(OI)(CI)F" "*S-1-5-19:(OI)(CI)RX" "*S-1-5-32-545:(OI)(CI)RX"' $0
  StrCmp $0 "0" +3
    MessageBox MB_ICONSTOP|MB_OK "Falha ao proteger a pasta do Nexa Collector."
    Abort "Nexa Collector root ACL failure"

  ; Keep SQLite/WAL/SHM and secrets private, and allow service data writes.
  ExecWait '"$SYSDIR\icacls.exe" "$COMMONAPPDATA\NexaCollector\data" /inheritance:r /grant:r "*S-1-5-18:(OI)(CI)F" "*S-1-5-32-544:(OI)(CI)F" "*S-1-5-19:(OI)(CI)M"' $0
  StrCmp $0 "0" +3
    MessageBox MB_ICONSTOP|MB_OK "Falha ao proteger a fila local de medicoes."
    Abort "Nexa Collector data ACL failure"

  ExecWait '"$SYSDIR\icacls.exe" "$COMMONAPPDATA\NexaCollector\credentials" /inheritance:r /grant:r "*S-1-5-18:(OI)(CI)F" "*S-1-5-32-544:(OI)(CI)F" "*S-1-5-19:(OI)(CI)R"' $0
  StrCmp $0 "0" +3
    MessageBox MB_ICONSTOP|MB_OK "Falha ao proteger as credenciais do agente."
    Abort "Nexa Collector credential ACL failure"

  ; Create example config only on clean install; never overwrite binding.
  IfFileExists "$COMMONAPPDATA\NexaCollector\config.json" nexa_config_exists 0
    SetOutPath "$COMMONAPPDATA\NexaCollector"
    File /oname=config.json "${NEXA_DEFAULT_CONFIG}"
    SetOutPath "$INSTDIR"
  nexa_config_exists:

  ; No secrets in SCM arguments; start after secure client provisioning only.
  ExecWait '"$SYSDIR\sc.exe" create NexaCollector binPath= "$\"$INSTDIR\nexa-collector-daemon.exe$\" --service" start= demand obj= "NT AUTHORITY\LocalService" DisplayName= "Nexa Collector"' $0
  StrCmp $0 "0" nexa_service_created
    MessageBox MB_ICONSTOP|MB_OK "Falha ao registrar o servico NexaCollector."
    Abort "Nexa Collector registration failure"
  nexa_service_created:

  ExecWait '"$SYSDIR\sc.exe" failure NexaCollector reset= 86400 actions= restart/15000/restart/60000/none/0' $0
  StrCmp $0 "0" nexa_recovery_configured
    ExecWait '"$SYSDIR\sc.exe" delete NexaCollector' $1
    MessageBox MB_ICONSTOP|MB_OK "Falha ao configurar recuperacao do servico."
    Abort "Nexa Collector recovery failure"
  nexa_recovery_configured:

  ExecWait '"$SYSDIR\sc.exe" failureflag NexaCollector 1' $0
  StrCmp $0 "0" nexa_installed
    ExecWait '"$SYSDIR\sc.exe" delete NexaCollector' $1
    MessageBox MB_ICONSTOP|MB_OK "Falha ao configurar recuperacao do servico."
    Abort "Nexa Collector failure flag error"
  nexa_installed:
  DetailPrint "NexaCollector instalado com inicio manual ate vinculacao."
!macroend

!macro NSIS_HOOK_PREUNINSTALL
  ; Delete the SCM entry before Tauri removes the service binary.
  nsExec::ExecToStack '"$SYSDIR\sc.exe" query NexaCollector'
  Pop $0
  Pop $1
  StrCmp $0 "0" 0 nexa_uninstall_no_service
    ExecWait '"$SYSDIR\sc.exe" stop NexaCollector' $1
    Sleep 2000
    ExecWait '"$SYSDIR\sc.exe" delete NexaCollector' $1
    StrCmp $1 "0" nexa_uninstall_no_service
      MessageBox MB_ICONSTOP|MB_OK "O servico NexaCollector nao pode ser removido. Feche o agente e tente novamente."
      Abort "Nexa Collector service removal failed"
  nexa_uninstall_no_service:
!macroend

!macro NSIS_HOOK_POSTUNINSTALL
  ; Preserve existing encrypted queue, config, client binding and credentials.
  DetailPrint "Dados do Nexa Collector preservados em ProgramData."
!macroend
