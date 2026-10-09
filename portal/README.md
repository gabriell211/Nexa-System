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
