# Nexa Collector — implementação inicial

A **interface desktop usa Tauri 2.12 + React 19 + TypeScript**. A coleta roda no **daemon Rust separado**. Fechar o console não deve encerrar o daemon, desde que o serviço tenha sido iniciado pelo sistema operacional.

## O que existe no código

- Consulta SNMPv2c de um OID de páginas **explicitamente configurado e homologado** por impressora; apenas contador real é aceito (indisponível não é zero).
- Configuração de equipamentos por IP, ID numérico atribuído pelo backend e rede/CIDR autorizada; não existe varredura automática nesta etapa.
- Outbox persistente em SQLite com modo WAL, chave única por UUID, limite configurável de amostras pendentes (`max_pending_samples`, padrão 50000), envio HTTPS e remoção apenas de amostras reconhecidas pelo backend. Com fila cheia, a coleta pausa, tenta transmitir pendências e não descarta leituras anteriores. IDs repetidos com payload divergente são recusados.
- API Laravel autentica coletor por token individual, aceita até 100 leituras, valida propriedade do equipamento e retorna ACK idempotente.
- Heartbeat autenticado independente do upload (`heartbeat_interval_seconds`, padrão 60s), enviado pelo daemon em um worker próprio, com timeout e jitter para reduzir rajadas. O backend registra a data de recebimento em `collector_agents.heartbeat_at`, separada de `last_seen_at` das leituras.
- Tela desktop local de visão geral, impressoras e configuração, alimentada por configuração e estado SQLite reais (não há dados simulados).
- Testes escritos para durabilidade da outbox, validação de CIDR e isolamento na API.

## Limitações importantes — ainda não utilizar como coletor de produção

- **NÃO implementados:** instalador do serviço Windows/systemd/launchd, IPC daemon-console com autenticação, registro pela interface, armazenamento cifrado da fila, cofre de credenciais do sistema, SNMPv3, descoberta automática, USB, perfis por fabricante, alertas, watchdog, update assinado e diagnóstico avançado.
- A UI tem acesso **somente de leitura** à configuração e ao SQLite; a tela de agentes no portal apresenta o heartbeat observado pelo servidor, mas isso não prova execução contínua do serviço local. O controle por IPC autenticado ainda está pendente.
- O token e a comunidade SNMP são fornecidos por variáveis de ambiente **somente para laboratório**. Para produção, implementar serviço protegido e cofre de segredos do SO.
- Não há teste real de SNMP, execução Cargo, Tauri build ou homologação de impressoras comprovado neste commit.

## Rodar em ambiente de desenvolvimento

Requisitos: Rust 1.90+ (Tauri 2.12), Node.js 22.12+, PHP/Composer e PostgreSQL para a API. O host precisa das dependências nativas do Tauri para seu sistema.

1. Na API, execute as migrations e cadastre tenant, usuário, cliente e impressora via endpoints de administração.
2. No servidor Laravel, gere uma credencial de coletor com `php artisan nexa:collector-register ID_DO_CLIENTE`. O comando mostra um UUID e um token apenas uma vez.
3. Crie um `collector/config.local.json` a partir de `config.example.json`; use o UUID registrado, endpoint HTTPS real, CIDR explicitamente autorizado, ID de impressora e OID validado para o dispositivo.
4. Disponibilize `NEXA_SNMP_COMMUNITY` e `NEXA_COLLECTOR_TOKEN` **apenas no ambiente seguro do processo**, nunca em `config.local.json` ou no repositório.
5. Da pasta `collector`, execute `cargo test -p nexa-collector-core` e `cargo run -p nexa-collector-daemon -- config.local.json ./queue.sqlite --once`.
6. Para a interface, configure `NEXA_COLLECTOR_CONFIG` com o caminho absoluto de `config.local.json` e `NEXA_COLLECTOR_DB` com o caminho absoluto do mesmo SQLite. Em `collector/console`, execute `npm install` e `npm run tauri dev`.

**Exemplo de IPs** usa o bloco de documentação TEST-NET-1, não representa um equipamento real.

## Próximos passos

- Habilitar TLS pinning ou política equivalente, cofre OS e comunicações autenticadas entre daemon e console.
- Implementar instaladores e supervisão nativos e adicionar recursos de configuração/autorização na interface.
- Homologar SNMPv3 por fabricante/modelo, métricas de suprimentos e USB somente onde suportado.
- Validar a outbox com queda de energia, 48h sem rede, ACK parcial, versão de esquema e proteção contra disco cheio.
- Cobrir todos os cenários QA pertinentes antes de afirmar que um requisito do README está concluído.

## Intervalo de heartbeat e diagnóstico no servidor

O campo opcional `heartbeat_interval_seconds` de `config.local.json` (30..3600s) define a frequência de sinal da API, independentemente de `interval_seconds` usado no SNMP. Em caso de timeout, o processo registra aviso genérico e tenta somente no próximo ciclo, sem expor credenciais. Verifique `/agentes` no portal para último heartbeat e último upload, mantendo `NEXA_HEARTBEAT_MAX_SILENCE_SECONDS` maior que o intervalo mais jitter e tolerância operacional. O status `late` **não confirma** que o daemon esteja desligado: rede/proxy/API indisponíveis produzem o mesmo sintoma.

O token continua vindo do ambiente do serviço e deve ser protegido pelo SO. O HTTPS obrigatório permanece, mas ainda não foi homologado com proxy autenticado, Windows Service, systemd e launchd nem hardware real.

## Limite de fila (incremento experimental)

Defina `max_pending_samples` de 1 a 1000000 em `config.local.json`. O padrão para arquivos antigos é 50000. O teto é por **quantidade de amostras**, não por bytes: **não** equivale a cota física de armazenamento. O daemon confere o limite antes de cada consulta SNMP, preserva pendências e retoma após ACK; se a internet cair por muito tempo, haverá lacuna de *novas coletas*, sem perda silenciosa dos registros já enfileirados. Em caso de erro de gravação, o processo falha explicitamente, sem publicar sucesso falso.

Ainda faltam: limite real em bytes, criptografia em repouso, retenção autorizada, alerta observável de fila saturada, recuperação de disco cheio, backoff específico por código HTTP e testes de desligamento abrupto/hardware. Portanto NX-035 e NX-045 permanecem parciais.

## Visualização de capacidade no Console (implementação parcial)

