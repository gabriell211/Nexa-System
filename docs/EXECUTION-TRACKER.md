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
- Política de retry ainda volátil, parser de Retry-After suporta apenas segundos, não HTTP-date. Com erro HTTP 422 de item antigo/sem permissão, a fila para naquele lote até intervenção; não há quarentena automática nem descarte.
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
