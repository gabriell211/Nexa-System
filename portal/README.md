# Nexa System — Portal administrativo

Interface React 19 + TypeScript estrito + TanStack Query conectada **exclusivamente à API real** Laravel. Nenhum dado ou fluxo simulado.

## Desenvolvimento

1. Inicialize PostgreSQL e a API conforme `backend/.env.example` e `docker-compose.yml`.
2. Execute migrações e `php artisan nexa:bootstrap` com credenciais fortes configuradas.
3. Em `portal/`: `npm ci` e `npm run dev`.
4. Abra `http://127.0.0.1:5173/login`. Informe e-mail, senha e o slug do tenant.

O servidor Vite faz proxy de `/api` para `127.0.0.1:8080` por padrão. Defina `NEXA_API_PROXY_TARGET` (por exemplo, `http://127.0.0.1:18080`) para usar outra porta local. Em produção, configure o proxy reverso **na mesma origem** para servir `/api` pelo backend Laravel e o restante pela build do React.

## Entregue em código

Login persistente por cookie HttpOnly e CSRF, logout, dashboard com contagens reais, listagem/pesquisa/paginação de clientes e impressoras, inclusão/edição/inativação auditada e histórico para administradores. Formulários de impressoras, chamados e alertas permitem buscar e paginar clientes/impressoras sem limitar a escolha aos 100 primeiros registros. O controle de acesso do menu não substitui as verificações no servidor.

## Limitações

- Portal do cliente, filtros avançados, colunas personalizadas, importações e exportações não estão prontos.
- A suíte Playwright executa jornadas reais em Chromium desktop e viewport mobile, mas a homologação com API/PostgreSQL em ambiente de produção e impressoras reais permanece pendente.
- Ainda não há MFA, recuperação de senha, revogação de sessões em outros dispositivos, testes HTTPS em ambiente real e auditoria completa de login. Não liberar produção sem finalizar esses itens.

## Portal em contêiner

Siga a ordem de inicialização em [Execução local com Docker Compose](../README.md#execução-local-com-docker-compose): configure os segredos, inicie o PostgreSQL, execute migrações e bootstrap pela imagem da API e só então suba API, scheduler e portal.

O painel será servido em `http://127.0.0.1:8081` com proxy interno para `api:8080`. Este arranjo é **local/laboratório**, não uma pilha de produção: o Laravel ainda roda pelo servidor embutido `artisan serve` e não há TLS público, workers dedicados ou proteção de borda configurados.

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
- O painel busca unidades, departamentos e centros de custo ativos em páginas de 25, mantendo a opção escolhida ao trocar de página ou pesquisar. O histórico também é paginado em 25 movimentações por página; a localização atual é consultada separadamente para continuar correta ao navegar no histórico.

## Permissões por carteira

Administradores `owner/admin` acessam `/equipe` para conceder acesso a todos os clientes ou somente aos explicitamente selecionados. A filtragem ocorre no Laravel, inclusive ao acessar IDs pela URL, no dashboard e ao usar tokens bearer legados. Sem clientes atribuídos, a lista fica vazia; o acesso não é ampliado implicitamente.

A interface ainda não cria/invita operadores; a membership deve existir. Mudanças de papel, MFA e restrições mais granulares por unidade estão pendentes.

## Estoque — entrega inicial

A seção `/estoque` possui lista persistida de itens, depósitos/locais (provedor, técnico ou unidade de cliente), posições de saldo, entradas, transferências e baixas com justificativa. Requisições são transacionais no Laravel e contêm UUID de idempotência. Uma tentativa de retirar mais que o saldo retorna validação e não cria histórico.

Os papéis owner/admin/manager/warehouse com acesso à carteira inteira podem operar o estoque; usuários com carteira limitada não acessam os saldos de toda a operadora. O registro técnico exige vínculo do membro à operadora, e um local de cliente exige vínculo com unidade ativa.

**Ainda não é um WMS completo:** contagem física por lote, confirmação física de lote no picking, devoluções, mínimos, logística e integrações fiscais permanecem pendentes. O fluxo agregado de contagem supervisionada aparece abaixo. Catálogo, custo, compatibilidade, reservas, remessas parciais e rastreamento contábil de lotes são descritos nas entregas seguintes. Alguns formulários de estoque ainda usam os primeiros 100 registros; listas volumosas precisam de pesquisa remota e seleção paginada. Não confundir saída/entrada direta com mercadoria em trânsito.

## Contagens físicas supervisionadas (entrega 44, parcial)

Na aba **Contagens** de `/estoque`, um operador registra item, local, quantidade física e justificativa. Os seletores de item/local consultam páginas e pesquisa no servidor. O backend guarda saldo e último movimento observados; outra pessoa com papel owner/admin/manager pode aprovar ou rejeitar com justificativa. Aprovação cria o ajuste no razão somente se a posição não mudou após o registro. Uma tentativa reenviada sem editar o formulário conserva o mesmo UUID idempotente.

O fluxo não comprova sozinho a contagem física, nem aceita perda em uma posição com lote rastreado sem identificar o lote. O excedente é registrado sem lote conhecido. Reconciliação de estoque legado, ensaio operacional e aceite `NX-103` continuam pendentes.

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

## Incidentes manuais e alertas (entrega 12)

A nova rota `/alertas` expõe **observações humanas**, com severidade, código, categoria, deduplicação, estado e vínculo ao chamado da mesma impressora. Um incidente com o mesmo código e origem ainda aberto retorna o registro existente, sem duplicidade. O backend rejeita vinculação entre empresas/clientes/impressoras distintos e audita cada mudança com autor e motivo. O formulário de `/chamados` agora também permite selecionar a impressora.
 
Na mesma sessão autenticada por dispositivo, o Playwright acrescenta um fluxo completo de cliente → impressora → chamado → alerta → vínculo → resolução → logout em Chromium desktop e mobile. Isso **não** equivale a geração automática de alertas por telemetria nem prova compatibilidade SNMP, regras preventivas, SLA ou notificações.

## Aviso sobre lacunas automáticas de coleta (entrega 13)

A UI diferencia `operator` de `telemetry`: a segunda origem significa **apenas ausência de leituras recentes registrada pelo servidor**, não prova de impressora offline. Configure `NEXA_TELEMETRY_GAP_ENABLED=true` somente após conferir a periodicidade real de coleta. Requer `php artisan schedule:run` com cron externo a cada minuto, ou `schedule:work` no ambiente supervisionado, além de configuração de tolerância e janela de recuperação. Sem isso, a regra não executa. Alertas associados a chamados não são encerrados automaticamente.

## Agentes de coleta — diagnóstico de heartbeat (entrega 14)

A rota `/agentes` utiliza a API de agentes da empresa e apresenta, de forma separada, último heartbeat autenticado e último upload de contadores. Um agente com heartbeat recente não é necessariamente uma impressora com comunicação SNMP bem-sucedida. Estados `no_heartbeat`, `late` e `revoked` representam ausência observada de comunicação ou revogação da credencial, não diagnóstico conclusivo do host. Dados filtrados por carteira e atualizados pela API a cada minuto.
