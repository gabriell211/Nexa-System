!include "LogicLib.nsh"
!include "nsDialogs.nsh"

Var NexaId
Var NexaToken
Var NexaApi
Var NexaMode
Var NexaProxy
Var NexaProxyHost
Var NexaProxyPort
Var NexaUseProxy
Var NexaProxyUser
Var NexaProxyPassword
Var NexaProxyDomain
Var NexaMailAddress
Var NexaMailPassword
Var NexaMailRecipient
Var NexaPop3Host
Var NexaPop3Port
Var NexaPop3Ssl
Var NexaSmtpHost
Var NexaSmtpPort
Var NexaSmtpSsl
Var NexaIdInput
Var NexaTokenInput
Var NexaApiInput
Var NexaWebRadio
Var NexaMailRadio
Var NexaProxyInput
Var NexaProxyPortInput
Var NexaUseProxyInput
Var NexaProxyUserInput
Var NexaProxyPasswordInput
Var NexaProxyDomainInput
Var NexaMailAddressInput
Var NexaMailPasswordInput
Var NexaMailRecipientInput
Var NexaPop3HostInput
Var NexaPop3PortInput
Var NexaPop3SslInput
Var NexaSmtpHostInput
Var NexaSmtpPortInput
Var NexaSmtpSslInput

; Tauri includes hooks before its standard pages. Existing bindings and
; unattended installs skip this page; unattended provisioning uses environment
; variables inherited by the installer process, never command-line secrets.
Page custom NexaProvisionPage NexaProvisionLeave
Page custom NexaCommunicationPage NexaCommunicationLeave
Page custom NexaProxyPage NexaProxyLeave
Page custom NexaMailIncomingPage NexaMailIncomingLeave
Page custom NexaMailOutgoingPage NexaMailOutgoingLeave

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
  !insertmacro MUI_HEADER_TEXT "Chave do cliente Nexa" "Use a chave exibida uma unica vez no portal administrativo."
  ${NSD_CreateLabel} 0 0 100% 16u "Vincule este agente com o ID e a chave proprios do Nexa."
  Pop $0
  ${NSD_CreateLabel} 0 18u 100% 10u "ID do Collector"
  Pop $0
  ${NSD_CreateText} 0 29u 100% 12u "$NexaId"
  Pop $NexaIdInput
  ${NSD_CreateLabel} 0 44u 100% 10u "Chave do Collector (portal administrativo)"
  Pop $0
  ${NSD_CreatePassword} 0 55u 100% 12u "$NexaToken"
  Pop $NexaTokenInput
  nsDialogs::Show
FunctionEnd

Function NexaProvisionLeave
  ${NSD_GetText} $NexaIdInput $NexaId
  ${NSD_GetText} $NexaTokenInput $NexaToken
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
FunctionEnd

Function NexaCommunicationPage
  IfSilent 0 +2
    Abort
  IfFileExists "$COMMONPROGRAMDATA\NexaCollector\credentials\NEXA_COLLECTOR_TOKEN" 0 +2
    Abort
  nsDialogs::Create 1018
  Pop $0
  ${If} $0 == error
    Abort
  ${EndIf}
  !insertmacro MUI_HEADER_TEXT "Comunicacao com o servidor Nexa" "Selecione como o Collector enviara as leituras."
  ${NSD_CreateRadioButton} 0 9u 100% 13u "Internet (Web Services)"
  Pop $NexaWebRadio
  ${NSD_CreateRadioButton} 0 27u 100% 13u "E-mail"
  Pop $NexaMailRadio
  ${If} $NexaMode == "email"
    ${NSD_Check} $NexaMailRadio
  ${Else}
    ${NSD_Check} $NexaWebRadio
  ${EndIf}
  ${NSD_CreateCheckbox} 0 52u 100% 13u "Utilizar servidor proxy (somente Web Services)"
  Pop $NexaUseProxyInput
  ${If} $NexaUseProxy == "1"
    ${NSD_Check} $NexaUseProxyInput
  ${EndIf}
  ${NSD_CreateLabel} 0 83u 100% 13u "URL HTTPS da API Nexa (necessaria para Web Services)"
  Pop $0
  ${NSD_CreateText} 0 100u 100% 13u "$NexaApi"
  Pop $NexaApiInput
  nsDialogs::Show