O Console local lê `max_pending_samples` do JSON validado e conta leituras pendentes no SQLite existente. Mostra indicador de ocupação, limite total e aviso a partir de 80% ou ao atingir o teto. Não expõe segredo, não consulta conteúdo de impressão, nem inventa conectividade de serviço/impressora. Atualização por botão de leitura do banco; **não** é telemetria contínua ou status real de processo. A interface permanece somente leitura.

O CI agora verifica TypeScript e bundle React do console, mas **não** compila Tauri nativamente, assina instalador, executa serviços Windows/Linux/macOS nem faz ensaio de hardware.

## Transporte HTTP e retentativas (incremento 17, sem homologação)

O daemon agora tenta enviar a outbox independentemente de `interval_seconds` da coleta: a cada 5 segundos verifica a janela de envio e, depois de ACK, espera no mínimo 10 segundos antes da próxima tentativa. Para falha de rede, 408/425/429, 5xx e protocolos inválidos, aplica backoff com jitter; erros 4xx não transitórios (inclusive 401/403 e 422) e ACK inconsistente são reduzidos a uma tentativa no mínimo a cada 15 minutos. Em 429 e 503 o `Retry-After` em segundos prevalece se maior, limitado a 6 horas; valores com data absoluta HTTP ainda usam o backoff padrão. O reinício do serviço reseta apenas o contador de retentativas em memória; as leituras permanecem na outbox.

O ACK pode ser parcial, mas não vazio, duplicado nem conter UUID fora do lote efetivamente enviado. Confirmações válidas removem somente UUIDs listados; requisições malformadas nunca removem registros. Falhas ao ler/gravar a SQLite ou decodificar registros persistidos provocam saída com erro e exigem supervisor, sem descarte silencioso. Log de erro informa somente categoria e retardo, nunca cabeçalhos de autenticação, token ou corpo da resposta.

**Limites:** não existe serviço OS/supervisor nativo instalado, persistência da janela de backoff entre reinicializações, teste físico de proxy/queda prolongada, armazenamento cifrado, quota real em bytes, isolamento de amostras inválidas (422) ou validação integral dos cenários QA. Em especial, leituras com mais de 14 dias são recusadas pela API atual e precisam de política operacional explícita, nunca de exclusão automática sem auditoria.

## Diagnóstico e recuperação após reinicialização (incremento 18)

O arquivo SQLite mantém `delivery_diagnostics` na tabela `state`: `last_success_at`, `last_failure_at`, `last_error_kind`, `next_attempt_at` e `consecutive_failures`. Os campos são datas ISO 8601, categoria genérica e contador: não armazenam token, segredo SNMP, corpo HTTP, URL completa ou amostra de impressão. Atualização atômica via `INSERT ... ON CONFLICT` em uma linha.

Ao iniciar, o daemon restaura os atrasos futuros usando UTC persistido convertido a um timer monotônico, limitado a 6 horas. Um JSON ou timestamp de estado danificado é tratado como erro local explícito; não se descarta outbox nem se contorna o bloqueio silenciosamente. Um ACK real atualiza a data de sucesso, e uma fila vazia pode limpar falhas antigas sem fabricar um upload.

O console mostra a ocupação e este diagnóstico em leitura local manual. O build React do console é testado em CI, mas **Tauri nativo, instaladores, supervisor Windows/systemd/launchd, recuperação da energia, permissões de acesso ao arquivo SQLite e compatibilidade com hardware permanecem sem homologação**. A mudança é de observabilidade e retomada do backoff, não de disponibilidade garantida.

## Quarentena de amostras recusadas (incremento 19)

**Problema coberto:** a API rejeita todo lote que contenha qualquer leitura inválida (`422`), inclusive uma medição coletada há mais de 14 dias. Retentar o mesmo lote sem revisão bloquearia medições posteriores.

**Fluxo:** o Collector tenta um lote de até 100 leituras. Se o servidor responder `422`, envia **apenas a primeira amostra desse lote**. Se o backend confirmar o UUID, somente essa amostra sai da outbox; as demais continuam pendentes. Se a amostra isolada também receber `422`, ela é movida atomicamente para `quarantined_readings` com a carga original, UUID, timestamp e código de origem genérico. O próximo ciclo tenta a seguinte, sem apagar ou inventar dados. Falhas `401/403/429/5xx`, perda de rede ou ACK inválido seguem a política anterior e não geram quarentena.

A cota `max_pending_samples` soma **amostras pendentes + isoladas**; uma quarentena cheia bloqueia novas coletas, exigindo ação administrativa. Um UUID já isolado não pode ser enfileirado novamente por outra ação e cada transição usa uma transação SQLite; restauração não altera a origem histórica da leitura.

Para inspecionar localmente (sem credenciais de API):
```sh
nexa-collector-daemon config.local.json queue.sqlite --list-quarantined
```
Para recuperar **um UUID** depois de analisar/corrigir o problema (executar com o serviço parado e backup do SQLite):
```sh
nexa-collector-daemon config.local.json queue.sqlite --restore-quarantined <UUID>
```

O Console Tauri mostra os 25 primeiros UUIDs, data, impressora e motivo sem exibir payload dos contadores ou segredos. Os comandos não removem leitura isolada, exceto a transferência segura para a fila pendente; excluir uma amostra definitivamente exigiria um fluxo futuro de revisão/auditoria, ainda não implementado.

**Atenção:** um `422` não significa necessariamente que uma impressora está offline; pode indicar mudança de contrato, data inválida, equipamento desativado ou outra regra de validação. Amostras com mais de 14 dias continuarão rejeitadas se restauradas sem um processo de conciliação aprovado. Não há criptografia em repouso, limite de disco em bytes, exportação auditada nem homologação física. A coluna `payload` do SQLite precisa ser protegida por ACL do sistema operacional.

## Orçamento de bytes serializados (incremento 20)

O campo opcional `max_pending_payload_bytes` de `config.local.json` aceita **1 a 1.073.741.824 bytes**; sem ele, o padrão é **67.108.864 bytes (64 MiB)**. É um segundo limite, independente de `max_pending_samples`.

