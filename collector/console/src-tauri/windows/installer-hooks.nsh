!include "LogicLib.nsh"
!include "nsDialogs.nsh"

Var NexaId
Var NexaToken
Var NexaApi
Var NexaProxy
Var NexaProxyUser
Var NexaProxyPassword
Var NexaIdInput
Var NexaTokenInput
Var NexaApiInput
Var NexaProxyInput
Var NexaProxyUserInput
Var NexaProxyPasswordInput

; Tauri includes hooks before its standard pages. Existing bindings and
; unattended installs skip this page; unattended provisioning uses environment
; variables inherited by the installer process, never command-line secrets.
Page custom NexaProvisionPage NexaProvisionLeave

Function NexaProvisionPage
  IfSilent 0 +2
    Abort
  IfFileExists "$COMMONPROGRAMDATA\NexaCollector\credentials\NEXA_COLLECTOR_TOKEN" 0 +2
    Abort
  nsDialogs::Create 1018
  Pop $0
  ${If} $0 == error
    Abort
  ${EndIf}
  !insertmacro MUI_HEADER_TEXT "Vincular Collector" "Use o ID e a chave exibidos uma unica vez no portal administrativo."
  ${NSD_CreateLabel} 0 0 100% 16u "Dados do cliente e da rede. O proxy e opcional."
  Pop $0
  ${NSD_CreateLabel} 0 18u 100% 10u "ID do Collector"
  Pop $0
  ${NSD_CreateText} 0 29u 100% 12u "$NexaId"
  Pop $NexaIdInput
  ${NSD_CreateLabel} 0 44u 100% 10u "Chave do Collector (portal administrativo)"
  Pop $0
  ${NSD_CreatePassword} 0 55u 100% 12u "$NexaToken"
  Pop $NexaTokenInput
  ${NSD_CreateLabel} 0 70u 100% 10u "URL HTTPS da API Nexa"
  Pop $0
  ${NSD_CreateText} 0 81u 100% 12u "$NexaApi"
  Pop $NexaApiInput
  ${NSD_CreateLabel} 0 96u 100% 10u "Proxy HTTP/HTTPS (opcional, ex.: http://proxy:8080)"
  Pop $0
  ${NSD_CreateText} 0 107u 100% 12u "$NexaProxy"
  Pop $NexaProxyInput
  ${NSD_CreateLabel} 0 122u 100% 10u "Usuario do proxy (opcional)"
  Pop $0
  ${NSD_CreateText} 0 133u 100% 12u "$NexaProxyUser"
  Pop $NexaProxyUserInput
  ${NSD_CreateLabel} 0 148u 100% 10u "Senha do proxy (opcional)"
  Pop $0
  ${NSD_CreatePassword} 0 159u 100% 12u "$NexaProxyPassword"
  Pop $NexaProxyPasswordInput
  nsDialogs::Show
FunctionEnd

Function NexaProvisionLeave
  ${NSD_GetText} $NexaIdInput $NexaId
  ${NSD_GetText} $NexaTokenInput $NexaToken
  ${NSD_GetText} $NexaApiInput $NexaApi
  ${NSD_GetText} $NexaProxyInput $NexaProxy
  ${NSD_GetText} $NexaProxyUserInput $NexaProxyUser
  ${NSD_GetText} $NexaProxyPasswordInput $NexaProxyPassword
  StrLen $0 $NexaId
  ${If} $0 != 36
    MessageBox MB_ICONSTOP|MB_OK "Informe o ID de 36 caracteres exibido no portal administrativo." /SD IDOK
    Abort
  ${EndIf}
  StrLen $0 $NexaToken
  ${If} $0 != 64
    MessageBox MB_ICONSTOP|MB_OK "Informe a chave de 64 caracteres exibida no portal administrativo." /SD IDOK
    Abort
  ${EndIf}
  StrCpy $0 $NexaApi 8
  ${If} $0 != "https://"
    MessageBox MB_ICONSTOP|MB_OK "Informe a URL da API Nexa com HTTPS." /SD IDOK
    Abort
  ${EndIf}
  ${If} $NexaProxy != ""
    StrCpy $0 $NexaProxy 7
    StrCpy $1 $NexaProxy 8
    ${If} $0 != "http://"
    ${AndIf} $1 != "https://"
      MessageBox MB_ICONSTOP|MB_OK "O endereco do proxy deve comecar com http:// ou https://." /SD IDOK
      Abort
    ${EndIf}
  ${EndIf}
  ${If} $NexaProxyUser != ""
  ${OrIf} $NexaProxyPassword != ""
    ${If} $NexaProxy == ""
    ${OrIf} $NexaProxyUser == ""
    ${OrIf} $NexaProxyPassword == ""
      MessageBox MB_ICONSTOP|MB_OK "Para autenticar no proxy, informe URL, usuario e senha." /SD IDOK
      Abort
    ${EndIf}
  ${EndIf}