FunctionEnd

Function NexaCommunicationLeave
  ${NSD_GetState} $NexaMailRadio $0
  ${If} $0 == ${BST_CHECKED}
    StrCpy $NexaMode "email"
  ${Else}
    StrCpy $NexaMode "web_services"
  ${EndIf}
  ${NSD_GetState} $NexaUseProxyInput $NexaUseProxy
  ${NSD_GetText} $NexaApiInput $NexaApi
  ${If} $NexaUseProxy != "1"
    StrCpy $NexaProxy ""
    StrCpy $NexaProxyUser ""
    StrCpy $NexaProxyPassword ""
    StrCpy $NexaProxyDomain ""
  ${EndIf}
  ${If} $NexaMode == "web_services"
  ${OrIf} $NexaApi != ""
    StrCpy $0 $NexaApi 8
    ${If} $0 != "https://"
      MessageBox MB_ICONSTOP|MB_OK "Informe a URL da API Nexa com HTTPS." /SD IDOK
      Abort
    ${EndIf}
  ${EndIf}
FunctionEnd

Function NexaProxyPage
  IfSilent 0 +2
    Abort
  ${If} $NexaMode == "email"
    Abort
  ${EndIf}
  ${If} $NexaUseProxy != "1"
    Abort
  ${EndIf}
  nsDialogs::Create 1018
  Pop $0
  ${If} $0 == error
    Abort
  ${EndIf}
  !insertmacro MUI_HEADER_TEXT "Comunicacao com o servidor Nexa" "Internet (Web Services) e proxy."
  ${NSD_CreateLabel} 0 3u 100% 13u "Servidor proxy (sem http://)"
  Pop $0
  ${NSD_CreateText} 0 19u 100% 13u "$NexaProxyHost"
  Pop $NexaProxyInput
  ${NSD_CreateLabel} 0 37u 100% 13u "Porta"
  Pop $0
  ${NSD_CreateText} 0 53u 30% 13u "$NexaProxyPort"
  Pop $NexaProxyPortInput
  ${NSD_CreateLabel} 0 71u 100% 13u "Usuario do proxy"
  Pop $0
  ${NSD_CreateText} 0 87u 100% 13u "$NexaProxyUser"
  Pop $NexaProxyUserInput
  ${NSD_CreateLabel} 0 105u 100% 13u "Senha do proxy"
  Pop $0
  ${NSD_CreatePassword} 0 121u 100% 13u "$NexaProxyPassword"
  Pop $NexaProxyPasswordInput
  ${NSD_CreateLabel} 0 139u 100% 13u "Dominio do usuario (opcional)"
  Pop $0
  ${NSD_CreateText} 0 155u 100% 13u "$NexaProxyDomain"
  Pop $NexaProxyDomainInput
  nsDialogs::Show
FunctionEnd

Function NexaProxyLeave
  ${NSD_GetText} $NexaProxyInput $NexaProxyHost
  ${NSD_GetText} $NexaProxyPortInput $NexaProxyPort
  ${NSD_GetText} $NexaProxyUserInput $NexaProxyUser
  ${NSD_GetText} $NexaProxyPasswordInput $NexaProxyPassword
  ${NSD_GetText} $NexaProxyDomainInput $NexaProxyDomain
  ${If} $NexaUseProxy == ${BST_CHECKED}
    ${If} $NexaProxyHost == ""
    ${OrIf} $NexaProxyPort == ""
      MessageBox MB_ICONSTOP|MB_OK "Informe servidor e porta do proxy." /SD IDOK
      Abort
    ${EndIf}
    StrCpy $NexaProxy "http://$NexaProxyHost:$NexaProxyPort"
  ${Else}
    StrCpy $NexaProxy ""
    StrCpy $NexaProxyUser ""
    StrCpy $NexaProxyPassword ""
    StrCpy $NexaProxyDomain ""
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
  ${If} $NexaProxyDomain != ""
  ${AndIf} $NexaProxyUser == ""
    MessageBox MB_ICONSTOP|MB_OK "Informe o usuario junto com o dominio do proxy." /SD IDOK
    Abort
  ${EndIf}