- O SQLite insere o novo registro **somente** se a soma `LENGTH(CAST(payload AS BLOB))` de `outbox` e `quarantined_readings`, mais o novo JSON, não ultrapassar o orçamento. A verificação e o INSERT pertencem ao mesmo comando.
- Isolar ou restaurar um UUID não reduz essa soma. Leituras confirmadas saem da outbox, liberando orçamento, sem descarte de registros ainda pendentes.
- Se a configuração for reduzida abaixo do volume legado, o banco pode reabrir sem descartar dados. Novas inserções falham de modo explícito até a ocupação cair.
- O daemon recusa novas coletas quando estiver saturado e informa erro genérico sem conteúdo de leitura. A interface apresenta os limites de quantidade e de bytes como dois indicadores separados.

**Importante:** esta política limita apenas os **bytes do payload JSON armazenado**, não o tamanho físico do arquivo SQLite. Os arquivos `queue.sqlite`, `queue.sqlite-wal`, `queue.sqlite-shm`, índices, páginas livres, backups e demais arquivos ocupam espaço adicional e não têm quota fixa nesta implementação. Não ativar como coleta de produção sem monitor de espaço real, reserva de disco, política auditada para dados antigos, proteção dos arquivos (ACL) e criptografia em repouso. Sem testes de disco cheio/queda de energia e hardware real.

## Guard preventivo de espaço físico DB/WAL/SHM — entrega 21

Configuração opcional: `max_sqlite_files_bytes`, padrão `536870912` (512 MiB), entre 1 e 68719476736 (64 GiB). Configurações antigas usam o padrão. Esta verificação soma o tamanho do arquivo SQLite principal e, quando existirem, seus arquivos WAL e SHM. Não conta outras cópias, arquivos temporários em locais externos ou bytes livres no volume.

Ao atingir o limiar, o daemon suspende novas leituras SNMP, mas continua tentando transmitir amostras antigas e processar ACKs confirmados. A inserção de cada nova leitura também confere o tamanho antes de gravar e retorna um erro específico caso o guard seja atingido. Dados pendentes ou em quarentena **não são apagados**; alterações legítimas de estado e restaurações administrativas continuam permitidas para recuperação.

Consulta offline, sem heartbeat, consulta SNMP ou upload:
```sh
nexa-collector-daemon config.local.json queue.sqlite --storage-status
```

O Console apresenta a soma física observada e os tamanhos DB/WAL/SHM separados, além dos dois limites de retenção já existentes. A tela deve ser tratada como instantâneo observacional, não telemetria contínua.

**Limites e operação:** esta é uma proteção preventiva e não impõe quota atômica ao sistema de arquivos. A transação que começa logo abaixo do limiar pode ultrapassá-lo; clientes concorrentes também podem mudar os tamanhos entre medição e escrita. O WAL pode crescer independentemente do tamanho do conteúdo JSON. Após um ACK, o arquivo SQLite pode manter páginas livres e o limite físico permanecer acionado. Não executar `VACUUM`, exclusão de WAL, checkpoint forçado ou migração de banco automaticamente sob pressão: precisam de procedimento supervisionado, reserva de disco e backup verificado. Falta detectar espaço disponível real, implementar reserva mínima, criptografar dados em repouso e homologar falha de energia/dados físicos.

## Criptografia AES-256-GCM para bases novas — entrega 22 (opt-in)

Os registros das colunas `outbox.payload` e `quarantined_readings.payload` podem ser protegidos com criptografia autenticada. Para ativar em **uma base SQLite ainda inexistente**, gere uma chave aleatória de 32 bytes em um gerenciador de segredos externo (nunca no repositório, README, log ou arquivo `config.local.json`) e forneça via variável de ambiente **`NEXA_COLLECTOR_DB_KEY_HEX`** de exatamente 64 caracteres hexadecimais. Daemon e Console precisam receber a mesma chave na sua configuração de serviço/ambiente. Não reutilize o token de API ou a comunidade SNMP como chave de disco.

- Cada payload recebe um nonce aleatório de 96 bits e é armazenado como `enc:v1:<base64(nonce || ciphertext || tag)>` (tag de autenticação de 128 bits). O UUID da amostra é autenticado como AAD; adulteração, chave errada ou UUID divergente provocam falha explícita. O valor cifrado é diferente a cada gravação, mesmo com o mesmo JSON.
- O modo e um verificador autenticado são persistidos na tabela `state`, sem guardar a chave. Reiniciar exige a chave correta; sem ela, a fila é rejeitada. Falhas criptográficas não são enviadas como telemetria falsa.
- Leituras em quarentena mantêm exatamente o ciphertext original. ACK remove apenas UUID confirmado; reabrir, retransmitir e restaurar não fazem conversão automática para plaintext.
- O modo é **opt-in** por compatibilidade. Sem variável, bancos plaintext antigos continuam operacionais, mas arquivos marcados como criptografados não podem ser abertos. Com variável definida, **qualquer caminho SQLite que já exista e não seja marcado como criptografado é recusado**, mesmo que a tabela atual esteja vazia: dados históricos podem persistir nas páginas livres/WAL.
- Uma chave perdida impede decifrar os payloads existentes. Preserve-a fora da máquina usando o cofre da organização e defina controles de acesso e recuperação antes de ativar. Não reinicialize o banco para contornar chave perdida.
- O Console precisa ter acesso à variável para exibir os metadados de leituras da quarentena (a chave não aparece na UI). Essa distribuição de segredos precisa ser aperfeiçoada em implementação de serviço nativo e cofre do sistema operacional.

**Limitações explícitas:** isso **não é criptografia integral do SQLite**. UUIDs, índices, horários, estado de retry, metadados, padrões de ocupação e tabelas não sensíveis continuam visíveis. WAL/backups de bancos legados podem conter payload em texto claro; nunca faça conversão in-place. Permissões ACL de DB/WAL/SHM e do processo, chaves em memória, gestão/rotação segura, snapshots cifrados, migração verificada, supervisão, instalações nativas e testes de perda de energia são requisitos pendentes. Não afirmar NX-035 completo ou produção homologada.

## Migração offline de banco legado em texto claro — entrega 23

Para converter um SQLite antigo sem descartar amostras, **pare o serviço do Collector e o Console** e confirme que nenhuma instância poderá voltar a gravar no arquivo original durante a migração e o corte. Gere e guarde uma chave de 32 bytes em cofre externo e forneça a mesma `NEXA_COLLECTOR_DB_KEY_HEX` para o comando e para o futuro daemon/console. Exemplo de invocação (sem expor a chave em argumentos ou README):

```sh
nexa-collector-daemon config.local.json queue.sqlite --migrate-encrypted-to queue-encrypted.sqlite
```

