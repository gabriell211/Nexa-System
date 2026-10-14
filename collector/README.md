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
