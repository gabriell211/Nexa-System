# Nexa System — Portal do Cliente

Aplicação React 19 + Vite independente, instalada em `portal-cliente/`.
Ela **não compartilha páginas nem permissões de administração** com `portal/`.

## Páginas

| Caminho | Função |
|---|---|
| `/` | Painel: situação do parque, indicadores, gráfico de variação observada nos últimos 30 dias e ranking de impressoras |
| `/impressoras` | Parque próprio com status cadastral, fabricante, modelo, unidade, departamento, centro de custo, endereço IP, série e último total |
| `/chamados` | Listagem, abertura, seleção de até cinco impressoras, descrição e fotos/vídeos privados; detalhes e downloads autorizados |
| `/contadores` | Últimos contadores acumulados total, monocromático, colorido e digitalizações, com data real da coleta |
| `/localizacoes` | Unidade, departamento e centro de custo dos equipamentos; nenhum rastreamento geográfico |
| `/minha-conta` | Usuário atual, prestadora e permissão de leitura |

O frontend de leitura utiliza os endpoints browser, e a abertura de chamado realiza uma operação POST autenticada:
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

## Chamados com fotos e vídeos

O botão **Abrir chamado** segue o fluxo da referência visual: tipo de
ocorrência, botão Adicionar impressora, descrição e Salvar/Cancelar.
Tipos: Gaveta quebrada, Impressora atolando papel, Impressora fazendo
muito barulho, Impressora manchando as folhas, Outro e Solicitação de
toner reserva.

O cliente pode adicionar **até cinco impressoras do mesmo cliente**,
quatro anexos, no máximo **50 MiB por arquivo** e **100 MiB no conjunto**.
São permitidos JPEG, PNG, WebP, MP4, MOV e WebM (MIME e extensão
verificados no backend). São recusados executáveis, anexos acima do
limite e impressoras de outra carteira. Os arquivos ficam somente no
disco `local` privado Laravel (`storage/app/private/tickets`), com
nomes aleatórios. Não é gerada URL pública nem exposto o caminho físico.

Rotas:
- `GET /api/v1/browser/client/tickets` — lista paginada, somente
  clientes atribuídos e tenant autenticado;
- `POST /api/v1/browser/client/tickets` — `multipart/form-data`,
  com `category`, `description`, `printer_ids[]` e
  `attachments[]`; exige sessão, CSRF e limite 5 operações/minuto;
- `GET /api/v1/browser/client/tickets/{ticket}` — metadados e detalhes;
- `GET /api/v1/browser/client/tickets/{ticket}/attachments/{attachment}` —
  download privado somente após nova checagem de autorização;
- `GET /api/v1/browser/tickets/{ticket}/attachments/{attachment}` —
  download para equipe técnica autenticada e autorizada.

Chamados criados no portal entram na mesma fila `service_tickets` já
utilizada pela equipe, com `origin=customer_portal`, estado `triage`
e auditoria. Técnicos visualizam impressoras e anexos no detalhe do
painel interno. Nenhum cliente pode mudar prioridade, técnico atribuído,
estado do ticket, comentários internos ou permissões de outras empresas.

**Operação**: o container API configura PHP `upload_max_filesize=52M`
e `post_max_size=110M`; nginx do portal tem `client_max_body_size 110m`.
Para executar PHP diretamente sem Docker, configurar os mesmos limites
de maneira equivalente. Verificar persistência de `storage/app/private`
em produção: **o volume local do container API precisa de volume
persistente ou armazenamento privado externo antes do deployment**.
Implementar antimalware/quarentena e política de retenção de mídia para
a homologação de produção; a verificação MIME não substitui varredura
antivírus ou inspeção humana.

Os arquivos fornecidos são evidências do próprio cliente; o portal
não converte vídeos, não transcreve material e não torna a mídia pública.