O caminho `queue-encrypted.sqlite` **não deve existir**, nem ser um link para outro arquivo. O comando bloqueia a escrita na origem durante a leitura SQLite (transação `BEGIN IMMEDIATE`), usa `quick_check`, conserva as linhas e metadados `outbox`, `quarantined_readings` e `state`, cifra e autentica cada JSON com o UUID como AAD, compara contagens e testa integridade do destino. O arquivo criptografado permanece marcado como `incomplete` até a confirmação final. Se faltar espaço ou houver JSON inválido, a fonte fica intacta, o destino parcial não abre em operação normal, e o operador deve investigar antes de tentar um **novo nome de destino**. Nunca apague a fonte ou seu backup para recuperar espaço automaticamente.

Após sucesso, confira contagens e estado com `--storage-status` usando a nova base e a chave, compare IDs e registros isolados pela CLI, atualize `NEXA_COLLECTOR_DB` no Console e o caminho SQLite no comando/serviço do daemon, e só então inicie os serviços. **Não reative o writer do banco antigo** após a migração, pois o processo não replica leituras coletadas depois da cópia e não executa troca automática. Mantenha o banco original protegido até a conciliação/autorização de descarte, incluindo arquivos WAL/SHM e backups anteriores.

A criação exclusiva do destino evita sobrescrever um arquivo existente. No Unix, o arquivo novo é criado com modo `0600`, sujeito também à segurança do diretório; no Windows dependemos da ACL do diretório/serviço, que deve ser configurada e testada. A proteção é **somente dos payloads**. Diagnósticos, UUIDs, datas e índices continuam em claro. Não há gerenciamento de chave por keychain, prova de parada do serviço OS, rotação, sincronização atômica com o processo, backup criptografado, quota física rígida nem testes de energia/disco cheio. NX-035 permanece parcial.

## Rotação offline da chave AES-256-GCM — entrega 24

Para gerar uma nova cópia cifrada com outra chave, pare completamente o daemon e o Console (sem outros writers SQLite). Faça e valide um backup consistente, mantenha a **chave anterior** disponível no cofre apenas pelo período de migração e gere uma **nova chave aleatória independente de 32 bytes**. As duas devem ser diferentes.

As variáveis `NEXA_COLLECTOR_DB_KEY_HEX` (chave antiga) e `NEXA_COLLECTOR_NEW_DB_KEY_HEX` (nova chave) devem ser fornecidas por um gerenciador de segredos ou ambiente protegido — jamais por argumentos CLI, commit, README ou logs. Exemplo **sem valores de chaves**:

```sh
nexa-collector-daemon config.local.json fila-antiga.sqlite --rotate-encryption-to fila-rotacionada.sqlite
```

O comando autentica o marcador da fonte e cada JSON criptografado com a chave antiga; segura uma transação `BEGIN IMMEDIATE`, impede escritores enquanto lê e verifica `quick_check`. Depois cria exclusivamente um banco **novo** com arquivo marcado `migration_status=incomplete`, copia os registros pendentes, isolados e metadados operacionais, decifra cada payload somente em memória e recifra com a chave nova/nonce novo e AAD de UUID. Valida contagens e integridade antes de liberar o destino como `complete`. Uma falha deixa o arquivo incompleto indisponível para o daemon, e mantém a fonte original intacta.

Para concluir, troque **simultaneamente** o caminho da fila no serviço daemon e `NEXA_COLLECTOR_DB` do Console, além de atualizar `NEXA_COLLECTOR_DB_KEY_HEX` para a chave nova; verifique `--storage-status`, quarentena e leitura de lote com o segredo novo antes de ativar operação. Não retome um escritor antigo apontando para a base original, sob risco de divergência posterior ao snapshot.

**Limites:** este comando não apaga com segurança a origem ou seus backups, não faz cutover automático, não garante que o serviço parou, não instala cofres de segredos do OS, não recriptografa páginas/metadados fora dos payloads e não testa chaves em produção de campo. Retenção e descarte da fonte, WAL/SHM, snapshots e da chave antiga dependem de política e procedimento auditado. Nunca exclua arquivos originais antes da validação e reconciliação de leituras.

## Instalação como serviço nativo Linux — entrega 25 (systemd)

Agora há uma unidade `collector/linux/nexa-collector.service` e instalador `collector/linux/install.sh`. Suporta Linux com **systemd e `LoadCredential=`** (verifique a versão do sistema, diretivas e permissões). A unidade executa o daemon independentemente do console Tauri/React, usa usuário dedicado sem login, `StateDirectory=nexa-collector` com `0700`, `UMask=0077`, sandboxing do filesystem e reinício em falha com limite de tentativas (não é watchdog de travamento interno).

1. Compile o daemon para o host Linux: `cd collector && cargo build --release -p nexa-collector-daemon`.
2. Instale manualmente com privilégios: `sudo bash collector/linux/install.sh "$(pwd)/collector/target/release/nexa-collector-daemon"`. O instalador **recusa atualização com serviço ativo**, não sobrescreve configuração existente e não habilita/inicia o serviço. O caminho do executável precisa ser absoluto e executável.
3. Configure `/etc/nexa-collector/config.json` (exemplo inicial somente), permissões `root:nexa-collector 0640`; os dados de fila ficam em `/var/lib/nexa-collector/queue.sqlite`, com ACL do próprio serviço.
4. Provisione por meios protegidos três arquivos de credenciais **root:root 0600** em `/etc/nexa-collector/credentials/`: `token` (64 hex do agente registrado), `db-key-hex` (64 hex aleatórios para AES-256-GCM) e `snmp-community` (credencial real). Não inclua segredos no Git, unit, arguments CLI, prints ou `.env` compartilhado. O serviço usa `LoadCredential` para disponibilizá-los ao processo via `$CREDENTIALS_DIRECTORY`, e o daemon aborta se estiverem faltando. Novos bancos da unidade são criados com payload criptografado; bancos legados devem passar antes pela migração offline auditada das entregas anteriores.
5. Somente após preencher configuração/credenciais, ative e inicie: `sudo systemctl enable --now nexa-collector.service`. Para diagnóstico: `sudo systemctl status nexa-collector.service` e `sudo journalctl -u nexa-collector.service --no-pager -n 100`. O serviço não expõe token nem comunidade em logs de sucesso/erro.
6. Para manutenção, interrompa explicitamente: `sudo systemctl stop nexa-collector.service`; faça backup consistente da fila/WAL/SHM conforme procedimento e revalide o estado antes de atualizar. Não mova/copie banco SQLite aberto por conta própria.

