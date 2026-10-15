# Nexa System — execução e rastreabilidade (09/10/2026)

## Inventário preservado

Fonte de escopo: [README principal](../README.md) (NX-001 a NX-324; QA-01 a QA-62).
Nenhuma caixa do README foi marcada como concluída nesta etapa.
Os 324 requisitos e 62 cenários continuam no backlog para implementação e validação individual.

## Entrega 1: ciclo de vida administrativo (branch feat/foundation-admin-portal-20261009)

Implementado em código, **não homologado**:
- Clientes: listagem, criação, detalhe, atualização e inativação com histórico.
- Impressoras: listagem, criação, detalhe, atualização e inativação com histórico.
- Autorização de rotas por papel do tenant. O perfil customer não acessa os endpoints administrativos.
- Isolamento por tenant nos serviços, consultas e registros de auditoria.
- Form Requests e API Resources; trilha de auditoria para alterações via endpoints.
- Migração incremental preservando o esquema anterior.
- Testes de integração incluídos para permissões, auditoria, isolamento e inativação.

### Limitações que NÃO podem ser ignoradas

- CRUD continua restrito a papéis internos; autorizações por objeto, carteira e unidade ainda não existem.
- Auditoria não cobre alterações pelo Collector, pelo CLI nem todos os fluxos.
- Não há painel web, RBAC granular, sessões de navegador com cookies seguros, cadastro completo, importação ou exportação.
- Portal administrativo web e portal do cliente não estão implementados.
- Ainda não foram executados composer install, migrações PostgreSQL nem PHPUnit neste ambiente.
- A UI do Collector permanece somente leitura e o daemon não está homologado.
- A inativação de cliente não inativa automaticamente impressoras existentes; esta regra precisa de definição e fluxo específico.

## Próxima ordem de implementação

1. Executar migrations, testes e auditoria de endpoints; resolver falhas reais.
2. Implementar login seguro para SPA web e base visual em React/TypeScript.
3. Clientes, filiais, departamentos, operadores e RBAC por recurso.
4. Impressoras, medidores e vínculo validado com Collector.
5. Suprimentos e razão de estoque, chamados e SLA.
6. Contratos, fechamento congelado e relatórios fiscais.
7. Homologar Collector por SO e fabricante, offline, segurança e instaladores.
8. Testar cada cenário QA e revisar o controle NX após evidência.

## Regras de avanço

- Não declarar um requisito integralmente entregue sem integração completa e teste correspondente.
- Não usar mocks em telas operacionais.
- Não atribuir telemetria a impressoras não homologadas.
- Não expor dados de outros clientes, tenants ou perfis.

## Entrega 2: portal operacional e validação contínua (09/10/2026)

Implementado em código na branch de trabalho:
- Portal `portal/` com React 19, TypeScript estrito e TanStack Query.
- Login/token apenas em memória, leitura do perfil autenticado e logout.
- Dashboard com contagens reais; clientes e parque com paginação e pesquisa executadas no servidor.
- Criação, edição e inativação por perfil autorizado, com confirmação e histórico persistido.
- Auditoria consultável exclusivamente por `owner` e `admin`.
- Proxy de desenvolvimento Vite `/api` para Laravel; sem respostas fictícias e sem token em localStorage.
- Correção de colisão de `sample_id` com payload diferente, com rejeição HTTP 422.
- Ajustes de versões para Laravel 13 e correção do tipo de OID na API SNMP do Rust.
- CI de Laravel, frontend e Collector configurada no GitHub Actions.

### Provas e ressalvas
- O build do portal React concluiu com sucesso na primeira execução de CI.
- O primeiro CI encontrou incompatibilidade Composer/Laravel 13 e compilação de OID Rust; correções foram incluídas, aguardando novas evidências de CI.
- Não foram testadas interação de telas em navegador, migrações reais PostgreSQL, impressoras de clientes nem instaladores.
- O token web não persiste entre recargas; produção exige sessão segura por cookie, MFA e recuperação de conta.
- A lista do seletor de clientes no formulário de impressoras ainda é limitada aos primeiros 100 cadastros ativos.

**Todos os requisitos NX do README continuam com validação final pendente**, mesmo quando partes do fluxo já têm código.

## Entrega 3: organização de clientes — 09/10/2026

### Implementado em código

- `NX-003` (parcial): unidades/filiais de cliente com nome, código, documento fiscal opcional, endereço, ativação/inativação e consulta por cliente.
- `NX-004` (parcial): departamentos com responsável/contato vinculados à unidade e centros de custo com código único por cliente.
- `NX-006` (parcial): inativação lógica de unidades, departamentos e centros de custo; histórico preservado.
- `NX-012` (parcial): auditoria de criação, atualização e inativação para as novas entidades.
- `NX-016/017` (parcial): middleware de autorização por perfil e isolamento por tenant + cliente + unidade nas consultas e mudanças.
- Chaves estrangeiras compostas impedem vinculação cruzada na base de dados.
- Cadastro em cliente inativo proibido; não é possível inativar unidade com departamentos ainda ativos.
- Interface administrativa em `/clientes/:id/unidades` com unidades, departamentos e centros de custo persistidos.
- Cenários de testes de segregação, validação, CRUD, regras de inativação e integridade relacional.
- CI agora também solicita testes com PostgreSQL 17, além de SQLite.

### Ainda pendente

- `NX-003`: histórico temporal de localização e transferência, regras fiscais próprias por filial, geocodificação, normalização fiscal/CEP e integração com equipamento/contrato.
- `NX-004`: setores hierárquicos, relações explícitas entre departamentos e centros de custo, permissões por local, responsáveis vinculados a usuários e histórico temporal.
- `NX-005`: vínculos temporais impressora/contrato/responsável/local e trilhas de realocação.
- `NX-009/010`: expediente e módulos por localização.
- RBAC granular por ação/recurso, escopo de carteira, portal do cliente e aprovação de alteração fiscal.
- Proteção de concorrência específica para alterações de status de unidades em alta demanda.
- Testes de navegação em navegador real, acessibilidade automatizada, compatibilidade de hardware e migrações com dados já populados em PostgreSQL.
- Tokens web ainda transitórios; autenticação web por cookies HttpOnly, MFA, revogação de sessões e CSRF permanecem exigidos para produção.

Todos os requisitos 324 NX e 62 QA do README continuam sem marcação de aceite integral.

## Entrega 4: autenticação web persistente (09/10/2026)

Implementado no código:
- Session guard web do Laravel separado do bearer-token/Collector.
- Cookie de sessão HttpOnly com SameSite=Lax; Secure controlado por configuração do ambiente.
- GET CSRF sem cache, POST login com verificação de usuário, tenant e associação ativa.
- Rotação de sessão e token CSRF ao entrar; invalidação integral ao sair.
- Identidade consultada no backend ao recarregar a página, sem bearer token em JS/localStorage.
- Mesma superfície administrativa com `/api/v1/browser/*`; middleware web + auth:web + tenant em sessão + perfis de operador.
- Login antigo que cria bearer-token desabilitado por padrão (retorna 410), com chave de migração temporária.
- Regras para bloqueio de membership removido, tenant e usuário inativos no middleware de navegação.
- Testes Feature para sessão, CSRF, login, logout, privilégios e isolamento.

**Ainda não homologado**: MFA, recuperação de senha, controle de sessões por dispositivo, login com SSO, testes reais de browser em HTTPS, CSRF end-to-end com navegador, rotação de chave e observabilidade de autenticação. A API herdada de bearer permanece disponível somente para tokens emitidos anteriormente ou via migração controlada.

A sessão de desenvolvimento usa armazenamento `file`; produção exige storage compartilhado e HTTPS. Nada muda na outbox local Rust nem em seu token independente.

## Entrega 5: alocação temporal de impressoras (09/10/2026)

Implementado em código e com testes de integração:
- Modelo `printer_assignments` com cliente, unidade, departamento, centro de custo, data de instalação, data de retirada, autor e motivo.
- Chaves estrangeiras compostas impedem vinculação incorreta entre tenant, cliente, impressora e unidades.
- Índice parcial único (SQLite/PostgreSQL) impede duas alocações ativas para uma impressora, inclusive em corrida entre requisições.
- Operação transacional de instalação/remanejamento fecha a alocação anterior e abre a nova; retirada encerra período sem destruir histórico.
- API de consulta paginada `GET /api/v1/browser/printers/{id}/assignments`; criação `POST /assignments`; retirada `POST /unassign` com motivo.
- Serviços impedem troca direta do cliente de uma impressora, desativação de impressora instalada e inativação de unidade/departamento/centro de custo ocupados.
- Listagens de impressoras retornam a alocação atual por eager loading e a interface apresenta mudança de local e histórico real.
- Testes de segregação por cliente, integridade, status, posição única, auditoria e permissões.

**Pendente para NX-005/NX-053**: posse vs propriedade, transferência entre clientes, histórico de responsáveis, vínculos com contratos, alocação retroativa com validação de períodos, duplicidade de série/identificação física e reconciliation de produção por período de instalação.

A implementação atual registra eventos a partir da data da ação (não aceita retroatividade). A alocação existente de impressoras anteriores não é criada artificialmente por backfill; somente mudanças explicitamente registradas passam a ter histórico.

## Entrega 6 — RBAC por carteira de clientes (2026-10-12)

Implementação submetida (pendente de homologação completa):
- `tenant_user.customer_scope` mantém acesso `all` por padrão para usuários legados; `assigned` exige seleção explícita de clientes.
- `customer_user_access` guarda vínculos por tenant/usuário/cliente com FKs compostas, evitando referências cruzadas.
- Middlewares web e bearer fazem verificação por objeto em rotas de clientes, impressoras, alocações, unidades e centros de custo.
- Consultas de listagem e dashboard filtradas por carteira no banco, não no React.
- Gerenciamento de carteira com endpoint exclusivo para `owner` e `admin`, trilha de auditoria e validação dos IDs.
- Perfis owner/admin não podem ser convertidos para carteira restrita.
- UI administrativa de operadores com carteira `all` ou `assigned`, seleção paginada de clientes e confirmação da alteração.
- Cobertura automatizada para escopo vazio, vazamento cross-tenant, URLs diretas, token bearer, dashboard e restrição de alteração.

Pendentes: restrições por unidade/departamento, bloqueios contextuais por ação, tratamento de mudança de papel, onboarding de usuário com convites/MFA, teste real em navegador e análise de concorrência sob produção.
**Não marcar NX-016, NX-017 ou NX-019 como 100% atendidos: trata-se somente de escopo de carteira.**

