# Nexa System — Portal administrativo

Interface React 19 + TypeScript estrito + TanStack Query conectada **exclusivamente à API real** Laravel. Nenhum dado ou fluxo simulado.

## Desenvolvimento

1. Inicialize PostgreSQL e a API conforme `backend/.env.example` e `docker-compose.yml`.
2. Execute migrações e `php artisan nexa:bootstrap` com credenciais fortes configuradas.
3. Em `portal/`: `npm install` e `npm run dev`.
4. Abra `http://127.0.0.1:5173/login`. Informe e-mail, senha e o slug do tenant.

O servidor Vite faz proxy de `/api` para `127.0.0.1:8080`. Em produção, configure o proxy reverso **na mesma origem** para servir `/api` pelo backend Laravel e o restante pela build do React.

## Entregue em código

Login persistente por cookie HttpOnly e CSRF, logout, dashboard com contagens reais, listagem/pesquisa/paginação de clientes e impressoras, inclusão/edição/inativação auditada e histórico para administradores. O controle de acesso do menu não substitui as verificações no servidor.

## Limitações

- O token de acesso está **somente na memória** (sem localStorage, sessionStorage ou cookies). Um reload requer novo login. Essa é uma restrição deliberada até existir autenticação web persistente por cookie HttpOnly/SameSite/CSRF.
- A lista de clientes no formulário de impressoras consulta até 100 clientes ativos; seleção paginada/autocomplete remoto é trabalho pendente.
- Portal do cliente, filtros avançados, colunas personalizadas, importações e exportações não estão prontos.
- Ainda faltam build, testes de browser e homologação com API/PostgreSQL em execução.
- Ainda não há MFA, recuperação de senha, revogação de sessões em outros dispositivos, testes reais de browser/HTTPS e auditoria completa de login. Não liberar produção sem finalizar esses itens.

## Portal em contêiner

Depois de configurar `backend/.env`, `.env` da raiz com a senha de PostgreSQL e aplicar as migrations:

```sh
docker compose up --build -d
docker compose exec api php artisan migrate --force
docker compose exec api php artisan nexa:bootstrap
```

O painel será servido em `http://127.0.0.1:8081` com proxy interno para `api:8080`. Este arranjo é **local/laboratório**, não uma pilha de produção: o Laravel ainda roda pelo servidor embutido `artisan serve`, há token temporário e não há TLS público, workers dedicados ou proteção de borda configurados.

Para uso remoto, publicar atrás de HTTPS e implantar API com um servidor de aplicação adequado, supervisão, backups e política de segredos.

## Estrutura de clientes

Na lista de clientes, clique no nome para entrar em `/clientes/:id/unidades`. O portal carrega unidades, departamentos e centros de custo da API; criação/edição/inativação são exclusivas de papéis autorizados. Dados históricos e regras de negócio não são representados por mocks.

## Login de navegador (entrega 4)

- `GET /api/v1/browser/auth/csrf` inicia a sessão e obtém o token CSRF.
- `POST /api/v1/browser/auth/login` autentica usuário/tenant e **regenera ID de sessão e CSRF**.
- `GET /api/v1/browser/auth/me` restaura identidade após recarregar a página.
- `POST /api/v1/browser/auth/logout` invalida a sessão no servidor.
- A API administrativa foi exposta em `/api/v1/browser/*` sob middleware `web`, `auth:web`, `nexa.browser-tenant` e as mesmas permissões. Coleta SNMP e API de bearer mantêm seu fluxo separado.
- O login legado por bearer em `/api/v1/auth/login` retorna 410 por padrão; ativar `NEXA_ALLOW_LEGACY_TOKEN_LOGIN=true` apenas durante uma migração controlada de clientes externos.
- Para desenvolvimento local **HTTP** configure `SESSION_SECURE_COOKIE=false`, como no exemplo. Em produção HTTPS, defina `SESSION_SECURE_COOKIE=true`. Não coloque o frontend em origem cruzada sem revisar CSRF/CORS e cookies.
- Sessões persistem no filesystem da API no exemplo, não são persistidas pelo frontend. Em produção utilize storage de sessões compartilhado, HTTPS validado, renovação de secrets, expiração e supervisão da aplicação.

## Histórico e alocação de impressoras

No parque, a ação de localização abre um painel com a unidade, departamento e centro de custo vinculados, histórico de instalações/retiradas e motivo da movimentação. Regras de vinculação e integridade são validadas pelo Laravel e também pelo PostgreSQL.

- Instalação e mudança de local não apagam histórico. Apenas uma alocação aberta é permitida por impressora.
- Transferência entre clientes não é feita editando `customer_id`, exige um processo próprio de migração de ativos/contratos, ainda pendente.
- Antes de inativar unidade, departamento ou centro de custo com impressoras, mova ou libere os equipamentos vinculados.
- As alocações são sempre registradas com data de ação atual; sem inventar datas históricas.
- O painel atualmente limita seletores aos 100 primeiros locais/centros ativos, e o histórico às 25 movimentações mais recentes. Busca paginada e seleção remota permanecem pendentes.

## Permissões por carteira

Administradores `owner/admin` acessam `/equipe` para conceder acesso a todos os clientes ou somente aos explicitamente selecionados. A filtragem ocorre no Laravel, inclusive ao acessar IDs pela URL, no dashboard e ao usar tokens bearer legados. Sem clientes atribuídos, a lista fica vazia; o acesso não é ampliado implicitamente.

A interface ainda não cria/invita operadores; a membership deve existir. Mudanças de papel, MFA e restrições mais granulares por unidade estão pendentes.