O serviço restringe permissões de kernel/filesystem e reinicia em falhas de processo com atraso; a configuração **não** tem `WatchdogSec=` nem protocolo `sd_notify`, portanto não detecta deadlocks ou loops que mantêm o processo vivo. Serviços Windows/macOS, supervisionamento da UI, integração com keychain, instaladores assinados, atualização automática, compatibilidade SNMPv3 e ensaios reais de boot/energia/rede continuam pendentes. A chave permanece em arquivo root-only na origem de `LoadCredential`, não num cofre criptografado nativo; systemd apenas fornece uma cópia protegida ao processo.

**CI:** `scripts/verify_collector_linux_service.py` verifica estruturalmente o unit, hardening, sintaxe Bash e ausência de auto-start; testes Rust exercitam leitura de credenciais com limites, conteúdo vazio e multilinha. Isso NÃO valida execução real do serviço na estação.

## Serviço nativo Windows — entrega 26

O Collector agora oferece integração inicial com o **Windows Service Control Manager** via crate `windows-service` (somente na compilação Windows). O comando `--service` é reservado à execução pelo SCM e recebe `Stop` para encerrar cooperativamente entre operações. Como consultas SNMP e chamadas HTTPS são bloqueantes, a parada pode levar até o timeout dessas operações; **não** há watchdog do loop ainda.

### Instalação administrativa (não inicia automaticamente)

Compile `nexa-collector-daemon.exe` para Windows, abra PowerShell elevado e execute:

```powershell
.\collector\windows\install.ps1 -DaemonExe "C:\caminho\absoluto\nexa-collector-daemon.exe"
```

O instalador registra `NexaCollector` com conta `NT AUTHORITY\LocalService`, início **manual** e recuperação em caso de falha reconhecida pelo SCM. Recusa um serviço ou diretório de dados existente: atualizações exigem procedimento de manutenção revisado. A instalação não contém senhas/chaves em argumentos, PowerShell ou registro do serviço.

Edite `%ProgramData%\NexaCollector\config.json` com os identificadores reais, URL HTTPS e impressoras permitidas. Provisione os arquivos UTF-8 (sem BOM) em `%ProgramData%\NexaCollector\credentials`: `NEXA_COLLECTOR_TOKEN` (64 caracteres hex), `NEXA_COLLECTOR_DB_KEY_HEX` (64 caracteres hex aleatórios independentes), e `NEXA_SNMP_COMMUNITY` se houver impressoras configuradas. Os diretórios recebem ACL para SYSTEM, Administradores e LocalService; confirme as ACLs dos próprios arquivos antes de iniciar, principalmente se copiados de outras pastas. **Não** use um `.env` do sistema, nem distribua segredos em scripts, logs ou documentação.

A base criptografada nova é criada sob `%ProgramData%\NexaCollector\data\queue.sqlite`. Bancos existentes plaintext precisam passar antes pela migração auditada e explicitamente apontada pelo operador.

Só depois de verificar a configuração e o provisionamento, inicie manualmente:

```powershell
Start-Service -Name NexaCollector
Get-Service -Name NexaCollector
Stop-Service -Name NexaCollector
```

O SCM mostra estados StartPending/Running/Stopped. A parada solicita interrupção do loop e deixa o SQLite ser fechado pelo processo. Falha de inicialização reporta saída com erro; as ações configuradas de recuperação não substituem supervisão de processos travados que permanecem vivos.

**Limites:** não foi executado em estação Windows física, ainda não há política de atualização assinada, instalador MSI, DPAPI/Credential Manager, watchdog de deadlock, comunicação IPC autenticada com Tauri nem testes de falha de rede/energia. O estado Running indica inicialização do daemon, não disponibilidade das impressoras. NX-035, NX-039 e NX-041 permanecem parciais.

## Serviço nativo macOS — entrega 27 (launchd)

O daemon Rust pode rodar como **LaunchDaemon** por meio de `--launchd` (somente macOS). O supervisor do macOS reinicia o processo quando ele encerra com falha e limita reinícios; `SIGTERM` solicita saída cooperativa para fechar a outbox SQLite sem expurgar leituras. Isso não detecta loop travado que permaneça vivo, nem interrompe instantaneamente requisições SNMP/HTTPS bloqueantes.

### Instalação manual com provisionamento de segredos

1. Compile nativamente no macOS: `cd collector && cargo build --release -p nexa-collector-daemon`.
2. Em um Mac compatível com `launchd`, execute `sudo bash collector/macos/install.sh "$(pwd)/collector/target/release/nexa-collector-daemon"`. O instalador rejeita diretórios existentes, cria conta de sistema dedicada `_nexacollector` sem login, instala o executável em `/Library/PrivilegedHelperTools/NexaCollector` e prepara um plist de staging em `/Library/Application Support/NexaCollector/launchd`. **Não cria um LaunchDaemon ativo nem registra auto-start.**
3. Edite `/Library/Application Support/NexaCollector/config.json` (modo 0640, owner root, grupo `_nexacollector`), ajustando URL HTTPS, identificador real e impressoras autorizadas. Para banco novo, o payload será cifrado com a chave fornecida. Bancos antigos plaintext devem passar pelo procedimento offline de migração já documentado.
4. Provisione os arquivos `NEXA_COLLECTOR_TOKEN`, `NEXA_COLLECTOR_DB_KEY_HEX` e `NEXA_SNMP_COMMUNITY` em `/Library/Application Support/NexaCollector/credentials`, com proprietário `root`, grupo `_nexacollector` e modo **0640**, usando um gerenciador de segredos/processo administrativo seguro. Não inclua valores em argumentos, plist, scripts, arquivos versionados ou logs.
5. Somente depois do provisionamento, execute `sudo bash collector/macos/activate.sh`. Esse comando confere arquivos e permissões, copia o plist para `/Library/LaunchDaemons` e chama `launchctl bootstrap system`. O serviço, a partir daí, pode iniciar automaticamente em reinicializações do macOS.
6. Diagnóstico: `sudo launchctl print system/com.nexa.collector`; solicitar parada/descarregamento: `sudo launchctl bootout system/com.nexa.collector`. Logs (com controle de acesso pelo dono): `/Library/Logs/NexaCollector/stdout.log` e `stderr.log`. Eles exigem configuração operacional de rotação/limites para evitar crescimento indefinido.