## Entrega 7 — estoque transacional inicial (2026-10-13)

### Implementado em código (NÃO significa aceite integral dos NX)

- `NX-078` (parcial): catálogo tenant-scoped de materiais com SKU único por tenant, categoria, nome e unidade.
- `NX-098` (parcial): posições independentes de estoque por empresa, técnico (membro) ou unidade ativa de cliente, com FK composta protegendo tenant/cliente/unidade.
- `NX-100` (parcial): entrada externa, transferência direta entre posições e baixa/consumo.
- `NX-101` (parcial): transação serializada por item, registros de antes/depois, quem movimentou, data, justificativa e auditoria.
- `NX-102` (parcial): bloqueio de saída maior que saldo, idempotência por UUID, chave única por posição e proteção contra consumo duplo sob serialização no PostgreSQL.
- Novo `/estoque` em React: itens, locais, saldos, movimentações e formulários integrados, sem dados simulados.
- Permissão explícita para owner/admin/manager/warehouse com acesso integral à carteira; usuário limitado a clientes não acessa saldo global da operadora.
- Testes Feature em SQLite e PostgreSQL: saldos distintos, transferências, baixa, rollback por falta de saldo, UUID repetido, colisão divergente, vínculos cross-tenant e acesso não autorizado.
- CI anterior já incluía checagem NX/QA, Laravel/PHP, TypeScript/React, Docker/Nginx, Rust e smoke HTTP de sessão/CSRF.

### Não implementado / requisitos dependentes

- `NX-078/079`: SKU compatível com modelos, rendimento, custo exato, tipos parametrizáveis, preços, lotes, histórico de ajustes e catálogo de fornecedores.
- `NX-086/087`: regras e confirmação de troca de suprimento com baixa vinculada a impressora e compatibilidade.
- `NX-099/103/104`: entrada fiscal, fornecedor, lote, validade, inventário físico, ajuste/avaria, mínimo e estoque de segurança.
- `NX-102`: reserva de material, testes de concorrência verdadeiramente paralelos, recuperação de deadlock interprocessos.
- `NX-105..111`: envio, transporte, recebimento, divergências/quantidades parciais, programação de reposição e rastreamento logístico completo.
- Ainda não há fluxo para reativar/inativar/editar catálogo ou locais; não criar registros falsos para demonstrar UI.
- Primeira UI usa seletores dos primeiros 100 elementos e lista histórica paginada em 25; busca remota em carteiras grandes permanece pendente.
- Reservas, movimentações de retorno com documentos e razão fiscal financeira não foram homologadas.
- `NX-098/100/101/102` ficam **PARCIAIS** até teste de operação real, validação concorrente, relatório de auditoria e aceite E2E.

### Invariantes

1. Saldo não pode ser negativo na API.
2. Um UUID de movimentação só pode representar um único payload e ator; repetição idêntica retorna o registro sem reaplicar a baixa.
3. A movimentação e a auditoria se confirmam ou são revertidas juntas.
4. Nenhuma associação pode apontar para outro tenant; verificações em aplicação + chaves compostas no banco.
5. O inventário global da operadora é inacessível a membros com carteira restrita.

## Entrega 8 — reservas, envio em trânsito, recebimento parcial e perdas (2026-10-13)

### Implementado, sem aceite integral de NX-100..111
- `stock_shipments` registra origem, destino, item, quantidade solicitada, embarcada, recebida, perdida, cancelada e status.
- `stock_shipment_events` registra expedição, recebimento e encerramento com UUID de idempotência, ator, justificativa e referência aos movimentos do razão.
- Reserva inicial protege o saldo disponível sem abater o saldo físico.
- `InventoryLedgerService` bloqueia movimentações normais que consumam quantidades já reservadas.
- Expedição parcial reduz a reserva e dá baixa física na origem; remessa em trânsito não é lançada no destino.
- Recebimento parcial cria entrada real somente das unidades confirmadas.
- Encerramento cancela a parcela ainda reservada e registra separadamente a falta em trânsito, sem inventar entrada nem estornar baixa.
- Unicidade por tenant e request_id permite retry seguro de criação e eventos, rejeitando payload divergente ou outro ator.
- `owner/admin/manager` precisam autorizar encerramento com perda/cancelamento; almoxarifado pode expedir e confirmar.
- API GET/POST `/stock/shipments` e operações `/{id}/dispatch`, `/{id}/receive`, `/{id}/close`.
- Tela `/reposicoes` usa listagens reais, detalhes e eventos do servidor.
- Estoque agora mostra **físico, reservado, disponível** por item/local, com consulta agregada sem N+1.
- Testes Feature em SQLite e PostgreSQL validam reserva, transferência, recebimento parcial, falta, encerramento, repetição, RBAC e isolamento.
- Smoke HTTP autentica e faz reserva, expedição, retry de expedição, recebimento parcial e encerramento com perda.

### Pendente
- `NX-099`: fornecedor, notas, lotes, custo e validade.
- `NX-102`: reserva para chamados com integração e testes de concorrência em múltiplos processos.
- `NX-105..107`: programação periódica, previsão e responsáveis por reposição.
- `NX-108..111`: confirmação por destinatário cliente, comprovantes, rastreio externo, transportadora, fluxo de divergência com aprovação e envio multipartes.
- Estoque técnico/cliente permanece sob autorização interna; portal do cliente não deve enxergar nem confirmar remessas sem fluxo específico de acesso.
- Armazenamento de valores financeiros exatos, ajuste fiscal e rastreabilidade por lote/série ainda não foram modelados.
- Testes de carga, agentes reais, UX E2E com navegador e homologação operacional ainda pendentes.
- A migração não gera registros de remessas retroativos ou fictícios.

**NÃO marcar NX-100/101/102/108/110 integralmente atendidos.** O escopo atual somente cobre parte do fluxo logístico, com contabilidade de unidades inteiras.

## Entrega 8b — concorrência real PostgreSQL (2026-10-13)

