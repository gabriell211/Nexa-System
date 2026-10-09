# Nexa System — Portal administrativo

Interface React 19 + TypeScript estrito + TanStack Query conectada **exclusivamente à API real** Laravel. Nenhum dado ou fluxo simulado.

## Desenvolvimento

1. Inicialize PostgreSQL e a API conforme `backend/.env.example` e `docker-compose.yml`.
2. Execute migrações e `php artisan nexa:bootstrap` com credenciais fortes configuradas.
3. Em `portal/`: `npm install` e `npm run dev`.
4. Abra `http://127.0.0.1:5173/login`. Informe e-mail, senha e o slug do tenant.

O servidor Vite faz proxy de `/api` para `127.0.0.1:8080`. Em produção, configure o proxy reverso **na mesma origem** para servir `/api` pelo backend Laravel e o restante pela build do React.

## Entregue em código

Login e logout, dashboard com contagens reais, listagem/pesquisa/paginação de clientes e impressoras, inclusão/edição/inativação auditada e histórico para administradores. O controle de acesso do menu não substitui as verificações no servidor.

## Limitações

- O token de acesso está **somente na memória** (sem localStorage, sessionStorage ou cookies). Um reload requer novo login. Essa é uma restrição deliberada até existir autenticação web persistente por cookie HttpOnly/SameSite/CSRF.
- A lista de clientes no formulário de impressoras consulta até 100 clientes ativos; seleção paginada/autocomplete remoto é trabalho pendente.
- Portal do cliente, filtros avançados, colunas personalizadas, importações e exportações não estão prontos.
- Ainda faltam build, testes de browser e homologação com API/PostgreSQL em execução.
- Não há recuperação de senha, MFA ou controle de sessões no portal. Não liberar produção antes de implementar a autenticação web definitiva.

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
