# Nexa System — monitoramento remoto, descoberta e adoção de impressoras

## Incremento: decisões locais persistentes para candidatos (16/10/2026)

O Rust mantém até 200 decisões de revisão (`save`, `ignore` e reversão `reset`)
na base SQLite existente, sob transação e somente para candidatos do último
relatório bem-sucedido (até oito dias). Um registro guarda a observação
que fundamentou a decisão; divergências posteriores de IP/portas/eSCL/
servidor HTTP sinalizam **revisar**, não validam identidade. A tela
**Collector → Impressoras** exibe status e histórico em modo somente leitura.

A manutenção está inicialmente disponível por comando local administrativo
`--review-candidate IP save|ignore|reset` com permissão ao arquivo do
serviço, não por uma chamada Tauri irrestrita. O comando não altera o
`CollectorConfig.printers`, não ativa contadores, não vincula ao cliente e
não transmite candidatos. A seleção interativa com autenticação via IPC,
a verificação real do hardware e o fluxo **Monitorar** seguem pendentes.

## Incremento: busca manual por faixas autorizadas (17/10/2026)

O núcleo Rust agora expõe `plan_authorized_hosts` e `discover_authorized_ranges`:
- exige 1 a 4 IPv4/CIDRs por execução, cada um com máscara /24 ou mais restrita;
- exige que cada CIDR esteja **inteiramente** dentro de uma das faixas `authorized_subnets` previamente provisionadas no arquivo do Collector;
- recusa intervalos públicos, loopback, link-local e escopos não autorizados;
- limita o conjunto agregado a 1024 hosts e elimina duplicações;
- detecta apenas candidatos por portas de impressão/eSCL, sem cadastro, autorização ou coleta de contador.

O Console Tauri passa as faixas para o Rust por `discover_authorized_printers`; os limites são validados **no lado Rust**, não somente no formulário React. A ação manual fica em **Configurações → Busca manual por faixa autorizada** e exibe os candidatos em **Impressoras**. O Console não recebe permissões para editar o arquivo do serviço, alterar token ou cadastrar dispositivos na API.

O daemon também lê uma agenda **opt-in** (`discovery_schedule.enabled`, `interval_hours` de 6 a 168 e `cidrs`) no JSON protegido. Em background, roda buscas independentes de upload e heartbeat e mantém as últimas 200 evidências de candidatos, quantidade encontrada, horário e falhas no estado SQLite existente. A agenda é validada no startup e vem desabilitada por padrão para instalações antigas. O Console exibe esse histórico em **Impressoras**.

**Não concluídos:** editor persistente da agenda/faixas pelo operador via IPC privilegiado (por enquanto requer provisionamento administrativo do JSON), botões interativos de seleção no Console, ação Monitorar homologada, reservas IP/coletor e integração com API. O incremento não ativa nem monitora automaticamente nenhum equipamento.


**Situação em 16/10/2026: especificação e comparação com referência pública; fluxo remoto completo não implementado.**
O objetivo é disponibilizar no portal uma experiência de monitoramento remoto *sem depender de acesso remoto interativo ao computador do cliente*, por meio do Nexa Collector instalado e vinculado ao cliente.

## Fontes analisadas