A conta isolada possui permissão de escrita apenas nos dados e logs da aplicação; configurações e credenciais podem ser lidas, mas não alteradas, pela identidade de serviço. `Umask=63` (0077) torna privados os arquivos novos. A instalação não remove banco antigo, não altera serviço Windows/Linux nem gerencia automaticamente credenciais.

**Pendências de produção:** nenhum ensaio de boot/energia/macOS físico, sem Keychain/secure enclave, sem assinatura/notarização Apple, sem pkg profissional, sem watchdog para deadlocks, sem rotação de logs implementada, sem controle atômico de espaço livre e sem homologação em equipamentos SNMP reais. `NX-035`, `NX-039` e `NX-041` continuam PARCIAIS. O CI apenas compila e testa, não ativa o serviço do sistema.

## Watchdog de progresso e recuperação por supervisor — entrega 28

Em **modo de serviço supervisionado** (systemd com `CREDENTIALS_DIRECTORY`, Windows SCM `--service` ou macOS launchd `--launchd`), o daemon ativa um observador em thread separada. Ele usa `std::time::Instant` monotônico para verificar se a thread principal completou etapas de coleta SNMP, enfileiramento, tentativa de transmissão ou o ciclo de espera. A thread de heartbeat HTTP é **independente** e não pode mascarar um loop principal travado. A detecção entra em condição de falha aos **180 segundos** sem progresso, verificados a cada **5 segundos**.

Ao detectar o travamento, o watchdog registra apenas a categoria genérica e encerra o processo com status **70**. Isso é uma última defesa para que o supervisor existente possa aplicar sua política de reinício. O watchdog **não** apaga, modifica nem dá ACK a leituras. O SQLite WAL mantém as operações transacionais como mecanismo primário de recuperação, mas reinícios abruptos ainda precisam ser validados contra energia/disco cheio na instalação real.

- No Linux: `Restart=on-failure`, `RestartSec=15s`, `StartLimitBurst=5`; sem `sd_notify`/`WatchdogSec` do systemd nesta entrega.
- No Windows: o SCM usa ações de recuperação já configuradas (15 s, 60 s, depois sem novas tentativas naquele ciclo de política). O desligamento normal via Stop seta o sinal de parada e desarma o observador.
- No macOS: `KeepAlive.SuccessfulExit=false` e `ThrottleInterval=30` do `launchd` permitem reinício limitado por atraso, mas não impedem uma sequência indefinida de falhas recorrentes. Política de circuito aberto ainda pendente.
- Comandos manuais do Collector, `--once`, migração/rekey, status e consulta de quarentena **não** iniciam a thread watchdog.
- O watchdog verifica **somente o loop de coleta e entrega**. Ele não detecta uma thread de heartbeat travada quando o loop principal continua saudável, não comprova conectividade SNMP nem define SLO de monitoramento remoto. Se um dispositivo ficar apenas offline, o timeout de SNMP continua curto e a coleta segue para o próximo, sem disparar esse watchdog.
- Sob encerramento solicitado, o watchdog retorna sem provocar reinício anormal. Não existe ferramenta de recuperação automática que edite diretamente a SQLite.

Testes Rust usam relógio sintético para verificar o limite exato, progresso e cancelamento por shutdown; checks de empacotamento do CI verificam a vinculação com as três plataformas. **Faltam testes em host real com falha induzida, reinício de serviço, falta de espaço, SQLite WAL sob interrupção elétrica e proteção contra sucessivas falhas repetidas**. NX-039 permanece PARCIAL.

## Liveness independente da thread de heartbeat — entrega 29

O Collector usa um único observador monotônico com **dois checkpoints**: o loop principal (consulta SNMP, SQLite e transmissão) e a thread independente que realiza o heartbeat HTTPS. A thread de heartbeat não pode atualizar o checkpoint principal; o loop principal não pode atualizar o checkpoint de heartbeat. Ambos têm o mesmo limiar de **180 segundos** e verificação a cada **5 segundos**.

Uma tentativa de heartbeat, inclusive quando o servidor está indisponível e o HTTP termina com erro, é **progresso de execução**. Durante os intervalos de espera (`heartbeat_interval_seconds`, com jitter), cada segundo de sono interrompível confirma que a thread continua executando. Portanto, ficar offline ou esperar o próximo heartbeat **não dispara o watchdog por si só**. Se a thread entrar em deadlock, travar num syscall inesperadamente longo ou encerrar sem continuar a operar, o checkpoint para de ser renovado e o processo termina com código não zero 70, delegado ao supervisor do OS. O código não limpa a outbox.

O observador é inicializado depois de o daemon reportar startup pronto; ambos os checkpoints são armados nesse instante. O monitor não é iniciado em comandos de manutenção, diagnóstico ou `--once`; Stop/SIGTERM/SIGINT normais bloqueiam a ação de recuperação forçada.

**Limitações:** esse watchdog não verifica que o servidor recebeu um ACK de heartbeat, nem que cada impressora produziu leitura válida. Rede inoperante com requisições que retornam erro ainda conta como thread viva. O processo pode continuar vivo com um bug que atualize seu próprio checkpoint sem realizar trabalho útil. Sem ensaio real de simulação de deadlock, política anti-loop específica do launchd, diagnósticos remotos e teste em computadores físicos. Requisitos NX-039 e NX-041 permanecem **PARCIAIS**.

## Incidentes do watchdog e circuito de reinicializações — entrega 30

Um watchdog que força `exit(70)` registra primeiro um incidente pequeno em `queue.sqlite.watchdog.json` (ao lado da base configurada), com horário UTC e causa `main-loop` ou `heartbeat-worker`. O histórico retém até 32 ocorrências, sem payloads/segredos. **Ao acumular 3 incidentes do watchdog em 30 minutos**, a próxima inicialização supervisionada reporta o circuito aberto e termina **com sucesso**, preservando o banco. Isso desarma os reinícios automáticos de `systemd` com `Restart=on-failure`, de `launchd` com `SuccessfulExit=false` e das ações de erro do SCM. O operador deve analisar e iniciar manualmente após corrigir a causa.

Para consultar e liberar manualmente (sem SNMP, heartbeat ou upload), **pare o serviço**, use a configuração e caminho SQLite correspondentes e execute:

