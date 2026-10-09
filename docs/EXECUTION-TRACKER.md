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