## Estoque — entrega inicial

A seção `/estoque` possui lista persistida de itens, depósitos/locais (provedor, técnico ou unidade de cliente), posições de saldo, entradas, transferências e baixas com justificativa. Requisições são transacionais no Laravel e contêm UUID de idempotência. Uma tentativa de retirar mais que o saldo retorna validação e não cria histórico.

Os papéis owner/admin/manager/warehouse com acesso à carteira inteira podem operar o estoque; usuários com carteira limitada não acessam os saldos de toda a operadora. O registro técnico exige vínculo do membro à operadora, e um local de cliente exige vínculo com unidade ativa.

**Ainda não é um WMS completo:** catálogo de fornecedores, lote, custo, compatibilidade de consumíveis, reservas, envio/recebimento parcial, perdas, devoluções, documentos fiscais, inventário físico, mínimos e logística não estão implementados. A UI usa seleção dos primeiros 100 registros; listas volumosas precisam de pesquisa remota e seleção paginada. Não confundir saída/entrada direta com mercadoria em trânsito.

## Reservas e remessas (entrega 8)

O painel `/reposicoes` permite reservar material livre, expedir quantidades parciais, confirmar recebimentos parciais e encerrar remessas. Saldo físico, saldo reservado e saldo disponível aparecem separadamente em `/estoque`.

- Reservar não movimenta fisicamente o material. A quantidade reservada deixa de estar disponível para outras saídas.
- Expedir dá baixa da origem e marca quantidade em trânsito.
- Receber adiciona ao destino apenas as unidades confirmadas.
- Encerrar cancela a parcela não expedida e registra explicitamente eventual falta em trânsito.
- Retentativas usam UUID idempotente, e as operações são auditadas.
- Somente owner/admin/manager podem registrar perdas e cancelamentos finais. Warehouse pode operar expedição e recebimento.
- A aplicação ainda não gerencia comprovantes, transportadoras, documento fiscal, lotes, reservas por chamado, confirmação por cliente, expedições multi-SKU ou previsão de reposição.

A tela de criação usa as primeiras 100 opções disponíveis; busca/paginação remota para catálogos grandes está pendente.

## Concorrência PostgreSQL na integração

A suíte CI executa um teste separado de processos reais (`scripts/postgres_stock_concurrency.php`), com conexões independentes ao PostgreSQL. Duas reservas ou duas baixas da última unidade devem produzir um único sucesso e uma única rejeição, mantendo saldo correto. O cenário passou em [CI #38421263725](https://github.com/gabriell211/Nexa-System/actions/runs/38421263725). Isso não substitui teste de carga nem prova tolerância a falhas em produção.

## Suprimentos — fornecedores e entradas fiscais (entrega 9)

A nova tela `/suprimentos` cria fornecedores por código, registra recebimento em lote/notas com custo em centavos e define quais fabricantes/modelos foram confirmados para um SKU. O catálogo de estoque permite informar marca, cor, preço, custo e rendimento nominal. A API evita saldo órfão: entrada física + metadados + auditoria são confirmados atomicamente.

**Atenção:** o lote de entrada é metadado fiscal, não saldo por lote. O sistema ainda **não** consegue afirmar qual lote físico saiu em uma transferência ou reposição, nem implementar FIFO/FEFO. Compatibilidade não cadastrada é reportada como desconhecida. Valores monetários representam BRL em centavos inteiros; retenção e impostos deverão seguir modelo fiscal específico.

## Controle de lotes e FEFO (entrega 10)

A aba **Lotes** em `/estoque` exibe SKU, lote, local, quantidade conhecida, validade e custo histórico. `/stock/lots` retorna esses dados por tenant com paginação. Baixas e transferências agora alocam lotes FEFO; movimentações registram as parcelas por lote. Em remessas, o rastreamento de lotes em trânsito conserva quantidade expedida, recebida e registrada como perda.

A coluna **Vencido** em saldos separa o estoque físico expirado conhecido do saldo disponível. Reservas recusam quantidades que só possam ser atendidas por lotes vencidos. Estoque legado sem lote continua disponível, mas sem proveniência falsamente atribuída.

**Atenção:** FEFO é uma regra contábil automática e pressupõe que o operador selecione fisicamente o lote correspondente. A confirmação efetiva do número de lote no picking e recebimento ainda não existe; recebimentos parciais não provam qual lote chegou sem conferência. Não declarar rastreabilidade física certificada ou FIFO/FEFO homologado em campo.

## Chamados técnicos e browser E2E (entrega 11)

`/chamados` expõe fila e filtros, cadastro de chamados internos e de cliente, atualização de estado e comentários internos/públicos, integrados à API Laravel. O backend verifica tenant, carteira de clientes, vínculos de impressora e autorizações de reabertura, com auditoria persistente.

`npm run test:e2e` usa **Playwright Chromium real** com perfis desktop e viewport mobile; no CI, ele executa contra o Laravel e o portal Vite iniciados para o teste (SQLite isolado e proprietário temporário). A suite não falsifica respostas da API, preserva o limite real de login e executa uma jornada por perfil para evitar atingir o throttle global. Os artefatos de falha contêm screenshots, vídeo, traces e estado acessível da página. O teste E2E não equivale a validação dos 324 requisitos nem à homologação no parque de impressoras.

Pendentes no Help Desk: SLA por calendário, anexos, ordens de serviço, peças/cobrança, notificações, portal cliente, vínculo de alertas/preventivas e métricas históricas por equipe.