- `scripts/postgres_stock_concurrency.php` executado após PHPUnit no job PostgreSQL 17.
- Dois processos PHP concorrentes com conexões independentes disputam a **última unidade disponível**.
- Reserva concorrente: **um processo confirma e o outro recebe validação**, preservando exatamente uma reserva.
- Saída direta de saldo totalmente reservado é recusada.
- Após cancelar a reserva, dois processos disputam uma saída de 1 unidade; exatamente **uma saída confirma e a outra é recusada**.
- Conclusão validada no banco: `quantity=0`, `issues=1`.
- Suite Laravel: **61 testes / 442 asserts** tanto em SQLite como PostgreSQL 17, somados a smoke HTTP real, Rust, TypeScript, Docker/Nginx e rastreabilidade.
- CI: [#38421263725](https://github.com/gabriell211/Nexa-System/actions/runs/38421263725).
- Ainda não representa benchmark de carga, failover, simulação de estoque com múltiplos nós ou comportamento em falhas de rede.

## Entrega 9 — fornecedores, custos, lotes de entrada e compatibilidade (2026-10-13)

### Implementado em branch (liberação condicionada ao CI da última revisão)
- `stock_items` mantém marca, cor, custo/preço em centavos inteiros, rendimento nominal e SKU. Valores em reais são convertidos no React para inteiros sem operação monetária em float.
- `stock_suppliers` cadastra fornecedores únicos por código dentro de cada tenant, com e-mail/documento e auditoria.
- `stock_item_compatibilities` associa SKU a fabricante/modelo de impressora com comparação normalizada (espaços e caixa); duplicatas bloqueadas. Modelo sem evidência é `unknown`, não `incompatible`.
- `stock_receipt_details` liga supplier, nota/documento, lote, validade opcional e custo de aquisição **a um movimento real de entrada**; chaves compostas garantem vínculo do mesmo tenant.
- `StockDocumentedReceiptService` confirma entrada de saldo, metadados fiscais e auditoria numa única transação. Falhas ou conflito UUID não podem gravar apenas parte do recebimento.
- Recusa fornecedor externo/inativo, lote com validade anterior ao dia UTC corrente, preço inválido e repetição do UUID com metadata distinta.
- Endpoints `/stock/suppliers`, `/stock/receipts`, `/stock/items/{id}/compatibilities` e `/stock/items/{id}/compatibility-check?printer_id=x` são autenticados por web session ou bearer com mesmo enforcement tenant/carteira.
- Portal `/suprimentos`: fornecedores, entrada documentada e compatibilidade; cadastro de SKU na tela de estoque agora admite marca/cor/preço/custo/rendimento.
- Casos de teste novos para cents, fornecedores e material cross-tenant, lotes vencidos, recebimento atômico, idempotência, auditoria e compatibilidade de impressora.

### Limites explícitos
- **Não existe estoque segregado por lote nas movimentações seguintes**. O número do lote pertence ao documento de entrada e não rastreia qual lote foi expedido, instalado ou consumido. Não afirmar FIFO/FEFO, série individual ou inventário lote-a-lote.
- Preço/custo por SKU podem divergir do custo histórico registrado por lote; entradas históricas preservam o custo de cada evento sem reescrever valores antigos.
- Ausência de compatibilidade cadastrada = desconhecida. A confirmação manual não comprova compatibilidade física sem homologação de fabricante ou teste operacional.
- Falta importação de nota fiscal XML/NF-e, tributos, unidades fracionadas, múltiplas linhas na mesma nota, devolução por lote, previsão de vencimento, vínculo da troca ao equipamento e normalização de códigos GTIN/EAN.
- Suporte a catálogos com mais de 100 registros no seletor React exige busca paginada.
- `NX-078` e `NX-099` continuam **PARCIAIS**, sem aceite E2E de fluxo fiscal/hardware.

## Entrega 10 — contabilidade por lote, FEFO e disponibilidade vencida (2026-10-13)

**Código implementado e integrado:** `stock_lots`, `stock_lot_balances`, `stock_movement_lot_allocations` e `stock_shipment_lot_transit` foram adicionados à base persistida. Entradas documentadas passam a criar lote único por recebimento, posições físicas por lote/local e ligação ao movimento de estoque. Baixas e transferências escolhem lotes válidos por FEFO (menor validade primeiro, nulo por último, desempate cronológico) e armazenam quais quantidades foram alocadas de cada lote. Expedições e recebimentos mantêm lotes em trânsito, registrando perdas no encerramento. `GET /api/v1/stock/lots` expõe posições paginadas sem mostrar outras empresas; o portal agora apresenta a aba Lotes.

**Prevenção contra dados inventados:** estoque antigo anterior à migração, e entradas sem documento, continuam fisicamente contabilizados, mas não recebem lote automático. A quantidade rastreável pode ser inferior à física. Nenhuma migração preenche lotes históricos artificialmente.

**Correção adicional:** saldos físicos de lotes vencidos permanecem registrados, mas são excluídos de novas reservas e de `available_quantity`, expostos separadamente em `expired_quantity`. Unidades legadas sem lote continuam contabilizadas como não rastreadas, sem apropriação a lotes fictícios.

**Testes:** Feature Laravel para FEFO, transferência, legado misto, lote vencido, remessa parcial, perda, isolamento multiempresa, idempotência e bloqueio de reserva vencida; smoke HTTP para leitura dos lotes e baixa rastreada, além dos testes anteriores de reserva/concorrência PostgreSQL.

### Limitações e bloqueios de aceite

- **FEFO atual é alocação contábil por regra**, não prova de picking físico. Uma remessa recebida parcialmente é atribuída por FEFO dentre lotes em trânsito; sem conferência/escaneamento do lote físico no destino, não é possível afirmar que exatamente aquele lote chegou. Exigir confirmação física por lote e divergências na fase logística.
- **Não há rastreabilidade por unidade serializada**, etiquetas GS1, leitura de código, conferência de lacre, cadeia de custódia comprovada ou quarentena física/avaria.
- **Não há reconciliação completa de estoque legado**, inventário físico supervisionado, perdas com aprovação de inventário, relatórios de divergência ou valorização por custo médio tributário.
- Estoque vencido permanece no saldo físico até ajuste/retirada autorizado. A API não deve fingir que sumiu.
- O controle de reserva ainda é por SKU/local, e não por lote escolhido pelo operador; é seguro no agregado, mas não garante a seleção física antecipada do lote.
- `NX-099`, `NX-102` e `NX-103` permanecem **PARCIAIS** até os testes de campo, reconciliação e aceite de processos reais.

## Entrega 11 — chamados internos e por cliente + browser E2E (2026-10-13)

### Implementado (parcial em relação ao README)
- `service_tickets` e `service_ticket_comments` com FKs escopadas por tenant/cliente, estado, prioridade, categoria, impressora opcional, responsável, cronologia e corpo de comentário interno/público.
- Form Requests e recursos explícitos; serviços transacionais para abertura, comentários e alteração de estado, com trilha de auditoria sem duplicar o conteúdo privado nas entradas de auditoria.
- Chamados internos sem cliente; tickets de cliente só admitem equipamentos ativos pertencentes ao mesmo cliente/tenant.
- `triage`, `unassigned`, `pending`, `in_progress`, `resolved`, `closed`: transições permitidas explicitamente; reabertura de encerrados só por supervisão, atendimento em progresso exige responsável.
- Escopo de carteira aplicado na listagem e no acesso por ID; teste de bloqueio de tentativa cross-tenant e carteira restrita.
- `/chamados` no React com fila, filtro por estado, abertura, visualização, comentário interno/público e transição; rotas reais, sem fixtures inventados.
- Corrigida integração dos filtros `q=` e `active_only=true` nas listagens existentes de clientes e impressoras, após descoberta por testes E2E.
- Correção do cache de sessão React Query: preservar o observador de autenticação entre login/logout sem expor tokens ao JavaScript.
- Adicionado `portal/e2e/operations.spec.ts` em Playwright com Chromium real em dois perfis (desktop e viewport móvel), Laravel real, Vite real e SQLite isolado; valida login, clientes, ticket, comentário, mudança de estado, estoque e logout. Job CI não utiliza mocks de API.
- 7 novos testes PHPUnit de Help Desk e uma regressão para filtros de URL; executados em SQLite e PostgreSQL no GitHub Actions.

### Limitações / não declarar aceite 100%
- Abertura externa pelo cliente ainda não existe: `NX-127` só é parcial, pois falta portal do cliente e vínculo de alertas/dispositivos.
- Não há calendário de SLA, prazos úteis, escalonamento, ordem de serviço, anexo, impressão, custo por atendimento, notificação, vínculo de peças, alerta ou faturamento.
- A coluna `internal=false` identifica comentário destinado ao cliente, mas **não existe endpoint de visualização por cliente**; `QA-17` não pode ser aceito sem autorização comprovada em portal real.
- A UI inicial não suporta seleção múltipla de impressoras/dispositivos, campo de equipe, troca direta de responsável sem transição, nem paginação remota nos seletores com mais de 100 registros.
- Teste Playwright cobre uma jornada específica, não os 62 QA integrais. Teste emulando viewport mobile não é ensaio em dispositivo físico. Homologação de produção permanece pendente.

## Entrega 12 — incidentes manuais e correlação com chamados (2026-10-14)

### Implementado na branch `feat/alerts-lifecycle-e2e-20261014`

- Modelo `printer_alerts` com FKs compostas para tenant/cliente/impressora e tenant/chamado, severidade, categoria, código, motivo, origem `operator` e estados `active/acknowledged/linked/resolved/ignored`.
- Deduplicação no PostgreSQL e SQLite por índice único parcial de incidente não encerrado + lock da linha de impressora para serializar novas ocorrências sob concorrência.
- API autenticada com `/alerts`, `/alerts/{id}`, `/alerts/{id}/transition`, `/alerts/{id}/link-ticket`; permissão por papel e carteira aplicada antes de mutações.
- Correlação só aceita chamado ainda em atendimento, da mesma impressora/cliente e tenant. Vínculo repetido ao mesmo chamado é idempotente, e alterações geram auditoria.
- Portal `/alertas` para registro humano real, fila, filtros, detalhe, reconhecimento, resolução, ignorar e vínculo com chamado. O cadastro de chamado também permite selecionar impressora ativa do cliente.
- Testes Laravel multiempresa e E2E em Chromium desktop/mobile sem respostas simuladas.

### Pendente e escopo excluído
- A origem `operator` impede fingir que um operador recebeu dados SNMP. Não há geração automática de alerta pelo Collector, classificadores de erro USB/rede, histerese de desconexão, timers de manutenção ou regras por página/nível/modelo.
- Não há fechamento automático do alerta ao resolver chamado, porque o status de um ticket não prova recuperação da impressora. Requer regra explícita e validação de origem.
- Seletores da UI limitados aos primeiros 100 equipamentos/chamados autorizados; precisa busca paginada, escopo por unidade e melhorias de escala.
- Relatórios, notificações e aceite físico permanecem pendentes. Nenhum requisito NX é marcado integralmente.

## Entrega 13 — avaliação agendada da ausência de leituras (14/10/2026)

### Funcionalidade em validação
- Comando Artisan `nexa:alerts:check-telemetry` agendado a cada cinco minutos; execução desabilitada por padrão e sem notificações externas.
- Regras `NEXA_TELEMETRY_GAP_ENABLED`, `NEXA_TELEMETRY_GAP_MINUTES` (60 inicialmente) e `NEXA_TELEMETRY_RECOVERY_MINUTES` (10 inicialmente), com limites validados e recuperação menor que tolerância.
- Busca agregada de última coleta, processada em blocos de 100; verifica tenants, clientes e impressoras ativos e utiliza transação/lock por impressora para evitar duplicação sob concorrência.
- Incidentes com `origin=telemetry`, `code=TELEMETRY_GAP` e `telemetry_anchor_id` persistente, identificando a leitura que iniciou o episódio. Ignorar/resolver não causa abertura repetida sobre a mesma leitura-base.
- Auditoria do processo com ator nulo, origem `scheduled-telemetry-check` e timestamp/código observados: nenhum funcionário/collector fictício é atribuído.
- Confirmação de recuperação requer duas leituras realmente posteriores ao alerta, separadas por intervalo configurado e com última leitura fresca. Chamados associados bloqueiam o fechamento automático.
- A lista de alertas distingue explicitamente origem por operador e ausência detectada pela avaliação agendada.
- `TelemetryGapMonitorTest` cobre ausência de base, limiar, dedup, recuperação, status terminal, tenant inativo, amostras atrasadas, vínculos, disabled por padrão, configuração inválida.

### Não implementado
- Status físico online/offline, heartbeat separado da coleta, perda de comunicação agente-servidor, leitura SNMP de estados, geração de alerta por toner/peças, supressão por janela e regras avançadas por equipamento.
- Operação depende de scheduler de Laravel realmente ativo na infraestrutura. A opção fica desligada até definir política por cliente, intervalo de coleta e comportamento de suporte.
- Ativar este monitor não substitui monitoramento de rede nem garante detecção de falha de impressão; métricas representam somente ausência observada de leituras.

## Entrega 14 — heartbeat autenticado independente do Collector (14/10/2026)

### Implementado
- Nova coluna `collector_agents.heartbeat_at`: último POST de saúde autenticado, recebimento marcado exclusivamente pelo servidor. `last_seen_at` continua indicando último upload de leituras; um não atualiza o outro.
- Autenticação extraída para `CollectorAuthenticator`, compartilhada entre ingest e heartbeat, verificando segredo 64-hex, UUID do agente, revogação e tenant/cliente ativos.
- Daemon Rust cria worker separado para heartbeat, com tolerância a polling SNMP demorado ou upload em backlog. Intervalo próprio de 30..3600 segundos (60s padrão) e jitter limitado; teste `--once` envia um único sinal.
- API `collector-agents` com paginação/isolamento tenant e carteira, sem expor hashes de segredos, e estados derivados de clock do servidor. Painel `/agentes` com heartbeat, último upload, tolerância, histórico observável e atualização periódica.
- Suite Laravel cobre ausência, revogação, credenciais inválidas, empresa inativa, timestamps não confiáveis, papel, carteira, paginação e separação de relógios. Rust cobre defaults e validação do intervalo; Playwright cobre página integrada.

### Limites
- Heartbeat recente é evidência agente→servidor, **não prova SNMP ou impressora online**.
- Não existe watchdog nativo, instalador de serviço, telemetria do updater, certificado TLS pinning, gestão de rotação do segredo, semântica de disponibilidade histórica, notificações de atraso ou métrica de latência/rede.
- O heartbeat não é armazenamento persistente/offline: perda de rede não gera heartbeat até restabelecer conexão. O upload de leituras mantém sua própria fila.
- As configurações de limiar devem refletir jitter, intervalo de heartbeat e atraso tolerado; o painel não faz afirmação absoluta sobre queda de conexão.
- `NX-034/041/255` permanecem PARCIAIS; nenhuma nova homologação de dispositivo foi reivindicada.

## Entrega 15 — limite de amostras na outbox e backpressure (14/10/2026)

### Código incluído, ainda sem aceite final
- `max_pending_samples` opcional (padrão 50000, faixa 1..1000000) e `Outbox::open_bounded` limitam quantidade de UUIDs pendentes na tabela SQLite. Versões anteriores da configuração continuam válidas por default.
- Inserção condicionada ao total pendente no mesmo comando SQLite, rejeita o excesso com erro tipado e **não descarta amostras antigas**. UUID idêntico/payload idêntico continua idempotente mesmo com a fila cheia; UUID reaproveitado com payload divergente falha explicitamente.
- O daemon faz tentativa de envio antes da varredura SNMP e, com fila cheia, não solicita mais leituras. Ao receber ACK válido, consegue retomar a coleta. Nenhum status falso de impressora é inferido.
- Novos testes unitários cobrem capacidade, preservação após reabrir o SQLite, desbloqueio por ACK, ordenação, colisões de UUID e validação do limite.

### Pendências/alertas
- **NX-035 e NX-045 continuam PARCIAIS:** limite por amostras não garante cota em bytes, criptografia, política de expurgo, retenção legal, monitoramento de saturação, backoff por status HTTP, testes de 48 horas sem rede, falha de disco/energia ou homologação Windows/Linux/macOS.
- Este incremento pausa a coleta sob saturação, produzindo lacunas de observação em campo até liberar espaço. Essas lacunas não devem ser interpoladas/faturadas como leituras reais.
- Evidência de testes somente após CI Rust e jobs de validação aprovados; não assumir aprovação a partir do commit.

## Entrega 16 — diagnóstico de saturação no Collector Console (14/10/2026)

- Tauri expõe somente a capacidade de amostras configurada, além do total real de leituras pendentes na outbox SQLite. Corrigida conversão do OID `Vec<u64>` na interface de integração nativa.
- React apresenta ocupação percentual, quantidade e limite; aviso acessível quando ≥80% e mensagem contextual ao atingir 100%, sem declarar falha ou disponibilidade física de impressoras.
- Job CI `Collector console TypeScript build` executa instalação e build da UI desktop com TypeScript estrito, separadamente do portal principal.
- **Ainda parcial:** sem build/instalador Tauri nativo, sem verificação de execução do serviço local, sem telemetria em tempo real do daemon, sem teste de UX automatizado, sem limite de bytes ou criptografia. NX-035, NX-038, NX-041 e NX-045 não aceitos integralmente.

## Entrega 17 — Política de retentativas HTTP do Collector (14/10/2026)

### Implementação parcial
- `UploadRetryPolicy` separa elegibilidade de upload da cadência de polling SNMP; verificação do canal a cada 5s, janela de sucesso 10s, backoff exponencial com jitter 15s..1h+10% sob falha.
- HTTP 408/425/429/5xx, rede e respostas incompletas entram em retentativa; 401/403 e demais 4xx não transitórios recebem retardo mínimo de 15min, sem alterar o conteúdo persistido. `429/503` respeitam `Retry-After` em segundos (máximo 6h).
- ACK vazio, repetido ou com UUID externo é rejeitado. ACK parcial é aceito e remove apenas UUIDs efetivamente confirmados pelo servidor, sob transação local SQLite.
- Falhas de SQLite ou JSON armazenado não são ignoradas pelo daemon: parada explícita para supervisão, sem exclusão automática de outbox. Logs usam categorias genéricas, sem credenciais/corpos de resposta.
- Testes Rust acrescentados para jitter e limites, recuperação, rate limit, autorização, cabeçalho inválido, replay após reiniciar e ACK parcial/corrompido.

### Limitações e aceitação
- `NX-034`, `NX-035`, `NX-036`, `NX-038` continuam **PARCIAIS**, sem homologação do comportamento real em proxy, 48h offline, disco cheio, reinício da estação, credencial rotacionada ou firmware de impressora.
- Política de retry ainda volátil. Nesta entrega 17, o parser de `Retry-After` suportava apenas segundos; datas HTTP foram acrescentadas na entrega 43. Com erro HTTP 422 de item antigo/sem permissão, a fila para naquele lote até intervenção; não há quarentena automática nem descarte.
- A API Laravel limita leituras antigas a 14 dias. Faturamento nunca deve interpolar lacunas geradas por falhas de transporte.
- Sem prometer integridade fim a fim até CI e testes de operação reais; `--once` só faz uma tentativa quando elegível.

## Entrega 18 — telemetria local sanitizada e retomada de retentativas (14/10/2026)

- JSON `delivery_diagnostics` na tabela SQLite de estado guarda somente última confirmação, falha, categoria genérica, prazo UTC de retry e contador de falhas. Não persiste token ou corpos HTTP.
- No restart, o daemon reconstrói o deadline de backoff na monotonic clock usando o relógio UTC atual, até 6h; timestamp/estado inválido provocam erro explícito.
- ACK válido atualiza sucesso; falhas atualizam estado antes do log. Console Tauri/React apresenta campos observáveis e quantidade de falhas, separando último upload de status físico SNMP.
- Testes Rust de persistência entre reaberturas, restauração da janela e validação de timestamps; job TypeScript do console valida exibição tipada.

### Limites
- O diagnóstico não é healthcheck de rede ou prova de disponibilidade de equipamento. A data prevista pode variar conforme relógio do sistema, ciclos de 5s e duração de operações bloqueantes.
- Não existe criptografia em repouso, bloqueio seguro de arquivos, watchdog instalado, upgrade nativo, proxy autenticado e instrumentação histórica server-side; NX-034/035/038/041 permanecem PARCIAIS.
- Uma falha grave de persistência encerra o processo, necessitando supervisão nativa ainda não implementada.

## Entrega 19 — quarentena transacional de amostras com rejeição individual HTTP 422 (14/10/2026)

### Implementação parcial
- Tabela SQLite `quarantined_readings` adicionada de forma incremental, mantendo payload original, UUID, timestamp, motivo categórico. Apenas transações de mover/restaurar alteram a fila; nenhuma expiração automática ou descarte.
- Em `422` no lote, reenvia apenas a primeira amostra. Um `422` **individual** causa isolamento; se a primeira for aceita, somente seu ACK é apagado. Outros códigos/erros/ACK inválidos permanecem na outbox.
- Capacidade total calcula `outbox + quarantine`, evitando que a fila liberada pelo isolamento permita crescimento indefinido. Restauração exige comando CLI explícito por UUID; listagem sanitizada e tela de consulta somente leitura no console.
- Testes de unidade para isolamento seletivo, ACK, reversibilidade após reiniciar banco SQLite, integridade de contagem, erros temporários e ausência de exclusão silenciosa.

### Limites de aceite
- **NÃO representa solução fiscal para leituras >14 dias.** Backend continua recusando amostras antigas; restauro só é seguro com processo autorizado de conciliação/manualização ainda inexistente.
- `NX-035/038/045` são PARCIAIS: criptografia local, quota por bytes, recuperação em disco cheio, auditoria de decisões sobre descarte, gestão de retenção e testes reais de interrupção elétrica são pendentes.
- A interface de desktop lê até 25 metadados isolados por vez, sem paginação; a CLI lista até 100 por execução. Reprocessamento de milhares de registros requer ferramenta e regras específicas ainda não implementadas.
- Homologação de serviço nativo e impressão em hardware real ainda pendente. Não declarar 62 QA ou 324 NX concluídos.

## Entrega 20 — orçamento SQLite de bytes de payload retido (14/10/2026)

### Implementado para validação
- `CollectorConfig.max_pending_payload_bytes` opcional com default 64 MiB; validação estrita 1..1 GiB e compatibilidade com configurações legadas.
- `Outbox::open_with_limits` impõe dois limites simultâneos (contagem e JSON bytes) via inserção condicional no SQLite. A soma de bytes inclui `outbox` e `quarantined_readings`, evitando liberar espaço contábil ao isolar amostras HTTP 422.
- Outbox reaberta com limite menor conserva todos os dados legados. ACK legítimo reduz a ocupação, sem exclusão forçada, e permite novas inserções.
- Daemon verifica capacidade antes do SNMP e trata limite atingido durante a consulta sem fingir amostra persistida. Console apresenta ocupação de payload em bytes e alerta de saturação separadamente do limite em quantidade.
- Testes Rust adicionais cobrem saturação, idempotência no limite, quarentena, restauração, recuperação por ACK, mudança de configuração e preservação de dados antigos.

### Pendências obrigatórias
- **Este NÃO é um limite do tamanho físico em disco**. Páginas/indexação SQLite, WAL, SHM, arquivos temporários, fragmentação, backups e registros auxiliares continuam fora da cota. Um limite físico rígido requer provisionamento/quotas de filesystem e política testada de checkpoint/recuperação.
- Faltam criptografia da outbox, proteção OS contra cópias não autorizadas, janela de retenção auditada, simulação de 48h sem rede e recuperação de falta de espaço/energia.
- NX-035 e NX-045 permanecem **PARCIAIS** até testes E2E e homologação de produção.

## Entrega 21 — guard observacional de tamanho físico SQLite (14/10/2026)

### Implementação incremental
- Nova configuração opcional `max_sqlite_files_bytes` (default 512 MiB; 1..64 GiB), validada e com compatibilidade para JSON antigo.
- Núcleo Rust guarda caminho do arquivo SQLite e retorna `SqliteFootprint`: tamanho em bytes de DB, WAL, SHM, total, limiar e condição de saturação. Sidecars ausentes contam como zero; erros inesperados de leitura do filesystem não são omitidos.
- `Outbox::enqueue` verifica a pressão antes do INSERT e mantém a semântica anterior de idempotência; erro tipado `SqliteSoftLimit` não descarta registros. `Outbox::at_capacity` integra contagem + orçamento de payload + guard de arquivos.
- Daemon pausa novas consultas SNMP quando o guard é atingido, evita gravações desnecessárias do relógio de coleta sob pressão, mas mantém processamento de upload/ACK e quarentena. CLI `--storage-status` lê diagnóstico sem rede.
- Console Tauri/React exibe soma e componentes DB/WAL/SHM, limiar configurado e mensagens de saturação.
- Testes Rust cobrem somatório físico, guard bloqueando novas coletas sem impedir ACK/restore e validação de limite configurado.

### Atenção — sem homologação completa
- **Não equivale a quota rígida de disco ou garantia contra ENOSPC.** Operações podem ultrapassar o limite entre a medição e a escrita, incluindo concorrência e WAL; metadados em outros caminhos, backups, temporários e disponibilidade física livre não são monitorados.
- ACK não encolhe necessariamente DB/WAL, e operação de VACUUM/CHKPT manual exige supervisão e backup. Não há política de mínimo espaço livre nem instalações de serviço nativo nem criptografia em repouso.
- O monitoramento local é observacional e não inclui testes reais de interrupção elétrica/disco cheio; `NX-035` e `NX-045` continuam **PARCIAIS**.

## Entrega 22 — criptografia autenticada opt-in de payload do Collector (14/10/2026)

### Incremento implementado
- Novo `PayloadCipher` usa AES-256-GCM com nonce por registro e AAD do UUID; codificação `enc:v1:` em coluna payload. AAD bloqueia troca de ciphertext entre UUIDs. Chave hexadecimal de 32 bytes é recebida somente via `NEXA_COLLECTOR_DB_KEY_HEX`; não está no JSON nem no Git.
- Estado do DB traz marcador explícito de modo cifrado e verificador autenticado de chave. Abertura criptografada rejeita chave errada/ciphertext danificado; abertura plaintext rejeita banco marcado, sem downgrade silencioso.
- `enqueue` compara dados decifrados para deduplicar UUID com ciphertext randomizado; `next_batch` e consulta de quarentena autenticam/descriptografam; transições de quarentena e ACK mantêm bytes cifrados enquanto retidos.
- Ativação **somente para arquivo DB novo**. Um banco legado existente, até vazio, recusa conversão para evitar restos plaintext em páginas livres/WAL/SHM. Não há procedimento de migração/rekey nesta entrega.
- Daemon e console fazem abertura protegida quando a variável externa está definida; sem ela o daemon legado mantém compatibilidade plaintext. Console apresenta status verificado ou mensagem de falha sem expor a chave.
- Testes Rust adicionados: round-trip, deduplicação, quarentena/restore/ACK após reiniciar, chave incorreta/ausente, recusa de migração legada, ciphertext modificado, unicidade de nonce e integridade AAD.

### Limites de produção
- **Somente payload de leituras cifrado; não equivale a SQLCipher ou cifragem do arquivo inteiro.** Metadata, horários, índices, estado de retry e payload histórico de bancos antigos permanecem expostos.
- Migração segura para arquivo novo com auditoria, rotação de chave, distribuição em OS keychain/secret store, permissões nos arquivos e zeroização integral de segredos em runtime ainda pendentes.
- Faltam testes E2E nativos Tauri, instaladores Windows/Linux/macOS, retomada após interrupção elétrica, hardware SNMP e auditoria fiscal de leituras antigas. `NX-035` continua PARCIAL, sem homologação de produção.

## Entrega 23 — migração offline de payload plaintext para banco novo cifrado (14/10/2026)

### Incremento
- Nova API `Outbox::migrate_plaintext_to_encrypted` e comando `--migrate-encrypted-to <new-db>` sem rede/SNMP. Exige `NEXA_COLLECTOR_DB_KEY_HEX` e destino inexistente.
- Fonte aberta com `BEGIN IMMEDIATE` para impedir alteração concomitante durante o snapshot; checagem inicial `PRAGMA quick_check` e rejeição de origem já cifrada. Destino criado exclusivamente, com modo `0600` no Unix, e marcador `migration_status=incomplete` gravado atomicamente junto ao modo AES.
- Cada leitura de outbox/quarentena tem UUID validado contra JSON, ciphertext autenticado com AAD do UUID e checagem de roundtrip. Conservados timestamps, motivo de isolamento, contador, estado de upload e demais registros `state` não reservados.
- Contagens e `quick_check` validados antes de mudar marcador para `complete`. Abertura normal com chave recusa destino incompleto. Falhas preservam fonte, não removem destino automaticamente, e impedem reuso acidental do nome.
- Testes Rust para migração/recuperação, metadados e quarentena, impossibilidade de sobrescrever destino ou fonte, rejeição de JSON legado corrompido, recusa de origem cifrada e de chave inválida.

### Limitações
- A prova de desligamento do serviço OS e a troca de caminho são procedimentos manuais. Embora a fonte esteja travada durante a cópia, escritores reativados **depois** da transação ainda podem divergir; não há cutover automático/replicação.
- Não migra o banco em uso, não remove texto claro de source/WAL/backups, não criptografa metadados e não implementa rotação de chaves, secret store, cota física ou garantia de ENOSPC.
- Sem aceitação de instalação nativa no Windows/Linux/macOS e sem teste de energia/hardware real. `NX-035` continua **PARCIAL**.

## Entrega 24 — rotação offline de chave AES-256-GCM (14/10/2026)

### Incremento implementado
- Reutilizado fluxo de migração para fonte cifrada autenticada com a chave antiga; nova API `rotate_encryption_key_offline` e CLI `--rotate-encryption-to <new-db>` com variáveis **separadas** `NEXA_COLLECTOR_DB_KEY_HEX` e `NEXA_COLLECTOR_NEW_DB_KEY_HEX`.
- Recusa chaves iguais, chave anterior incorreta, fonte plaintext ou incompleta e destino já existente, sem sobrescrever ou truncar a origem.
- Segura origem com `BEGIN IMMEDIATE`, valida chave e `quick_check`, decifra JSON de outbox/quarentena em buffer com zeroização e cifra sob nova chave/AAD UUID antes da cópia. Preserva UUID, timestamp, ordem, motivos, ACK pendente e estado de transmissão.
- Marcador `migration_status=incomplete` bloqueia leitura do destino até checagem de contagens, ciphertext roundtrip e `quick_check`, quando vira `complete`. O processo não alterna o caminho da base nem altera a configuração dos serviços.
- Novos testes Rust cobrem rotação e preservação de metadados/quarentena, chave errada/igual, fonte plaintext, destino existente e ciphertext corrompido, mantendo destino parcial inutilizável.

### Limites de aceite
- Rotação **offline**, sem prova de parada de writers depois do snapshot; cutover e backup exigem operador. Nenhuma chave é recuperável automaticamente se perdida.
- Ausentes cofre nativo (Windows DPAPI/Credential Manager, macOS Keychain e Linux Secret Service), cifragem integral SQLite, descarte seguro de backups, instaladores e teste real de interrupção elétrica/disco cheio. `NX-035` permanece **PARCIAL**.

## Entrega 25 — serviço systemd Linux e credenciais protegidas (14/10/2026)

### Incremento
- Serviço `collector/linux/nexa-collector.service` invoca daemon independente do Console com `Restart=on-failure`, atraso 15s, `StartLimitIntervalSec/StartLimitBurst`, usuário sem login, `StateDirectoryMode=0700`, `UMask=0077`, `ProtectSystem=strict` e demais diretivas de hardening compatíveis com SNMP UDP e HTTPS.
- Usa `LoadCredential=` para três arquivos root-only de token, chave AES e comunidade SNMP; daemon seleciona `CREDENTIALS_DIRECTORY` como fonte exclusiva em execução supervisionada, falha sem credenciais e nunca cai para ambiente em modo de serviço. Fora dele, variáveis explícitas continuam disponíveis para laboratório.
- Instalador Bash cria usuário/diretórios/configuração restrita, instala executável e unit, recusa sobrescrever binário com serviço ativo e não inicia ou habilita automaticamente sem provisionamento do operador.
- Testes: Rust de leitura de arquivo de credenciais (CRLF, ausente, inválido e excesso de tamanho); CI separado de checagem estrutural do serviço/Bash/credenciais e `systemd-analyze verify`.

### Limites
- Não foi executado o serviço em host real; `systemd-analyze verify` não comprova boot, reinício real, permissões montadas, conectividade SNMP ou escrita da outbox.
- Não existe `sd_notify`/watchdog para processo travado que não encerra; reinício é apenas em falha, com limite de tentativas. `NX-039` permanece PARCIAL.
- Não existe serviço nativo Windows ou launchd macOS, Tauri nativo validado, armazenamento de segredos em TPM/DPAPI/keychain, SNMPv3, instalador assinado ou homologação de dispositivos; `NX-035`, `NX-039`, `NX-041` continuam parciais.

## Entrega 26 — integração inicial com Windows Service Control Manager (14/10/2026)

### Implementado para validação
- Rust `windows_service_runtime` com `define_windows_service!`, handler SCM para Stop/Interrogate e transições explícitas StartPending/Running/Stopped. `--service` usa caminhos fixos sob ProgramData e chama o mesmo núcleo da CLI, com flag de parada cooperativa entre verificações/consultas.
- `run_application` aceita sinal de shutdown; heartbeat sai quando solicitado, loop principal cessa e o SQLite permanece intacto. Operações SNMP/HTTPS em andamento podem atrasar o stop até o respectivo timeout, sem garantia de shutdown instantâneo.
- No serviço Windows, segredos só são carregados de arquivos em `%ProgramData%\NexaCollector\credentials`. Inexistência de chave DB impede criar plaintext; não cai para variáveis herdadas do ambiente. Arquivos precisam de ACL restrita; modo CLI continua compatível.
- Instalador `collector/windows/install.ps1` registra SCM com `LocalService`, configura ações de recuperação, diretórios/ACLs com SIDs conhecidos, não sobrescreve instalações existentes e não ativa automaticamente.
- CI adicional Windows executa testes core/daemon nativos e valida parsing do PowerShell, contratos SCM, ausência de auto-start, configuração de ACL. Teste Windows de estados aceita Stop somente em Running e reporta falha na parada.

### Limites
- CI não executa `Start-Service` em máquina implantada. Falta teste operacional de boot, interrupção elétrica, permissões pós-instalação, comportamento de WAL e recuperação efetiva do SCM.
- Ainda sem DPAPI/cofre nativo, watchdog heartbeat interno para hang e proteção de credenciais além de ACLs; não existe MSI assinado ou autoatualização. macOS launchd pendente.
- NX-035, NX-039 e NX-041 continuam PARCIAIS; 324 NX e 62 QA não foram declarados completos.

## Entrega 27 — serviço macOS launchd com ativação manual e credenciais protegidas (14/10/2026)

### Incremento
- Novo módulo macOS `macos_launchd_runtime` com tratamento cooperativo de SIGTERM/SIGINT; chama o mesmo daemon/SQLite da CLI em modo supervisionado e com caminhos fixos. Verifica sinal de parada antes/entre consultas e no loop de espera, sem exclusão de leituras.
- `load_secret` exige arquivos sob `/Library/Application Support/NexaCollector/credentials` em modo launchd, sem fallback para env. Chave AES é obrigatória em modo supervisionado para não criar SQLite plaintext.
- `collector/macos/com.nexa.collector.plist`: conta dedicada, umask 0077, autoload após ativação explícita, reinício somente por exit não bem sucedido e throttling de 30s. Logs/dados separados com permissões restritas.
- `install.sh` cria conta dedicada e prepara diretórios e unidade em staging. `activate.sh` valida credenciais/permissões, instala LaunchDaemon e chama `launchctl bootstrap system` explicitamente. Não ativa sem ação administrativa.
- Job CI `macOS Collector native launchd build and tests` executa Rust nativo, bash -n, plutil -lint e checks estáticos do plist/credenciais.

### Limites / ainda não aceito
- Não há execução real em Mac físico/boot/energia, teste de recuperação de serviços que travam vivos, nem cofre Keychain/secure enclave, assinatura/notarização, instalação pkg, gestão/rotação de logs ou atualização automatizada. `launchd` reinicia falhas, mas não fornece watchdog de liveness desta aplicação.
- NX-035/039/041 permanecem PARCIAIS. Não fechar 324 NX ou 62 QA sem cobertura funcional e homologação em campo.

## Entrega 28 — watchdog monotônico do loop principal do Collector (14/10/2026)

### Implementado e verificável pelo código
- Módulo `collector/daemon/src/progress_watchdog.rs` separa o tempo de progresso do heartbeat HTTP, com verificação monotônica independente a cada 5 s e limite de 180 s.
- O loop Rust principal confirma progresso após cada tentativa SNMP, coleta enfileirada, etapa de polling, tentativa de upload e espera. Thread secundária do heartbeat não renova o monitor; consultas offline/CLI e `--once` não criam observador.
- Após ultrapassar limite, o observador relata apenas categoria genérica e usa `exit(70)`, delegando reinício à política existente de systemd/SCM/launchd. A outbox SQLite não é expurgada; WAL continua responsável pela consistência transacional.
- Shutdown normal antes do encerramento desarma o watchdog. Testes Rust validam limiar, ticker e shutdown com relógio sintético. Validadores de empacotamento nas três plataformas conferem o código de saída não zero e vínculo exclusivo com modo supervisionado.

### Limitações de aceitação
- Não é watchdog nativo `sd_notify`/SCM heartbeat, e não detecta a thread de heartbeat travada isoladamente. Reinício abrupto pode ocorrer em host lento/sobrecarregado e requer validação dos limites em campo.
- Não há teste E2E real de matar thread, recuperação física de energia, indisponibilidade SNMP massiva ou corrupção de WAL. macOS launchd ainda pode reiniciar continuamente, embora com throttling.
- `NX-039` permanece PARCIAL, pois exige política de reinícios, liveness de todos os componentes, testes de boot e validação operacional. `NX-041` também permanece PARCIAL.

## Entrega 29 — supervisionar heartbeat e processamento como workers independentes (14/10/2026)

### Implementado
- `ProgressWatchdog` passa a guardar timestamps monotônicos separados `main` e `heartbeat` e reporta causas distintas `MainStalled` ou `HeartbeatStalled`. Nenhum worker tem acesso à atualização do checkpoint do outro na execução normal.
- Checkpoint de heartbeat renovado após cada tentativa HTTP concluída (inclui indisponibilidade de rede) e após cada segundo de sono interrompível. Intervalos de heartbeat longos não causam falsos travamentos.
- Watchdog é armado depois de `on_ready` e executado apenas em modo de serviço supervisionado; termina com `exit(70)` em 180 s sem liveness de qualquer um dos workers, sem exclusão/ACK automático de registros SQLite.
- Testes unitários Rust para ambos os workers, teste de limite exato, startup, independência e stop; verificações estáticas Linux, Windows e macOS exigem wiring real dos dois sinais.

### Ainda parcial
- Isso detecta ausência de progresso das threads, **não sucesso do heartbeat HTTP nem saúde da impressora**. Falhas de rede retornando erro normalmente não disparam reinício.
- Sem induzir deadlock efetivo ou matar uma das threads sob um sistema operacional real; não está homologado em host com serviços ativos ou dispositivos físicos.
- Sem política completa de circuito aberto para macOS launchd, sem auditoria central de reinícios e alertas de saúde do collector por componente. NX-039/041 permanecem PARCIAIS.

## Entrega 30 — diário de incidentes e proteção contra crash-loop do watchdog (14/10/2026)

### Implementado e passível de validação
- `collector/daemon/src/recovery_guard.rs`: diário JSON versionado, limitado a 32 incidentes de watchdog, somente horário UTC e identidade do worker; sem leitura de fila nem segredos. Grava em `<queue.sqlite>.watchdog.json`, ao lado da base, com `sync_all` e permissões iniciais `0600` no Unix.
- `progress_watchdog.rs` persiste o incidente antes de executar `exit(70)`. Mesmo em falha de escrita, sai para permitir recuperação pelo gerenciador.
- No startup supervisionado, 3 eventos nos últimos 30 minutos abrem circuito e encerram serviço **com status de sucesso**, impedindo reinícios automáticos em loop com as políticas atuais. Erro ao ler JSON (corrompido, formato inesperado, caminho que é symlink) bloqueia startup de forma fail-closed.
- Comandos offline `--watchdog-status` e `--reset-watchdog-circuit` consultam e reconhecem o bloqueio sem abrir/alterar a outbox ou contatar a API; reset mantém histórico temporal.
- Tests Rust incluem 3 subprocessos realmente finalizados por watchdog, código 70, retenção do diário e outbox inalterada. Outros testes cobrem limiar, recuperação por reset, expiração de janela, corrupção e worker inválido. Validadores das três plataformas foram atualizados.

### Critérios ainda parciais
- O circuito conta somente mortes registradas pelo watchdog. `SIGKILL`, crash externo, `ENOSPC`, morte do OS ou problema de energia podem continuar criando loops controlados apenas pelo supervisor; falta um circuito abrangente para quaisquer crashes.
- O diário não recebe transação SQLite ou gravação atômica em todas as plataformas; JSON parcialmente gravado é bloqueado e exige operador. Não existe telemetria remota de incidentes nem garantia de durabilidade de `fsync` em diretório; proteção de dados em repouso cobre payloads e não o diário.
- O teste em subprocesso confirma o exit local, **não** comprova o efetivo reinício/stop pelo systemd, SCM ou launchd sob sistema real. Necessária homologação de serviço no host e testes com impressoras reais.
- `NX-039` permanece PARCIAL; o conjunto de 324 NX e 62 QA ainda não foi homologado.

## Entrega 31 — incidentes locais no Tauri e relatório agregado no painel administrativo (14/10/2026)

### Incremento implementado
- Parser e controlador do diário do watchdog centralizados no `nexa-collector-core::recovery_guard` para evitar divergência: histórico sanitizado `WatchdogSnapshot` com estado, limiar, últimos 32 incidentes, timestamp UTC e último reset. Daemon passa a reutilizar o mesmo módulo, mantendo a semântica de fail-closed.
- Console Tauri expõe snapshot de watchdog e erro independente de SQLite; aba Recuperação apresenta histórico, bloqueios e orientações operacionais, sem mutação local nem exibição de credenciais.
- Heartbeat do daemon ganha objeto opcional `watchdog` com `recent_incidents` e `circuit_open` (somente diagnóstico agregado), enviado com a mesma autenticação bearer. JSON inválido omite apenas essa telemetria, sem fingir estado normal.
- Nova migração Laravel adiciona `watchdog_recent_incidents`, `watchdog_circuit_open_reported` e `watchdog_reported_at`. Timestamp é originado no servidor; payloads estritamente validados, sem campos extras. Listagem administrativa continua limitada por tenant e carteira e identifica o relato como histórico, não condição atual.
- Testes Rust de projeção, corrupção e payload sem dados sensíveis; Laravel de autenticação, limites, compatibilidade de agentes antigos e timestamp. Builds TypeScript e E2E existentes continuam mandatórios no CI.

### Pendências de aceite
- O Collector não consegue informar abertura do circuito enquanto está desligado; telemetria remota é **último relato**, não monitoramento contínuo. Falta mecanismo de alerta assíncrono quando heartbeat atrasa e política central de notificação.
- Sem teste E2E nativo com Tauri aberto e serviço real, alerta recebido sob interrupção de rede, redelivery de status e equipamentos físicos. `NX-039/041` continuam PARCIAIS, assim como o escopo NX/QA integral.

## Entrega 32 — incidentes administrativos por silêncio de heartbeat (14/10/2026)

### Incremento verificável
- Backend `CollectorHeartbeatMonitor` e comando `nexa:alerts:check-collectors`, com scheduler de 1 min/sem sobreposição, opt-in via `NEXA_COLLECTOR_HEALTH_ALERTS_ENABLED=true`, tolerância 60..86400 segundos (padrão 300). Excesso de silêncio do heartbeat **autenticado** é distinto de falha SNMP e ausência de leituras no dispositivo.
- `collector_health_incidents` é uma tabela própria com FK para agente, tenant e cliente, status `active/resolved/closed`, data anterior do heartbeat, timestamp da detecção e razão de encerramento. Índice parcial exclusivo bloqueia dois incidentes `active` por agente; locks serializam transições e mantêm eventos auditados com `origin=scheduled-heartbeat-check`.
- Recusa alertar agente nunca conectado, revogado ou de organização inativa; só considera heartbeat anteriormente observado e efetivamente vencido. Uma nova comunicação **posterior à abertura e recente** resolve; desativação fecha com razão `deactivated`, sem afirmar saúde recuperada. O upload de leituras não promove recuperação.
- Endpoint `/collector-health-incidents` protegido por autenticação multi-tenant, papéis de suporte e carteira de clientes; `collector-agents` expõe alerta ativo sem dados de segredo. Portal React apresenta alerta de comunicação por agente, filtros ativo/histórico e navegação paginada.
- Testes de integração Laravel cobrem ausência inicial, deduplicação, recuperação, reabertura, revogação/cliente/tenant inativo, opt-in e limiar inválido, auditoria, tenant/portfólio e histórico. Seguem obrigatórios CI SQLite/PostgreSQL, React e navegador.

### Restrições de aceite
- Alertas são **registros administrativos dentro do Nexa System**, não emails/push. Scheduler da instalação deve estar funcionando para atualização; sem agendamento efetivo, incidentes não surgem automaticamente.
- Silêncio do heartbeat não prova impressora offline, fim do processo nem perda de credenciais; o diagnóstico precisa cruzar outros sinais. Agente nunca conectado aparece como `no_heartbeat` na lista, mas não é alertado por ausência desde a criação. Falta observabilidade no período de instalação.
- Nenhum teste real de implantação em rede/Windows/Linux/macOS ou integração com impressoras físicas. `NX-039/041` e QA ponta-a-ponta permanecem PARCIAIS.

## Entrega 33 — notificações administrativas por e-mail, com escalonamento (14/10/2026)

- Mailer de texto CollectorHealthIncidentMail, dispatcher CollectorIncidentNotifier e comando nexa:alerts:notify-collectors, agendado a cada cinco minutos com prevenção de sobreposição. Opt-in NEXA_COLLECTOR_NOTIFICATIONS_ENABLED=false por padrão, exige monitor habilitado.
- Primeiro aviso aos owners/admins ativos e e-mails verificados do tenant; escalonamento após NEXA_COLLECTOR_ESCALATION_MINUTES (15..10080, padrão 60) apenas a owners. Registro único por incidente, destinatário e estágio, status attempting/accepted/failed/skipped.
- Persistência e auditoria de cada resultado, com falhas sanitizadas e sem retransmissão automática sob resultado ambíguo. API de histórico fornece apenas contagens agregadas por incidente dentro do tenant/carteira; portal exibe aceite do transporte, não recebimento no inbox.
- Testes Laravel Mail fake: opt-in, papéis/contas verificadas, idempotência, tempo de escalonamento, recuperação/encerramento, erros sanitizados e autorização.
- Limitações: mailer log não entrega correspondência; sem validação SMTP real ou bounces, sem replay seguro, sem transação distribuída SMTP/DB, sem ensaio de queda de energia, scheduler real ou impressoras físicas. NX-039/041 e QA integrais continuam PARCIAIS.

## Entrega 34 — orçamento persistente de e-mails e reconciliação de devoluções (14/10/2026)

### Implementação
- Novo lock serializado em banco `collector_notification_rate_locks` protege reservas de tentativas concorrentes. Cotas de até 50 por execução, 20/tenant/hora e 100 globais/hora por padrão, validadas por comando e serviço, configuradas exclusivamente por ambiente de backend. Janela de 60 min depende de timestamps de reserva persistidos; tentativas `failed/attempting/skipped/returned/complaint` contam conservadoramente.
- Em falta de capacidade, reserva não é criada e o aviso permanece apto a envio posterior quando o orçamento voltar; comandos imprimem total `deferred`. Antes da reserva verifica novamente estado de incidente, agente, cliente, tenant, papel e e-mail confirmado do destinatário.
- Comando local `nexa:alerts:reconcile-collector-mail` permite classificar e auditar manualmente um envio anteriormente aceito como `returned` (hard/soft bounce) ou `complaint` após confirmação explícita de revisão. Não há retry; alterações repetidas são recusadas.
- API e portal mostram apenas contagens por categoria, sem emails ou payloads; testes incluem orçamento por tenant e global, janela móvel, bloqueio por execução, falha contabilizada, entrada inválida e reconciliação.
- O envio permanece DESATIVADO por padrão; `MAIL_MAILER=log` nunca confirma envio SMTP real.

### Pendências de aceite
- Sem ingestão autenticada de DSN/webhooks, sem prova de entrega real, sem reenvio após revisão, sem avaliação de concorrência em múltiplos processos com transporte SMTP real. Cotas globais se aplicam somente aos workers no mesmo banco de dados.
- Teste unitário/feature não significa homologação de fornecedor de e-mail; precisa de infraestrutura e serviço operacional, medições em produção e limites de spam. NX-039/041 permanecem PARCIAIS.

## Entrega 35 — replay auditado e único de falha confirmada pelo operador (15/10/2026)

- Tabela independente `collector_notification_replays`, com referência administrativa de revisão, status e timestamps, FK tenant/alerta/original e índice único por tentativa original; impede replay duplo ainda que dois processos façam reserva em concorrência.
- CLI local `nexa:alerts:replay-collector-mail ID --review-reference=CASE-123 --confirm-not-accepted`, sem agendamento nem endpoint HTTP. Exige revisão externa explícita de não aceitação pelo transporte; aceita só origem `failed`, incidente ativo, proprietário/admin ativo, e-mail verificado e cliente/tenant/agente ativos.
- Reserva usa o mesmo `collector_notification_rate_locks` da entrega 34 e computa mensagens originais **e** replays em orçamentos horário por tenant e globais; falta de cota adia sem criar linha. O scheduler automático também considera os replays nos dois orçamentos.
- Resultados do replay são auditados sem corpos de mensagens, destinatários ou exceções SMTP. Falhas ou ambiguidade não são reenviadas; a tentativa original mantém sua condição. Endpoint/portal apresentam somente contagens agregadas por incidente e carteira.
- Testes Laravel devem cobrir autorização explícita, única tentativa, bloqueio de originais aceitos/ambíguos/devolvidos, revogação, incidente recuperado, cotas compartilhadas, falhas sanitizadas e opção desabilitada.
- **Sem webhooks/DSN assinados, confirmação de recebimento, SMTP real ou homologação operacional**. NX-039/041 permanecem PARCIAIS.

## Entrega 36 — correlação opcional com Resend e eventos assinados (15/10/2026)

- Adaptador `CollectorMailTransport` envia pela API Resend somente com `NEXA_COLLECTOR_MAIL_DRIVER=resend`, armazenando o UUID de saída de cada tentativa original ou reenvio e usando `Idempotency-Key`. Driver Laravel genérico continua sem provider_id e não é correlacionado por e-mail.
- Novo endpoint autenticado criptograficamente `/api/v1/webhooks/resend` valida `svix-id`, `svix-timestamp`, `svix-signature` sobre JSON bruto: HMAC-SHA256 com segredo base64 `whsec_`, comparação constante, janela ±300s, size ≤65536 bytes e rate limit. Configuração opt-in.
- Tabela `collector_mail_webhook_events` persiste **apenas hashes dos IDs Svix** com índice UNIQUE, tipo/outcome sanitizados, alvo opcional e timestamp de recepção, garantindo idempotência de redeliveries via banco. Sem armazenar conteúdo, destinatário ou segredo do provedor.
- `ResendWebhookProcessor` trata `email.delivered`, `email.bounced`, `email.complained` por `email_id` exatamente associado a notificação original ou replay e audita transições. Devoluções/denúncias prevalecem sobre eventos positivos atrasados; nunca usa assunto ou e-mail como chave.
- API/portal passam a diferenciar contagem `delivered` reportada pelo provedor, aceite técnico de envio e estados de bounce/reclamação; testes fake HTTP/Resend cobrem assinaturas, data, idempotência, correlação, ordem e isolamentos.
- **Aceite pendente:** testagem real com domínio Resend verificado, DNS/remetente, HTTP webhook público, rotação de segredo, webhook precoce `unmatched` e validação de entrega real. Não há prova de abertura da caixa postal nem E2E físico. NX-039 e NX-041 continuam PARCIAIS.

## Entrega 37 — reconciliation de webhook Resend antecipado (15/10/2026)

- Migração amplia `collector_mail_webhook_events` com `provider_email_id` e `bounce_category` sanitizada e dois índices para consultas pendentes, preservando o hash único Svix de redelivery. Sem dados pessoais, assunto ou corpo de e-mail.
- Ingestão de evento verificado HMAC cria `pending` caso UUID ainda não seja conhecido e aplica de maneira transacional quando ele é localizado. `duplicate` continua bloqueado pelo hash único. Precedência de status não permite que entrega tardia reverta bounce/complaint.
- Após `accepted` de envio original ou reenvio, `reconcileForProviderEmailId` tenta aplicar pendências desse UUID, sem novo envio. O comando adicional `nexa:mail:reconcile-resend-webhooks` faz sweep de 1 minuto com `withoutOverlapping`, limitado a 100 eventos correlacionáveis por padrão, sem starvation de eventos desconhecidos.
- Pendências expiradas após 72 horas por padrão recebem `expired`, **sem apagar hash de deduplicação**. Configuração permite lote 1..500 e retenção 1..168 horas; inválida falha fechada.
- Testes Laravel integram chegada antecipada, aplicação imediata, replay, correção por sweep agendado, eventos desconhecidos, sanitização, expiração e opt-in.
- **Ainda parcial:** sem ensaio de webhook físico de Resend, política de limpeza de hashes, alarmes de filas pendentes, validação carga concorrente/restart e testes de entregabilidade real. NX-039/041 permanecem PARCIAIS.


## Entrega 38 — monitoramento operacional do backlog Resend (15/10/2026)

- Adicionado diagnóstico `ResendWebhookBacklogMonitor` de leitura agregada e comando `nexa:mail:check-resend-backlog` com modo JSON e código de saída de alerta. Configuração opt-in `NEXA_RESEND_MONITOR_ENABLED` e limites estritos de idade e contagem.
- Distingue pendências com UUID do provedor já correlacionável de eventos sem alvo; não mistura estes dados com carteira ou tenant, já que UUID desconhecido não autoriza identificação de empresa.
- Informa contadores `pending/known/unknown/overdue/applied/stale/expired`, idade da pendência mais antiga e severidade. Comando não muta eventos nem dispara envio. Os totais `expired` são históricos, não alertas de expiração recente.
- Testes automatizados de fila vazia, warning, critical, capacidade, desativação, configuração inválida, não exposição de identificadores, histórico e ausência de escrita. Integração com monitor externo é manual; nenhum webhook/e-mail adicional é enviado.
- Sem validação de tráfego real, instalação operacional de monitor externo, simulação multi-worker e aceite de todos os 324 requisitos; NX-039/041 continuam PARCIAIS.


## Entrega 39 — reconciliação segura após indisponibilidade prolongada do Scheduler (15/10/2026)

- Bug corrigido: a rotina de expiração de eventos `pending` descartava indistintamente eventos que já estavam relacionados a um UUID conhecido caso o Scheduler ficasse indisponível além da retenção de 72h. Agora usa consultas `NOT EXISTS` nas notificações originais e reenvios antes de expirar, sem descartar eventos comprovadamente correlacionáveis.
- A execução agendada continua processando eventos correlacionáveis em lotes, independentemente da idade; desconhecidos antigos ainda expiram e preservam seu hash Svix para impedir reaplicação após redelivery.
- Teste de regressão cobre parada >72h, evento correlacionado aplicado com resultado de bounce, evento desconhecido expirado, status e auditoria idempotentes.
- A decisão de correlação e expiração é dependente do estado do banco; ainda faltam ensaios de interleaving multi-worker, webhook real, observabilidade externa e homologação de produção. NX-039/041 PARCIAIS.

## Entrega 40 — último contador persistido no parque (15/10/2026)

- Associação `Printer.latestReading` definida por `MAX(collected_at)` e `MAX(id)` no mesmo carimbo: usa origem da coleta, não timestamp do recebimento. Índice próprio para `printer_id,collected_at,id`; eager loading na lista paginada evita N+1.
- `PrinterResource` expõe `latest_reading` somente quando carregado, com `null` na ausência de leituras. Campos de medição são strings decimais para não arredondar `BIGINT` no JavaScript; TypeScript converte somente para formatação `BigInt`.
- Portal `/parque` exibe Total/P&B/Cor, data da coleta ou indicador de falta de amostras; jamais interpreta dado persistido como disponibilidade atual.
- Testes feature SQLite/PostgreSQL: ordem de coleta versus recepção, empates, valores acima de 2^53, nulos, isolamento multi-tenant/carteira e regressão N+1 em 30 impressoras. E2E do navegador confere impressora nova sem leitura.
- Sem validação SNMP/USB real, sinal de status atual, consumo, fechamento de mês ou faturamento; escopo NX e 62 cenários de aceite continuam PARCIAIS.

## Entrega 41 — histórico paginado de contadores por impressora (15/10/2026)

- Rota autenticada `GET /printers/{printer}/readings` atende apenas impressoras do tenant e carteira do usuário, verificadas no middleware e de novo pela consulta scoped no controller. Data de origem armazenada em UTC; filtro por dia inclusivo no final usando comparação exclusiva à meia-noite seguinte.
- `PrinterReadingHistory` consulta diretamente o índice de `printer_readings` por tenant/impressora, ordena por `collected_at DESC,id DESC`, limita `per_page` a 100 e não carrega todas as amostras nem as relaciona à impressora via N+1.
- A API preserva contadores de 64 bits como strings decimais; canais não disponíveis continuam nulos. UI traz janela modal, filtros de datas, paginação e aviso explícito de que o histórico não comprova estado online/produção por período.
- Testes Laravel abrangem paginação, data UTC inclusiva, validação estrita, empates, leituras com recebimento posterior, ausência de amostras e isolamento por tenant/carteira; Playwright testa a abertura da janela em cadastro real.
- Pendente: homologação física, investigação de reset/rollover, apuração de produção entre leituras, custos/contratos e aceitação dos 324 requisitos completos.

## Entrega 42 — prévia conservadora de incrementos de contadores (15/10/2026)

- `PrinterProductionPreview` compara apenas amostras com dois timestamps distintos **dentro da janela**. Não soma valores inicial/final cruzando a borda da janela. Consulta `printer_readings` com escopo explícito de tenant/impressora, ordenação cronológica, cursor e limite de 20.000 amostras; período é restrito a 31 dias UTC.
- Canal ausente não equivale a zero; quedas de contador/rollover não geram incremento negativo ou suposta produção. Duplicidade de timestamp com valores idênticos é agrupada; divergência invalida intervalos adjacentes.
- Aritmética de inteiros de medição é decimal exata (`UnsignedDecimal`) sem cast para float/int, incluindo acumulação potencialmente maior que 64 bits. Resposta informa pares observados, lacunas, quedas, conflitos e status; `billing_ready=false` é incondicional.
- A tela de histórico ganhou ação explícita "Prévia de incrementos", estado vazio e indicadores de revisão. Testes cobrem resets, nulls, ordem, precisão >2^53, tempos idênticos/conflitantes, fronteiras, tamanho da janela e segregação. Playwright testa prévia vazia.
- **Não é faturamento** nem prova de volume impresso completo; podem existir resets entre duas leituras, coletas perdidas, diferenças de origem do relógio, páginas copiadas/impressas ou outras particularidades de fabricante. Falta homologação real.

## Entrega 43 — seleção paginada, diagnóstico local e execução reproduzível (15/10/2026)

- Cadastros de impressora, chamado e alerta passaram a buscar clientes, impressoras e chamados por páginas na API, com opção selecionada preservada durante a navegação. Os filtros `customer_id` em impressoras e `printer_id`, `customer_id` e `open_only` em chamados atuam antes da paginação, sob o mesmo escopo de tenant/carteira. Formulários de estoque ainda têm seletores limitados.
- O Console do Collector abre a outbox SQLite em modo somente leitura e recusa uma fila inexistente sem criá-la. O watchdog recusa também um reset registrado no futuro, impedindo ocultar incidentes após retrocesso do relógio. `Retry-After` passa a aceitar segundos ou data HTTP válida, limitada a seis horas.
- O Compose local ganhou processo `scheduler`, saúde da API e senha única de PostgreSQL injetada na API e no agendador. O Dockerfile da API exclui `.env`, `vendor` e artefatos locais do contexto, usa `composer.lock` e executa como `www-data`. Lockfiles de Composer, Cargo e npm passam a fixar dependências; o CI ganhou um job para validar build da imagem da API e ausência de `.env` nela.
- Essas mudanças corrigem fluxos existentes e melhoram a repetibilidade local. Não comprovam segurança de uma instalação pública, funcionamento em hardware real, conclusão dos módulos planejados ou aceite integral dos 324 NX e 62 QA.

## Entrega 44 — contagem supervisionada, buscas paginadas e histórico íntegro (15/10/2026)

- `NX-103` avança parcialmente: `stock_counts` registra item/local, observação de saldo, último movimento da posição, quantidade contada, justificativa, autor e UUID idempotente. A revisão exige outro usuário com papel `owner`, `admin` ou `manager` e altera o razão somente dentro da transação de aprovação. Repetição da mesma operação mantém o mesmo resultado; reutilização do UUID com dados diferentes é recusada.
- A aprovação verifica saldo e último ID de movimento sob lock do SKU. Assim, uma entrada seguida de saída que restaura o saldo não autoriza ajuste sobre snapshot antigo. Contagem abaixo de reserva ou perda em posição com lote rastreado é rejeitada; excedente entra sem proveniência de lote. Rejeição preserva o razão. Auditoria registra as decisões.
- O portal lista contagens com paginação e filtro de status, permite registrar e revisar segundo papel/ator, mostra snapshot e diferença, e preserva o UUID de um formulário sem alteração ao repetir uma tentativa. Itens e locais do estoque, além de unidades, departamentos e centros de custo, ganharam `q` validado e busca no servidor antes da paginação. O histórico de alocação de impressoras ganhou navegação entre páginas sem perder a posição atual.
- O watchdog do Collector grava seu diário por arquivo temporário sincronizado e renomeação no mesmo diretório. Um lock de arquivo estável serializa o ciclo inteiro de leitura, alteração e substituição entre processos; teste com daemon/reset simulados em processos distintos preserva 16 incidentes e o reconhecimento. No Windows não há sincronização do diretório, e queda de energia/antivírus/serviço real não foram ensaiados.
- Teste Laravel local completo: **182 testes, 1521 asserções aprovados**. Build do portal, teste de alocação Playwright desktop/mobile e cenário Playwright de contagem com dois usuários (10 → 8, razão ajustada) passaram. `cargo test --workspace --locked`: **50 testes core + 12 daemon aprovados** após o bloqueio entre escritores. A validação PostgreSQL/CI desta entrega ainda depende de integração. A remoção do SQLite isolado de E2E foi bloqueada por revisão automática; `backend/database/*.sqlite` está ignorado pelo Git.
- Ainda faltam contagem por lote, conciliação do estoque legado, testemunho físico de contagem, política fiscal/contábil, testes de concorrência PostgreSQL da nova operação, hardware de impressão e homologação. `NX-103`, os demais requisitos NX e os cenários QA permanecem sem aceite integral.

## Entrega 45 — políticas e necessidades de reposição por posição (15/10/2026)

- `NX-104` avança parcialmente: `stock_reorder_policies` define crítico ≤ segurança ≤ mínimo ≤ alvo para cada item/local, inclusive sem saldo. As escritas validam tenant, papel, carteira completa e cadastro ativo, serializam por SKU, e registram criação, alteração e remoção em auditoria. A API lista posições paginadas e filtra por estado e item/local.
- Disponível = `max(0, físico - reserva pendente - lote vencido)`. Uma reserva sem vínculo de lote pode sobrepor um lote vencido posteriormente; o cálculo é intencionalmente um limite inferior conservador. A classificação distingue crítico, faixa de segurança, abaixo do mínimo, adequado e inativo. Posições inativas aparecem para consulta/remoção, sem sugestão; necessidade padrão as exclui. Sugestão ativa = `max(0, alvo - disponível)`.
- O portal oferece busca paginada para escolher item/local, formulário de política, filtros, saldos e sugestão. O Playwright percorre saldo zero, filtros, edição e remoção. Nenhuma compra, remessa ou pedido é disparado automaticamente.
- Um defeito encontrado pelo CI da entrega 44 foi corrigido: `/browser/auth/csrf` e `/browser/auth/login` usavam a mesma chave de throttling por IP, então handshakes legítimos esgotavam a quota de 5 logins/min. Prefixos separados também isolam a rota antiga de login; teste de regressão cobre seis CSRF seguidos por login válido.
- Permanecem pendentes aprovação e consolidação de sugestões em pedidos, planejamento por cliente/modelo/demanda, compra real, casos QA de logística, ensaios de concorrência PostgreSQL para a nova política e homologação operacional. `NX-104` e o sistema completo seguem PARCIAIS.