FunctionEnd

Function NexaMailIncomingPage
  IfSilent 0 +2
    Abort
  ${If} $NexaMode != "email"
    Abort
  ${EndIf}
  nsDialogs::Create 1018
  Pop $0
  ${If} $0 == error
    Abort
  ${EndIf}
  !insertmacro MUI_HEADER_TEXT "E-mail: recebimento" "Caixa do agente Nexa para receber confirmacoes."
  ${NSD_CreateLabel} 0 3u 100% 13u "Endereco de e-mail do agente"
  Pop $0
  ${NSD_CreateText} 0 19u 100% 13u "$NexaMailAddress"
  Pop $NexaMailAddressInput
  ${NSD_CreateLabel} 0 37u 100% 13u "Senha do e-mail (nao sera gravada no config.json)"
  Pop $0
  ${NSD_CreatePassword} 0 53u 100% 13u "$NexaMailPassword"
  Pop $NexaMailPasswordInput
  ${NSD_CreateLabel} 0 71u 100% 13u "Servidor POP3"
  Pop $0
  ${NSD_CreateText} 0 87u 100% 13u "$NexaPop3Host"
  Pop $NexaPop3HostInput
  ${NSD_CreateLabel} 0 105u 100% 13u "Porta POP3"
  Pop $0
  ${NSD_CreateText} 0 121u 30% 13u "$NexaPop3Port"
  Pop $NexaPop3PortInput
  ${NSD_CreateCheckbox} 0 143u 100% 13u "SSL implicito (ex.: 995); desmarcado exige STLS"
  Pop $NexaPop3SslInput
  ${If} $NexaPop3Ssl != "0"
    ${NSD_Check} $NexaPop3SslInput
  ${EndIf}
  nsDialogs::Show
FunctionEnd

Function NexaMailIncomingLeave
  ${NSD_GetText} $NexaMailAddressInput $NexaMailAddress
  ${NSD_GetText} $NexaMailPasswordInput $NexaMailPassword
  ${NSD_GetText} $NexaPop3HostInput $NexaPop3Host
  ${NSD_GetText} $NexaPop3PortInput $NexaPop3Port
  ${NSD_GetState} $NexaPop3SslInput $NexaPop3Ssl
  ${If} $NexaMailAddress == ""
  ${OrIf} $NexaMailPassword == ""
  ${OrIf} $NexaPop3Host == ""
  ${OrIf} $NexaPop3Port == ""
    MessageBox MB_ICONSTOP|MB_OK "Informe e-mail, senha, servidor e porta POP3." /SD IDOK
    Abort
  ${EndIf}
FunctionEnd

Function NexaMailOutgoingPage
  IfSilent 0 +2
    Abort
  ${If} $NexaMode != "email"
    Abort
  ${EndIf}
  nsDialogs::Create 1018
  Pop $0
  ${If} $0 == error
    Abort
  ${EndIf}
  !insertmacro MUI_HEADER_TEXT "E-mail: envio" "Destino das leituras e servidor SMTP do agente."
  ${NSD_CreateLabel} 0 3u 100% 13u "E-mail de entrada do servidor Nexa"
  Pop $0
  ${NSD_CreateText} 0 19u 100% 13u "$NexaMailRecipient"
  Pop $NexaMailRecipientInput
  ${NSD_CreateLabel} 0 37u 100% 13u "Servidor SMTP"
  Pop $0
  ${NSD_CreateText} 0 53u 100% 13u "$NexaSmtpHost"
  Pop $NexaSmtpHostInput
  ${NSD_CreateLabel} 0 71u 100% 13u "Porta SMTP"
  Pop $0
  ${NSD_CreateText} 0 87u 30% 13u "$NexaSmtpPort"
  Pop $NexaSmtpPortInput
  ${NSD_CreateCheckbox} 0 111u 100% 13u "SSL implicito (ex.: 465); desmarcado exige STARTTLS"
  Pop $NexaSmtpSslInput
  ${If} $NexaSmtpSsl != "0"
    ${NSD_Check} $NexaSmtpSslInput
  ${EndIf}
  nsDialogs::Show
FunctionEnd