FunctionEnd

; Embedded in the native NSIS installer. No PowerShell required.

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

  ; The credential request is a temporary UTF-16 file below the private ACL.
  ; Rust validates it, writes UTF-8 config and secrets, then removes it.
  IfFileExists "$COMMONPROGRAMDATA\NexaCollector\credentials\NEXA_COLLECTOR_TOKEN" nexa_provisioned 0
  IfSilent nexa_silent_inputs nexa_write_request
  nexa_silent_inputs:
    ReadEnvStr $NexaId NEXA_INSTALL_COLLECTOR_ID
    ReadEnvStr $NexaToken NEXA_INSTALL_TOKEN
    ReadEnvStr $NexaApi NEXA_INSTALL_API_URL
    ReadEnvStr $NexaProxy NEXA_INSTALL_PROXY_URL
    ReadEnvStr $NexaProxyUser NEXA_INSTALL_PROXY_USERNAME
    ReadEnvStr $NexaProxyPassword NEXA_INSTALL_PROXY_PASSWORD
  nexa_write_request:
    FileOpen $9 "$COMMONPROGRAMDATA\NexaCollector\credentials\install-request.utf16" w
    IfErrors nexa_request_failed
    FileWriteUTF16LE /BOM $9 "$NexaId$\r$\n$NexaToken$\r$\n$NexaApi$\r$\n$NexaProxy$\r$\n$NexaProxyUser$\r$\n$NexaProxyPassword$\r$\n"
    IfErrors nexa_request_failed_close
    FileClose $9
    ExecWait '"$INSTDIR\nexa-collector-daemon.exe" --provision-windows' $0
    ${If} $0 != "0"
      MessageBox MB_ICONSTOP|MB_OK "Falha ao validar o ID, a chave, a URL ou o proxy. Revise os dados no portal e execute o instalador novamente." /SD IDOK
      SetErrorLevel 22
      !insertmacro NEXA_DIAGNOSTIC 22
      IfSilent 0 +2
        Quit
      Abort "Nexa Collector provisioning failed"
    ${EndIf}
    Goto nexa_provisioned
  nexa_request_failed_close:
    FileClose $9
  nexa_request_failed:
    MessageBox MB_ICONSTOP|MB_OK "Nao foi possivel gravar os dados protegidos do Collector." /SD IDOK
    SetErrorLevel 23
    !insertmacro NEXA_DIAGNOSTIC 23
    IfSilent 0 +2
      Quit
    Abort "Nexa Collector protected request failure"
  nexa_provisioned:

  ; Fail closed when SCM rejects the request. Collect the operating system
  ; error code and sc.exe output so support can distinguish access denied,
  ; invalid account, incorrect arguments, or an orphaned existing service.
  ; The command contains no authentication tokens or customer secrets.
  ; sc.exe must receive one binPath value containing literal quotes around
  ; the executable. Without the backslashes, Program Files becomes 3 args.
  nsExec::ExecToStack '"$SYSDIR\sc.exe" create NexaCollector type= own binPath= "\$\"$INSTDIR\nexa-collector-daemon.exe\$\" --service" start= demand obj= "NT AUTHORITY\LocalService" DisplayName= "Nexa Collector"'
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
  ; Provisioning is complete; enable startup and verify the actual service.
  ExecWait '"$SYSDIR\sc.exe" config NexaCollector start= auto' $0
  ${If} $0 != "0"
    ExecWait '"$SYSDIR\sc.exe" delete NexaCollector' $1
    MessageBox MB_ICONSTOP|MB_OK "Falha ao habilitar inicio automatico do Collector." /SD IDOK
    SetErrorLevel 24
    !insertmacro NEXA_DIAGNOSTIC 24
    IfSilent 0 +2
      Quit
    Abort "Nexa Collector automatic startup configuration failed"
  ${EndIf}
  nsExec::ExecToStack '"$SYSDIR\sc.exe" start NexaCollector'
  Pop $0
  Pop $1
  ${If} $0 != "0"
    ExecWait '"$SYSDIR\sc.exe" delete NexaCollector' $2
    MessageBox MB_ICONSTOP|MB_OK "O Collector foi configurado, mas o servico nao iniciou (codigo $0).$\r$\n$1" /SD IDOK
    SetErrorLevel 25
    !insertmacro NEXA_DIAGNOSTIC 25
    IfSilent 0 +2
      Quit
    Abort "Nexa Collector service startup failed"
  ${EndIf}
  DetailPrint "NexaCollector vinculado e iniciado."
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
