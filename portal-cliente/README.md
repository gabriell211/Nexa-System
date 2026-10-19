# Nexa System — Portal do Cliente

Aplicação React 19 + Vite independente, instalada em `portal-cliente/`.
Ela **não compartilha páginas nem permissões de administração** com `portal/`.

## Páginas

| Caminho | Função |
|---|---|
| `/` | Painel: situação do parque, indicadores, gráfico de variação observada nos últimos 30 dias e ranking de impressoras |
| `/impressoras` | Parque próprio com status cadastral, fabricante, modelo, unidade, departamento, centro de custo, endereço IP, série e último total |
| `/chamados` | Página informativa: consulta de chamados não habilitada nesta etapa (não exibe dados falsos) |
| `/contadores` | Últimos contadores acumulados total, monocromático, colorido e digitalizações, com data real da coleta |
| `/localizacoes` | Unidade, departamento e centro de custo dos equipamentos; nenhum rastreamento geográfico |
| `/minha-conta` | Usuário atual, prestadora e permissão de leitura |

Todas as páginas consultam **exclusivamente** os endpoints browser:
`/api/v1/browser/client/printers`, `/api/v1/browser/client/summary`, `/api/v1/browser/client/analytics` e
`/api/v1/browser/auth/{csrf,login,me,logout}`.

O backend:
- exige sessão web com CSRF em alterações de autenticação;
- exige role `customer` **e** `customer_scope=assigned`;
- permite ver **somente** impressoras cujos `customer_id` estão em
  `customer_user_access` para o usuário autenticado **e** tenant atual;
- atualiza permissões a cada requisição: vínculos removidos deixam de aparecer imediatamente;
- usa leituras já existentes e preserva os valores de contadores como **strings** de inteiros decimais;
- não fornece rotas POST/PATCH/DELETE para alterar cadastro, nem acesso a estoque, credenciais, agentes ou outros clientes.

O usuário precisa ser previamente provisionado pela prestadora como membro ativo
do tenant com `role=customer`, `customer_scope=assigned` e um ou mais
vínculos explícitos em `customer_user_access`.
**A gestão de convites/contas de clientes ainda não faz parte desta entrega.**
Uma conta cliente sem vínculos enxerga zero equipamentos; o legado `customer_scope=all`
é bloqueado intencionalmente para essa role.

## Execução local

```sh
cd portal-cliente
npm ci
npm run dev
```

- Frontend cliente: http://127.0.0.1:5174
- Backend Laravel local: http://127.0.0.1:8080 (com API rodando).
- A autenticação é da prestadora/tenant e precisa de usuário cliente real provisionado.

Ou usar a pilha Docker Compose na raiz:

```sh
docker compose up -d api portal-cliente
```

Nesse caso, abra http://127.0.0.1:8082. O painel **administrativo**
continua separado em http://127.0.0.1:8081.

Para validar a aplicação isoladamente:

```sh
cd portal-cliente
npm ci --no-audit --no-fund
npm run build
```

Os testes PHPUnit `ClientPortalTest` verificam acesso entre clientes,
tenants, funções e revogação, além de precisão de contadores. O CI
valida também a imagem Docker e o nginx.

## Limites documentados

- `Ativa` significa ativo no cadastro, **não** necessariamente online agora.
- `Sem leitura`/travessão **não é zero páginas**.
- Contadores informam último valor acumulado recebido, sem inferir consumo
  mensal, cópias coloridas ou valores faturáveis quando faltam dados.
- Localização é a do vínculo administrativo, não descoberta por IP ou GPS.
- Campos de patrimônio, contrato e ponto de instalação físico da imagem
  de referência **ainda não possuem vínculo exposto por este endpoint**.
  Precisam de cadastro/relacionamento verificado antes de aparecer como valores reais.
- Dados reais dependem de coletor instalado, equipamento cadastrado e leituras
  disponíveis na API. Sem esses requisitos, a tela exibe ausência de dados.
- O Compose de desenvolvimento publica apenas no loopback; não é
  configuração para exposição pública sem HTTPS, proteção de sessão,
  monitoramento de serviços, backup e homologação de campo (#60).

Nexa é um sistema em desenvolvimento. Este PR entrega a consulta do parque
ao cliente; não atesta homologação completa do SaaS para produção.

## Organização do painel

A referência visual fornecida define **somente a disposição**, não as cores nem a identidade visual: menu lateral com Painel, Impressoras, Chamados, Relatórios (submenu Contadores/Localizações) e Configurações (Minha conta), cards de situação, gráfico grande de 30 dias e ranking por equipamento. A marca e paleta continuam independentes.

O gráfico **não inventa produção diária**: compara somente amostras válidas dentro do mesmo dia UTC para o mesmo equipamento, descartando lacunas, resets e leituras desconhecidas. Dias sem evidência aparecem em branco, não como zero. Ranking é baseado em diferenças **observadas e parciais** de P&B, nunca em faturamento; um parque maior que 20.000 amostras em 30 dias exige relatório dedicado no backend. A situação 'sem leitura registrada' não afirma falha de comunicação.