```sh
nexa-collector-daemon config.local.json queue.sqlite --watchdog-status
nexa-collector-daemon config.local.json queue.sqlite --reset-watchdog-circuit
```

O reset mantém as ocorrências no histórico e marca um reconhecimento temporal; **não** reconhece nenhuma leitura da fila e não reinicia automaticamente o serviço. Faça backup/inspeção do diário antes de intervir. Se o JSON estiver danificado ou ilegível, o serviço supervisionado também para com saída normal, sem recomeçar um loop; a inspeção e eventual arquivamento/substituição do arquivo requerem ação administrativa, pois um reset de arquivo inválido é rejeitado.

Testes de regressão executam um **subprocesso Rust isolado** em que o watchdog detecta o avanço interrompido, o termina efetivamente com status 70, persiste o motivo e abre o circuito após três execuções. O teste também confirma que o arquivo simulando a fila permanece intocado. Unit tests exercitam expiração da janela, histórico retido, reset e corrupção. A alteração inclui verificações de empacotamento Linux, Windows e macOS no GitHub Actions.

**Limitações importantes:** a proteção conta somente exits originados pelo próprio watchdog; falhas de energia, abortos externos e reinícios de outras causas continuam sujeitos às políticas de restart do serviço. O diário é gravado e sincronizado fora do banco SQLite; se faltar espaço no disco, a gravação pode falhar (o watchdog ainda sai com 70). Uma falha de energia durante a escrita pode deixar JSON incompleto, que por segurança bloqueia novos starts até a revisão manual. Não executa `fsync` do diretório pai, não fornece limite físico rígido de disco e ainda não existe homologação de restart real após deadlock do serviço em hosts de produção. `NX-039` permanece PARCIAL.

## Diagnóstico de incidentes no Console e portal — entrega 31

O Console Tauri/React exibe uma aba **Recuperação**: circuito aberto, contagem de incidentes na janela, horário do último reconhecimento e até 32 eventos (apenas data UTC e componente `main-loop` ou `heartbeat-worker`). A leitura do diário `queue.sqlite.watchdog.json` usa o **mesmo parser do daemon**, agora disponibilizado pelo crate `nexa-collector-core`. O console não grava, faz reset, reinicia serviços ou apaga amostras. Journal inacessível, alterado ou corrompido é exibido como erro, nunca como sucesso. O status observado é local e não prova que o serviço está executando.

Ao executar o heartbeat autenticado, o daemon pode enviar `watchdog: {recent_incidents, circuit_open}` para `/api/v1/collector/heartbeat`. O payload inclui no máximo dois campos agregados; não contém número de páginas, IP, UUID de leitura, identidade de usuário, segredo nem histórico completo. Caso o diário não seja confiável no momento da requisição, o daemon continua transmitindo heartbeat **sem** o objeto de watchdog e emite aviso sanitizado. O backend mantém o último relatório conhecido e marca o horário **de recebimento no servidor**; relatórios de agentes antigos continuam aceitos.

O portal, na página **Agentes de coleta**, mostra `Watchdog (último relatório)` e sua data. Essa informação pode estar **desatualizada**: se o circuito bloquear a inicialização, o daemon não estará rodando para enviar um heartbeat novo; não se deve apresentar o último relatório como saúde em tempo real. A ausência de relato nunca significa que não houve travamentos.

Limites: sem streaming/push de alertas de abertura de circuito, sem serviço central buscando arquivos privados em hosts remotos, sem testes Tauri nativos com serviço real, sem controle administrativo remoto de restart/reset e sem segurança de keychain de sistema. A visualização não substitui logs e diagnóstico in loco; NX-039 e NX-041 continuam PARCIAIS.

## Incidentes administrativos por ausência de heartbeat — entrega 32

O backend pode criar alertas **de comunicação com o Collector**, sem transformar ausência de telemetria em defeito físico da impressora. São registros de `collector_health_incidents`, separados de `printer_alerts` e de `TELEMETRY_GAP`.

Habilitação explícita de operação: configure `NEXA_COLLECTOR_HEALTH_ALERTS_ENABLED=true` e opcionalmente `NEXA_COLLECTOR_HEALTH_SILENCE_SECONDS=300` no ambiente protegido do **backend Laravel**. Rode `php artisan nexa:alerts:check-collectors` ou configure o scheduler Laravel para executar a tarefa de 1 em 1 minuto. O limiar deve ficar entre 60 e 86400 segundos e a tarefa é serializada pelo scheduler com `withoutOverlapping`. O padrão é **desabilitado** até ajuste dos intervalos para a instalação.

A tarefa só abre alertas para agentes previamente conectados, ativos, com clientes/tenants ativos e `heartbeat_at` anterior ao limite. A checagem de concorrência usa lock no registro e índice parcial de unicidade: um incidente ativo por agente. A API grava o horário da última comunicação recebida pelo servidor, não o horário relatado pelo endpoint local. Um heartbeat subsequente, posterior à abertura do incidente, resolve; desativação/revogação fecha por `deactivated`, nunca por falsa recuperação. Leituras/upload de amostras isolados não resolvem silêncio do heartbeat.

O portal administrativo em **Agentes de coleta** exibe o incidente ativo, histórico paginado e estado de resolução, respeitando tenant e carteira do técnico. O endpoint `GET /api/v1/collector-health-incidents` aceita `status`, `customer_id`, `collector_agent_id`, `page` e `per_page` validados. O serviço não envia email, SMS ou push e não identifica a causa do silêncio. Agentes ainda não instalados permanecem `no_heartbeat`, sem abrir automaticamente um incidente.

**Não homologado:** scheduler real da implantação, limitação de latência ponta-a-ponta, monitoramento de instalação inicial, notificações para os operadores, recuperação sob queda de energia e estado dos dispositivos físicos. `NX-039/041` seguem PARCIAIS.

## Notificações e escalonamento por e-mail — entrega 33

Configure explicitamente no backend Laravel NEXA_COLLECTOR_HEALTH_ALERTS_ENABLED=true e NEXA_COLLECTOR_NOTIFICATIONS_ENABLED=true, com NEXA_COLLECTOR_ESCALATION_MINUTES=60 (15..10080). Provisione MAIL_MAILER e MAIL_FROM_ADDRESS adequados em ambiente seguro; MAIL_MAILER=log NÃO envia correspondência real. O scheduler precisa executar o comando nexa:alerts:notify-collectors a cada cinco minutos. Sem scheduler ativo, não ocorre notificação automática.

