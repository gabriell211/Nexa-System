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
