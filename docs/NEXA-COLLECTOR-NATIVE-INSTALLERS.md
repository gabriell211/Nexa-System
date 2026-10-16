# Nexa Collector — instaladores Linux e macOS

**Estado: pacotes de teste não assinados, sem homologação de instalação em hardware real.** O instalador nativo empacota Console Tauri/React + daemon Rust, mas o assistente de vinculação de cliente, proxy e ativação ainda precisa ser concluído.

## Linux x64: DEB e RPM

Os pacotes nativos incluem:
- aplicativo gráfico Nexa Collector;
- daemon em `/usr/lib/nexa-collector/nexa-collector-daemon`;
- unidade `systemd` do coletor com usuário limitado `nexa-collector`;
- exemplo em `/usr/share/nexa-collector/config.example.json`;
- hooks nativos `postinstall` e `preremove`, executados pelo gerenciador do pacote.

Instale o DEB com `sudo apt install ./Nexa_Collector_*.deb` ou o RPM com `sudo dnf install ./Nexa_Collector_*.rpm`, selecionando o arquivo que realmente foi gerado pelo workflow. Não é necessário executar `collector/linux/install.sh` para instalar o pacote nativo.

O instalador preserva ` /etc/nexa-collector/config.json` e a fila SQLite sob `/var/lib/nexa-collector` quando já existem; cria diretórios de credenciais protegidas. Depois de provisionar `collector_id`, endpoint HTTPS, sub-redes autorizadas e IDs/OIDs homologados, configure arquivos de segredo do serviço em `/etc/nexa-collector/credentials`: `token`, `db-key-hex` e `snmp-community`. Só após isso:

```sh
sudo systemctl enable --now nexa-collector.service
sudo systemctl status nexa-collector.service
sudo journalctl -u nexa-collector.service -n 100 --no-pager
```

O serviço **não** é ativado pelo instalador sem credenciais reais. A desinstalação pára o serviço mas não apaga dados ou chaves do cliente. A interface gráfica não tem ainda o assistente de provisionamento.

## macOS Intel e Apple Silicon: Installer.app PKG

O pacote `NexaCollector-0.1.0-arm64-unsigned.pkg` ou `NexaCollector-0.1.0-x86_64-unsigned.pkg` contém:
- `/Applications/Nexa Collector.app`;
- daemon em `/Library/PrivilegedHelperTools/NexaCollector/nexa-collector-daemon`;
- configuração e plist launchd em `/Library/Application Support/NexaCollector/`;
- conta sem login `_nexacollector`, diretórios de dados/logs isolados e script de ativação incluso.

O instalador prepara, mas **não ativa**, o LaunchDaemon enquanto faltarem configurações e credenciais do cliente. Com as credenciais `NEXA_COLLECTOR_TOKEN`, `NEXA_COLLECTOR_DB_KEY_HEX` e `NEXA_SNMP_COMMUNITY` criadas sob `/Library/Application Support/NexaCollector/credentials/` e verificadas com proprietário `root`, grupo `_nexacollector`, modo `0640`, o administrador pode executar:

```sh
sudo bash "/Library/Application Support/NexaCollector/package/activate.sh"
sudo launchctl print system/com.nexa.collector
```

O mecanismo de segurança recusa sobrescrever instalações existentes ou ativar com credenciais ausentes; migração/upgrade é etapa separada. Sem assinatura Apple Developer ID e notarização, o macOS Gatekeeper pode impedir a abertura. **Não desabilitar Gatekeeper como workaround.**

## Build/CI

Os workflows executam builds nativos e inspeção dos pacotes:
- `Nexa Collector Linux DEB and RPM installers` (Ubuntu 24.04 x64);
- `Nexa Collector macOS PKG (arm64)`;
- `Nexa Collector macOS PKG (x86_64)`.

Os artefatos ficam na execução de GitHub Actions por sete dias. Esses pacotes devem ser tratados como **prévias de teste não assinadas** até haver validação real de instalação, service management, login, USB/SNMP, ACL, atualização, desinstalação, rollback e assinatura.

## Pendências de produto e homologação

- [ ] Assistente de chave do cliente, proxy e ativação automática segura do daemon.
- [ ] Edição de CIDRs, agenda de varredura e seleção de impressoras **no agente**.
- [ ] Contadores e números de série reais com homologação por modelo e protocolo.
- [ ] Assinatura, notarização macOS, atualização/rollback e testes de instalação em máquinas reais.
- [ ] Suporte para Linux ARM64 e distros sem systemd apenas após testes dedicados.

*Não confundir uma compilação bem-sucedida com entrega de produto pronto para produção.*