Function NexaMailOutgoingLeave
  ${NSD_GetText} $NexaMailRecipientInput $NexaMailRecipient
  ${NSD_GetText} $NexaSmtpHostInput $NexaSmtpHost
  ${NSD_GetText} $NexaSmtpPortInput $NexaSmtpPort
  ${NSD_GetState} $NexaSmtpSslInput $NexaSmtpSsl
  ${If} $NexaMailRecipient == ""
  ${OrIf} $NexaSmtpHost == ""
  ${OrIf} $NexaSmtpPort == ""
    MessageBox MB_ICONSTOP|MB_OK "Informe destino Nexa, servidor e porta SMTP." /SD IDOK
    Abort
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
    ReadEnvStr $NexaProxyDomain NEXA_INSTALL_PROXY_DOMAIN
    ReadEnvStr $NexaMode NEXA_INSTALL_COMMUNICATION_MODE
    ReadEnvStr $NexaMailAddress NEXA_INSTALL_MAIL_ADDRESS
    ReadEnvStr $NexaMailPassword NEXA_INSTALL_MAIL_PASSWORD
    ReadEnvStr $NexaMailRecipient NEXA_INSTALL_MAIL_RECIPIENT
    ReadEnvStr $NexaPop3Host NEXA_INSTALL_POP3_HOST
    ReadEnvStr $NexaPop3Port NEXA_INSTALL_POP3_PORT
    ReadEnvStr $NexaPop3Ssl NEXA_INSTALL_POP3_SSL
    ReadEnvStr $NexaSmtpHost NEXA_INSTALL_SMTP_HOST
    ReadEnvStr $NexaSmtpPort NEXA_INSTALL_SMTP_PORT
    ReadEnvStr $NexaSmtpSsl NEXA_INSTALL_SMTP_SSL
  nexa_write_request:
    ${If} $NexaMode == "email"
      StrCpy $NexaProxy ""
      StrCpy $NexaProxyUser ""
      StrCpy $NexaProxyPassword ""
      StrCpy $NexaProxyDomain ""
    ${Else}
      StrCpy $NexaMailAddress ""
      StrCpy $NexaMailPassword ""
      StrCpy $NexaMailRecipient ""
      StrCpy $NexaPop3Host ""
      StrCpy $NexaPop3Port ""
      StrCpy $NexaPop3Ssl ""
      StrCpy $NexaSmtpHost ""
      StrCpy $NexaSmtpPort ""
      StrCpy $NexaSmtpSsl ""
    ${EndIf}
    ; ReadEnvStr sets errors when optional environment variables are absent.
    ; Reset the flag so only the following I/O operation can fail the install.
    ClearErrors
    FileOpen $9 "$COMMONPROGRAMDATA\NexaCollector\credentials\install-request.utf16" w
    IfErrors nexa_request_open_failed
    ClearErrors
    FileWriteUTF16LE /BOM $9 "$NexaId$\r$\n$NexaToken$\r$\n$NexaApi$\r$\n$NexaProxy$\r$\n$NexaProxyUser$\r$\n$NexaProxyPassword$\r$\n$NexaMode$\r$\n$NexaProxyDomain$\r$\n$NexaMailAddress$\r$\n$NexaMailPassword$\r$\n$NexaMailRecipient$\r$\n$NexaPop3Host$\r$\n$NexaPop3Port$\r$\n$NexaPop3Ssl$\r$\n$NexaSmtpHost$\r$\n$NexaSmtpPort$\r$\n$NexaSmtpSsl$\r$\n"
    IfErrors nexa_request_write_failed
    FileClose $9
    ExecWait '"$INSTDIR\nexa-collector-daemon.exe" --provision-windows' $0
    ${If} $0 != "0"
      MessageBox MB_ICONSTOP|MB_OK "Falha ao validar os dados de vinculacao ou comunicacao. Revise os campos e execute o instalador novamente." /SD IDOK
      SetErrorLevel 22
      !insertmacro NEXA_DIAGNOSTIC 22
      IfSilent 0 +2
        Quit
      Abort "Nexa Collector provisioning failed"
    ${EndIf}
    Goto nexa_provisioned
  nexa_request_write_failed:
    FileClose $9
    ; Erase any partially written request containing credentials.
    Delete "$COMMONPROGRAMDATA\NexaCollector\credentials\install-request.utf16"
    MessageBox MB_ICONSTOP|MB_OK "Falha na gravacao dos dados protegidos do Collector." /SD IDOK
    SetErrorLevel 30
    !insertmacro NEXA_DIAGNOSTIC 30
    IfSilent 0 +2
      Quit
    Abort "Nexa Collector protected request write failure"
  nexa_request_open_failed:
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
  ; A provisioned service is AUTO_START while installed. Disable restart
  ; before stopping it so that an uninstall never races against its startup.
  nsExec::ExecToStack '"$SYSDIR\sc.exe" config NexaCollector start= demand'
  Pop $1
  Pop $2
  ${If} $1 != "0"
    MessageBox MB_ICONSTOP|MB_OK "Nao foi possivel desativar o inicio automatico (codigo $1).$\r$\n$2" /SD IDOK
    SetErrorLevel 26
    !insertmacro NEXA_DIAGNOSTIC 26
    IfSilent 0 +2
      Quit
    Abort "Nexa Collector service disable failed"
  ${EndIf}

  ; Exit code 1062 means the service was already stopped and is safe to
  ; delete. Any other failure is not evidence of a stopped service.
  nsExec::ExecToStack '"$SYSDIR\sc.exe" stop NexaCollector'
  Pop $1
  Pop $2
  ${If} $1 != "0"
  ${AndIf} $1 != "1062"
    MessageBox MB_ICONSTOP|MB_OK "Nao foi possivel parar o servico NexaCollector (codigo $1).$\r$\n$2" /SD IDOK
    SetErrorLevel 27
    !insertmacro NEXA_DIAGNOSTIC 27
    IfSilent 0 +2
      Quit
    Abort "Nexa Collector service stop failed"
  ${EndIf}

  nsExec::ExecToStack '"$SYSDIR\sc.exe" delete NexaCollector'
  Pop $1
  Pop $2
  ${If} $1 != "0"
    MessageBox MB_ICONSTOP|MB_OK "O servico nao pode ser marcado para remocao (codigo $1).$\r$\n$2" /SD IDOK
    SetErrorLevel 21
    !insertmacro NEXA_DIAGNOSTIC 21
    IfSilent 0 +2
      Quit
    Abort "Nexa Collector service removal failed"
  ${EndIf}

  ; sc delete returns success when the entry is merely *marked* for removal.
  ; Wait for the SCM to stop the daemon and release the entry before allowing
  ; Tauri to remove files. Preserve the original client config and secrets.
  StrCpy $3 0
  nexa_uninstall_wait_for_delete:
    nsExec::ExecToStack '"$SYSDIR\sc.exe" query NexaCollector'
    Pop $1
    Pop $2
    ${If} $1 == "1060"
      Goto nexa_uninstall_no_service
    ${ElseIf} $1 != "0"
    ${AndIf} $1 != "1072"
      MessageBox MB_ICONSTOP|MB_OK "Falha ao confirmar a remocao do servico (codigo $1).$\r$\n$2" /SD IDOK
      SetErrorLevel 28
      !insertmacro NEXA_DIAGNOSTIC 28
      IfSilent 0 +2
        Quit
      Abort "Nexa Collector service removal inspection failed"
    ${EndIf}
    IntOp $3 $3 + 1
    ${If} $3 >= 45
      MessageBox MB_ICONSTOP|MB_OK "O Windows ainda esta removendo NexaCollector. Feche consoles de servicos e outros processos que usam o agente, reinicie o computador se necessario e tente desinstalar novamente. Os dados do cliente foram preservados." /SD IDOK
      SetErrorLevel 29
      !insertmacro NEXA_DIAGNOSTIC 29
      IfSilent 0 +2
        Quit
      Abort "Nexa Collector service deletion timed out"
    ${EndIf}
    Sleep 1000
    Goto nexa_uninstall_wait_for_delete
  nexa_uninstall_no_service:
!macroend

!macro NSIS_HOOK_POSTUNINSTALL
  ; Preserve existing encrypted queue, config, client binding and credentials.
  DetailPrint "Dados do Nexa Collector preservados em ProgramData."
!macroend