1. [PrintWayy — Monitorando impressoras de forma remota](https://help.printwayy.com/monitorando-remotamente/): a ferramenta local suporta descoberta periódica (6 a 168 horas), faixa IP configurável, descoberta local na ausência de faixa e envio de candidatos para Novas impressoras; oferece ainda pré-cadastro de impressora por cliente com modo manual ou automático e ponto de instalação/IP reservados.
   - [Captura: configuração da varredura](https://help.printwayy.com/wp-content/uploads/2024/12/varredura.png)
   - [Captura: seleção entre monitoramento manual/automático](https://help.printwayy.com/wp-content/uploads/2024/12/impressora-manual.png)
2. [PrintWayy — Novas impressoras](https://help.printwayy.com/novas-impressoras/): revisão da descoberta, preenchimento de proprietário, localização, departamento e contrato; dispositivos rejeitados são ignorados em buscas futuras.
3. [PrintWayy — Monitorando impressoras](https://help.printwayy.com/monitorando-impressoras/): diferencia **Salvar impressoras** de **Monitorar**; descoberta USB e rede não equivalem a cadastro homologado.
4. [Especificação prévia do Nexa Collector](NEXA-COLLECTOR.md) e [instalador/proxy](NEXA-COLLECTOR-INSTALLER-PROXY.md).

Estas referências são de funcionalidade, **não** autorização para copiar telas, marcas, ativos, software ou protocolos privados.

## Decisão do Nexa: quem configura e aprova é o agente

**Escolha funcional confirmada pelo proprietário do Nexa em 16/10/2026:** o cadastro de faixas IPv4/CIDR, a ativação e a frequência da descoberta programada **ficam em Nexa Collector → Configurações** no computador do cliente; o técnico escolhe quais candidatos serão monitorados em **Nexa Collector → Impressoras**, não no portal. O portal somente recebe estados/leituras de dispositivos autorizados e permite reserva de IP por ponto de instalação; não envia ordens genéricas de varredura nem altera a lista de faixas.

**Diferença consciente em relação à PrintWayy:** a documentação de terceiros também descreve uma fila de *Novas impressoras* no portal para dispositivos detectados automaticamente. **No Nexa, essa fila de seleção/aprovação fica no agente**, de acordo com o fluxo solicitado. Não confundir pesquisa de referência com requisito definitivo.

## Fluxo A: cadastro local de faixas, agenda e seleção no Collector

1. Em **Nexa Collector → Configurações → Descoberta automática**, operador local autorizado ativa/desativa a agenda, seleciona intervalo **6–168 horas** e cadastra/edita/remove **faixas IPv4/CIDR privadas**. Sem faixas, o comportamento da rede local requer autorização explícita; não varrer redes externas por padrão.
2. O Console Tauri valida faixas/intervalos antes do salvamento e envia a alteração a um canal local autenticado e autorizado que persiste a configuração protegida consumida pelo daemon. Console de usuário comum não pode escrever diretamente no arquivo do serviço nem manipular seus segredos.
3. O daemon supervisionado usa horário persistido para calcular próximo ciclo, aplica limites de /24 ou teto de hosts/concurrency e registra última execução/erros. Agenda independente do heartbeat, SNMP, outbox e upload.
4. A descoberta de rede e USB produz **candidatos** locais (nome/ID/porta/IP/evidência, status e horário). **Ainda não são impressoras homologadas nem monitoradas.** Deduplicar por identidade comprovada, não por IP isolado. Não alegar número de série físico se houver apenas VID:PID ou PnP instance.
5. Em **Nexa Collector → Impressoras**, o técnico marca os dispositivos desejados e aciona **Salvar impressoras** para a lista local. Uma segunda ação explícita **Monitorar** inicia a solicitação de habilitação. Também haverá **Ignorar**, reversível pelo operador autorizado e persistente após reinício.
6. Para ativar, verificar que o cliente vinculado ao Collector é dono da impressora, identidade corresponde ao cadastro/reserva e o driver/OID do contador está homologado. Se faltar qualquer evidência, manter **Aguardando homologação** e não transmitir medidores.
7. O backend recebe somente eventos/auditoria ou cadastros aprovados pelo técnico (com escopo cliente/coletor) e medições reais; não fornece um botão independente de aprovação de candidatos no portal.

**Segurança:** nenhuma seleção/edição habilita impressão/escrita no equipamento; cadastro local de faixas nunca concede permissão para varrer IP público, VPN ou rede de outro cliente sem autorização específica e revisão de escopo.

## Fluxo B: reservar IP e ponto de instalação pelo portal

1. Em **Parque → Impressoras por cliente → Adicionar impressora**, administrador seleciona cliente/equipamento e faz pré-reserva de IP privado e ponto de instalação **do mesmo cliente**.
2. O portal persiste estado **Reservado / aguardando descoberta** e permite conferir o resultado, mas **não ativa monitoramento automaticamente por estar no IP indicado**.
3. O Collector recebe de forma autenticada a referência da reserva como sugestão no agente; o IP só é consultado se estiver dentro das **faixas previamente autorizadas no agente**.
4. Quando encontrar um candidato, o técnico **seleciona no agente** e aciona **Monitorar**. A validação confere fabricante/modelo e serial físico quando verificável, além do perfil homologado de contador. Identidade insuficiente ou divergente produz **Revisar identidade**.
5. Após confirmação explícita no agente + validação no backend, a reserva pode se converter em monitoramento ativo; nunca usar apenas resposta em TCP 9100, endereço IP ou VID:PID para ativar.
6. Modo manual exige autorização e auditoria próprios; jamais se passa por leitura automática. Cancelamento, transferência e expiração de reservas devem suspender vínculos incorretos.

O portal poderá acompanhar **aguardando seleção no agente / aguardando homologação / monitorada / falha de comunicação**, sem possuir uma segunda fila de aprovação de dispositivos descobertos.

## Interfaces propostas no Nexa

| Superfície | Ação/estado |
| --- | --- |
| **Collector → Configurações → Descoberta** | Ativar agenda, frequência 6–168 h, adicionar/remover faixas IP/CIDR, salvar e conferir último/próximo scan |
| **Collector → Ferramentas → Busca de rede/USB** | Busca sob demanda nas redes autorizadas e USB conectado |
| **Collector → Impressoras** | Fila de candidatos locais, selecionar, salvar, ignorar, **Monitorar**, diagnóstico de homologação |
| Portal → Pontos de instalação | Estado, último scan informado, histórico/contagem de impressoras monitoradas (somente leitura sobre faixas, sem edição) |
| Portal → Parque por cliente | Pré-reserva IP/coletor do mesmo cliente; visualizar estado aguardando seleção no agente |
| Auditoria | Registro de faixa alterada e do técnico que selecionou/ignorou/habilitou dispositivo |

A interface distingue **descoberto ≠ salvo no agente ≠ homologado ≠ monitorado**.

## Componentes / contratos propostos (pendentes de implementação)

| Componente | Responsabilidade |
| --- | --- |
| `collector/core/discovery_policy` | Política **local** protegida: enabled, intervalo 6–168 h, redes privadas permitidas, limite de hosts e versão |
| Tauri `discovery_settings` | Formulário local; validação/edição via IPC autorizado; não expor segredos do serviço |
| Rust `discovery_scheduler` | Agenda persistente; varre rede e USB autorizados; sem bloquear heartbeat/medidores |
| Rust `discovery_candidates` | Fila local persistente, deduplicação, selecionar/salvar/ignorar, histórico e trilha auditável |
| Rust `monitoring_verifier` | Verifica identidade física e OID/driver homologado antes de habilitar leitura |
| Laravel `monitoring_reservations` | Pré-reserva cliente/impressora/collector/IP/expiração/status por API autenticada |
| Laravel `GET /api/v1/collector/reservations` | Devolve somente reservas do cliente/Collector autenticado; **sem sobrescrever faixas locais** |
| Laravel `POST /api/v1/collector/monitoring-selection` | Confirma escolha feita **no agente** com token da instância, idempotência e validação cruzada |
| Portal React/TanStack | Criar/consultar/cancelar reservas e visualizar estados, não editar agenda/faixas nem aprovar candidatos |

**Não implementar** shell remoto, aprovação automática por IP/porta aberta, editor de CIDR no portal, envio de comando arbitrário ao coletor ou monitoramento sem OID homologado.

## Gap do código Nexa verificado

| Capacidade atual | Situação |
| --- | --- |
| `collector/core/src/discovery.rs`: scan local privado (portas TCP 9100/515/631), limitado e sob demanda | **Implementado**, retorna *candidatos* não homologados |
| `collector/core/src/usb_devices.rs`: descoberta Windows USB impressora/imagem sob demanda | **Na PR #51**, diagnóstico sem contador |
| Daemon, SQLite outbox e HTTPS para contadores **já configurados** por ID/OID | **Implementado** com limites; não serve de fila de *candidatos* automaticamente |
| Laravel heartbeat/ingestão autenticados, isolamento por cliente e impressoras cadastradas | **Implementado** |
| Configuração local segura de descoberta, canal IPC autorizado, candidatos locais e comunicação da seleção ao backend | **PARCIAL** — CIDRs validados no Rust, candidatos e decisões administrativas persistidos em SQLite; falta IPC de edição e confirmação ao backend |
| Agenda automática de 6–168 horas, faixas configuradas e persistidas no agente | **PARCIAL** — daemon agenda scans opt-in no JSON administrativo e persiste snapshots no SQLite; edição de faixas via Console/IPC ainda pendente |
| Reserva prévia de IP+coletor, verificação física e ativação automática | **Não implementado** |
| Console Tauri com fila local de candidatos, seleção/ignorar, anti-duplicidade e Monitorar | **PARCIAL** — estado local salvo/ignorado, reversível via CLI privilegiada e visível no Console; falta UI de edição autorizada via IPC e monitoramento com verificação física |
| Inserção manual auditada de contadores distinguível de telemetria | **Exige validação/implementação específica** |

## Testes mínimos de aceite

- [ ] Intervalos 6/168h aceitos; 5/169h, intervalos nulos malformados e redes públicas não autorizadas recusados.
- [ ] Sem faixas aprovadas localmente, nenhum IP remoto não explicitamente autorizado é testado; limites de /24 e 1024 hosts respeitados quando aplicáveis.
- [ ] Descoberta sob demanda e automática não bloqueiam heartbeat, SNMP nem a entrega da outbox existente.
- [ ] Candidato duplicado por retries não vira múltiplas impressoras; `ignore` sobrevive a novo scan e restart; reversão somente por autorizado.
- [ ] API rejeita token revogado, customer/collector de outro tenant e payloads de modelos/seriais/contadores não confiáveis.
- [ ] Candidato com apenas porta TCP aberta fica `identidade não verificada`, sem medição e sem associação silenciosa.
- [ ] Reserva de IP do mesmo cliente ativa apenas após identidade confirmada; IP reutilizado, serial divergente e impressora já monitorada em outro cliente geram conflito sem faturamento.
- [ ] Cadastro manual exige autorização, origem, autor e auditoria; não aparece como amostra automática.
- [ ] Offline prolongado: preservação de dados de monitoramento já coletados, sem saturar disco com descobertas repetidas; recuperação sem duplicação.
- [ ] Processos simultâneos de aprovação e transferência do parque são serializados em transações, sem reassociação cruzada.
- [ ] E2E do console: salvar CIDR/frequência localmente → varrer → selecionar/salvar/ignorar → monitorar após homologação; E2E do portal: reservar IP/coletor → agente encontra e técnico confirma.
- [ ] Teste Windows do instalador/serviço na rede autorizada, queda de rede e migração/reinstalação preservando estados.

**Gate de liberação:** o fluxo remoto só está pronto quando a edição segura das faixas no console, agenda, fila e seleção local de candidatos, reservas no portal, verificação de identidade, outbox, logs e testes de hardware/CI estiverem integrados. Esta especificação não comprova o funcionamento do produto.
