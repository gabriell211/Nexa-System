# Estado verificável de implementação

## Fundação criada em 08/10/2026

O monorepo agora possui código inicial de API Laravel 13, autenticação por token de 12 horas, associação de usuários a tenants, clientes, impressoras, dashboard de contagens persistidas e esquema mínimo de leituras.

**Sem afirmação de produção:** esta mudança não comprova execução de composer install, migrações, testes ou deploy em infraestrutura real.

### Implementação parcial

- NX-001: entidades tenant e associação de usuários; faltam unidades e isolamento em toda a plataforma.
- NX-002: cadastro inicial de clientes; faltam identificação completa, importação e demais atributos.
- NX-015/NX-016: função por associação, autorizações iniciais para inserção; RBAC completo pendente.
- NX-047/NX-048: inventário inicial de impressoras; faltam fluxos de descobertas e demais campos.
- NX-063/NX-071: tabela inicial de amostras e unicidade por UUID; falta ingestão, normalização e coleta.
- NX-192: dashboard inicial com contagens reais; os demais indicadores estão indisponíveis.

**Nenhum requisito NX deve ser marcado como integralmente concluído neste estágio.**

### Próximas entregas

1. Revisar e executar os testes de isolamento de tenants em PostgreSQL e SQLite.
2. Criar API de ingestão dedicada a agentes, com credenciais revogáveis e deduplicação.
3. Implementar Nexa Collector Rust com SNMP, fila SQLite, retries e diagnóstico.
4. Expandir permissões e portal web; adicionar trilha de auditoria.
5. Implementar ledger de estoque, chamados/SLA, contratos, cálculos e fechamento.
6. Validar cada item NX e QA com evidências e hardware real.

O README principal é a fonte de escopo. Este arquivo documenta o que existe em código, não o que está planejado.

## Collector Rust / Tauri / React — implementação parcial

- Workspace Rust com `nexa-collector-core` e daemon CLI separado.
- SNMPv2c inicial com OID explicitamente configurado por impressora e escopo CIDR autorizado.
- SQLite WAL outbox, UUID único, reenvio HTTPS e ACK da API.
- Endpoint Laravel para ingestão com token individual por coletor e validação por cliente/tenant.
- Console Tauri 2.12 + React 19 + TypeScript, exibindo estado local real em modo somente leitura.
- Cadastro e revogação de coletor via comandos CLI Laravel.
- Novos testes escritos para outbox e ingestão/revogação, sem execução validada.

**Limitações:** sem criptografia de outbox, sem cofre do SO, sem SNMPv3, sem IPC autenticado entre UI e serviço, sem instalador nativo de serviço, sem USB, sem descoberta automática, sem TLS mutual, sem update assinado e sem teste de campo. O Collector não está pronto para instalação em clientes.

Ver instruções e limitações em [collector/README.md](../collector/README.md). A stack do software concorrente original não foi verificada; Tauri + React é a escolha arquitetural do Nexa.