O aviso inicial é destinado somente a administradores/proprietários ativos com e-mail verificado no mesmo tenant; o escalonamento após o prazo é destinado somente a proprietários. Técnicos comuns, destinatários externos e contas não verificadas são excluídos. Até 50 responsáveis elegíveis por etapa podem ser considerados, ordenados por ID. O incidente precisa continuar ativo para ser enviado; nenhum e-mail pode concluir que impressoras estão offline.

Cada tentativa é registrada na tabela collector_incident_notifications com idempotência por incidente/usuário/etapa e auditoria. O status accepted indica que o Laravel Mail terminou sem erro, NÃO garante recebimento na caixa postal. Falhas ficam registradas como transport_failure, sem texto bruto de exceções que possa conter segredos. Um envio com resultado ambíguo não é repetido automaticamente: investigue antes de qualquer tentativa manual. O envio e a gravação não são transacionais entre SMTP e banco, e existe pequena janela de corrida com resolução do incidente.

O portal mostra contagens agregadas aceitas/sem confirmação por incidente e não expõe endereços dos usuários. PENDÊNCIAS: transporte SMTP real, testes de interrupção, DSN, bounces, confirmação de entrega, alertas push, preferências individuais e homologação em campo. NX-039 e NX-041 permanecem PARCIAIS.

## Controle de volume e reconciliação de e-mails devolvidos — entrega 34

Além do opt-in de notificações (`NEXA_COLLECTOR_NOTIFICATIONS_ENABLED=true`), o backend valida estes limites antes de enviar:
- `NEXA_COLLECTOR_MAIL_MAX_PER_RUN=50` (1–500): quantidade de tentativas efetivas por execução.
- `NEXA_COLLECTOR_MAIL_MAX_PER_TENANT_HOUR=20` (1–1000): reservas por tenant na janela móvel de 60 minutos.
- `NEXA_COLLECTOR_MAIL_MAX_GLOBAL_HOUR=100` (1–10000): reservas globais em 60 minutos, sempre igual ou acima do limite por tenant.

A migração cria `collector_notification_rate_locks` com uma única linha, usada para transações serializadas de reserva de tentativa nos workers que compartilham o mesmo banco. O scheduler conta registros `collector_incident_notifications.attempted_at` recentes (incluindo falhas e resultados incertos) e adia candidatos que ultrapassam as cotas, sem descartá-los, reenviá-los ou alterar o incidente. Contagem `deferred` é apresentada no output do comando. Reiniciar o processo **não zera** as cotas persistentes; a consulta da janela é feita no banco. Este controle não é uma quota global entre *bancos separados* ou implantações independentes, nem substitui os limites de um provedor de SMTP.

### Reconciliação administrativa (somente CLI local)

Depois de confirmar com uma fonte confiável que um aviso **já aceito pelo transporte** foi devolvido ou denunciado, um operador com acesso autorizado ao host de backend pode executar:

```sh
php artisan nexa:alerts:reconcile-collector-mail 123 --type=hard_bounce --confirm-reviewed-evidence
php artisan nexa:alerts:reconcile-collector-mail 124 --type=complaint --confirm-reviewed-evidence
```

Tipos aceitos: `hard_bounce`, `soft_bounce`, `complaint`. O comando recusa IDs inválidos, registros não aceitos, tipos desconhecidos e ausência da confirmação explícita. Atualiza status para `returned` ou `complaint` e cria auditoria com categoria fixa sem dados de transporte, nome ou endereço. Repetições não modificam o registro. **Não consulta uma caixa de e-mail, não verifica a autenticidade da evidência, não envia/reenvia mensagens**; a revisão é responsabilidade do operador. A API administrativa e o portal exibem quantidades agregadas de aceitos, não confirmados, devolvidos e denúncias, com as mesmas restrições de tenant/carteira.

**Limites pendentes:** falta integração segura com DSN/webhooks do provedor, confirmação de entrega no destinatário, reenvio supervisionado com prova/controle de duplicidade, testes de concorrência cross-process do orçamento de e-mails, SMTP de produção e políticas de privacidade/retenção. NX-039/041 permanecem PARCIAIS.

## Reenvio manual supervisionado — entrega 35

O comando administrativo **não é agendado** e não está exposto por HTTP:
```sh
php artisan nexa:alerts:replay-collector-mail 123 --review-reference=CASE-123 --confirm-not-accepted
```
O argumento 123 é o ID de uma tentativa original com `status=failed`; a referência de caso deve ter 4–64 caracteres ASCII alfanuméricos, ponto, hífen ou sublinhado. A flag explícita exige que o responsável tenha revisado evidências externas suficientes para concluir que o provedor **não aceitou** a mensagem. A aplicação não consegue validar essa evidência automaticamente; use procedimento administrativo que registre a decisão fora do sistema. Um erro SMTP original pode ocorrer **depois** de uma entrega, portanto reenvio sem investigação pode duplicar o aviso.

Requer incidentes e e-mails opt-in ativos, alerta ainda aberto, destinatário ativo e verificado com o papel permitido, cliente/agente/tenant ativos e orçamento horário disponível. Apenas o erro original `failed` pode receber uma **única** tentativa de replay; tentativas originais ambíguas (`attempting`), aceitas, devolvidas, reclamadas ou puladas **nunca** são elegíveis. Uma falha de reenvio não desencadeia segunda tentativa.

`collector_notification_replays` preserva a evidência de autorização e os resultados `attempting/accepted/failed/skipped`; a tentativa original nunca é sobrescrita. Índice único por original limita a uma tentativa independente da quantidade de operadores ou processos. O envio ocorre fora da transação, mas a reserva usa a mesma trava persistente de orçamento dos e-mails automáticos. O log e as auditorias armazenam apenas categoria genérica para erro de transporte; nenhum erro SMTP bruto ou endereço de destinatário é exposto no painel.

**Limites:** o sinal `accepted` indica somente que a biblioteca Mail retornou sem exceção, não entrega. Ainda não há API de provider/webhook assinada, DSN automático, prova de entrega, controle de preferência/opt-out por destinatário nem testes de SMTP real. Só operadores com autorização para executar Artisan no host podem usar o comando. NX-039/041 permanecem PARCIAIS.
