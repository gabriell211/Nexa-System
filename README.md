# Nexa System

> **Status do repositório: escopo/documentação — nenhuma funcionalidade foi implementada ou validada em produção.**
> Este README é a especificação inicial e matriz de requisitos de uma plataforma **independente** de gestão de outsourcing de impressão (MPS). As caixas permanecem desmarcadas até implementação + testes.

**Nexa System** é uma plataforma para monitorar e administrar impressoras, dispositivos de TI, clientes, suprimentos, técnicos, chamados, contratos, produção, alertas, logística e faturamento em um único sistema.

## Controle de cobertura

- **Requisitos documentados**: 324 itens numerados, todos pendentes.
- **Testes de aceite descritos**: 62 cenários de ponta a ponta.
- **Fonte**: documentação pública oficial consultada em 08/10/2026.
- **Implementação verificada**: nenhuma funcionalidade concluída. Repositório contém documentação.
- **Paridade completa com ambiente autenticado**: ainda não verificável sem avaliação autorizada dos módulos, perfis, telas e equipamentos.

## Objetivo e referência funcional

O produto é inspirado no **escopo funcional publicamente documentado** do PrintWayy Dragon, com marca, interface, código, ativos e arquitetura próprios. Não se pretende copiar código-fonte, imagens, identidade visual, dados privados, credenciais nem endpoints internos de terceiros. Busca-se **paridade de processos de negócio**, com melhorias de confiabilidade e experiência de uso.

**Atenção à cobertura:** o site público e a Central de Ajuda permitem mapear muitos comportamentos, mas **não comprovam 100% das telas, permissões e regras internas de uma conta autenticada**. Itens não verificáveis estão assinalados como *pendentes de validação real*, nunca como recursos existentes. O inventário abaixo é uma meta de produto, não prova de implementação.

### Princípios inegociáveis

1. **Nada de telas fictícias:** um botão só é entregue quando seu fluxo real funciona no backend, banco e permissões.
2. **Nenhum mock em produção:** dashboard, indicadores e relatórios usam dados persistidos e rastreáveis.
3. **Coleta resiliente:** sem internet, o agente persiste as leituras e sincroniza depois sem duplicar.
4. **Dinheiro e contadores auditáveis:** fechamento congelado nunca muda silenciosamente.
5. **Isolamento entre clientes e provedores:** autorização aplicada em *toda* consulta, ação, exportação e evento.
6. **Cobertura por hardware verificada:** homologar cada modelo/protocolo/capacidade, evitando números fictícios.
7. **Operação de ponta a ponta:** da instalação ao suporte e faturamento, incluindo erros, cancelamentos e recuperação.
8. **Requisitos testáveis:** cada item possui identificador único; marcar apenas após prova funcional.

## Escopo completo — matriz de requisitos

> Legenda: **[ ] pendente**. Cada requisito numerado é parte do escopo proposto e deve gerar tarefas de implementação, validação e testes de aceite. Todos começam pendentes.

### 1. Base, tenants, clientes e locais

- [ ] **NX-001** Operação multiempresa (provedores/tenants) isolada, com estrutura de unidades do próprio provedor e respectivos CNPJs.
- [ ] **NX-002** Clientes pessoa jurídica/física, razão social, nome fantasia, documento, inscrição, contatos e código externo de ERP.
- [ ] **NX-003** Unidades/filiais com endereço, documento próprio ou compartilhado e identificador de localização.
- [ ] **NX-004** Departamentos, setores, centros de custo, contatos e responsáveis por localidade.
- [ ] **NX-005** Vínculos entre cliente, unidade, contrato, impressora, dispositivo, responsável e centro de custo com histórico temporal.
- [ ] **NX-006** Cadastro, edição, ativação e inativação sem exclusão destrutiva de registros vinculados.
- [ ] **NX-007** Importação validada de clientes, equipamentos e contratos por CSV/Excel com prévia e relatório de erros.
- [ ] **NX-008** Chaves individuais de registro dos agentes por cliente, revogáveis e com rotação segura.
- [ ] **NX-009** Configuração de horários de expediente do provedor e de cada filial do cliente, incluindo feriados e fuso.
- [ ] **NX-010** Ativação granular de módulos por provedor, cliente e impressora e valores-padrão para novos clientes.
- [ ] **NX-011** Pesquisa global, filtros persistentes, paginação, classificação, seleção múltipla, exportação e colunas personalizáveis.
- [ ] **NX-012** Histórico de ações por entidade com autor, origem, antes/depois e carimbo de data/hora.

### 2. Identidade, perfis e portal do cliente

- [ ] **NX-013** Login, logout, recuperação e redefinição segura de senha, convite por e-mail e verificação da conta.
- [ ] **NX-014** Autenticação multifator para perfis privilegiados; sessões gerenciáveis e revogação.
- [ ] **NX-015** Perfis: dono da plataforma, administrador do provedor, financeiro, gestor, supervisor, técnico, almoxarifado e cliente.
- [ ] **NX-016** RBAC por ação (listar, ver, criar, editar, excluir, exportar, congelar, administrar) e autorização objeto a objeto.
- [ ] **NX-017** Restrições simultâneas por provedor, clientes atribuídos, unidades, departamentos e módulos licenciados.
- [ ] **NX-018** Usuários de cliente vinculados a um ou mais clientes, com limites por localização.
- [ ] **NX-019** Usuários internos do provedor com acesso a todos os clientes ou apenas carteiras/unidades autorizadas.
- [ ] **NX-020** Portal do cliente para equipamentos, consumos, suprimentos, chamados, relatórios e recebimentos autorizados.
- [ ] **NX-021** Preferências de idioma (PT-BR inicialmente), tema, notificações, período e colunas.
- [ ] **NX-022** Auditoria de login, tentativas suspeitas, autenticação e acesso administrativo.

### 3. Agente local e comunicação

- [ ] **NX-023** Agente local multiplataforma planejado para Windows, Linux e macOS, com matriz explícita de recursos suportados por sistema.
- [ ] **NX-024** Instaladores assinados, versão de serviço, execução em segundo plano, inicialização automática e desinstalação limpa.
- [ ] **NX-025** Ativação pelo cadastro do cliente com chave de instalação e identificação única do ponto de coleta.
- [ ] **NX-026** Serviços/processos separados logicamente: descoberta/coleta, transmissão, atualização e supervisor de saúde.
- [ ] **NX-027** Descoberta autorizada por broadcast, subnet e intervalos de IP configurados; varredura programada e manual.
- [ ] **NX-028** Cadastro direto de IP e preparação remota para monitoramento quando o agente reconhecer o dispositivo.
- [ ] **NX-029** SNMP v1/v2c/v3, perfis de credenciais, OIDs padronizados e adaptadores por fabricante/modelo.
- [ ] **NX-030** HTTP/HTTPS, IPP e autenticação do fabricante onde necessário, sem contornar segurança do equipamento.
- [ ] **NX-031** Descoberta de impressoras USB, com suporte condicionado ao sistema operacional, drivers e fabricante.
- [ ] **NX-032** Configuração de credenciais por impressora com armazenamento cifrado, nunca na interface ou em logs.
- [ ] **NX-033** Coleta configurável de contadores, capacidade, consumíveis, eventos, dados de identificação e estado.
- [ ] **NX-034** Intervalos separados de consulta, descoberta, heartbeat e upload, com jitter, timeout e backoff.
- [ ] **NX-035** Fila de envio persistente, criptografada, limitada em disco, reenvio ordenado e deduplicação no backend.
- [ ] **NX-036** Envio por HTTPS/TLS usando apenas conexão iniciada pelo agente; compatibilidade com proxy autenticado.
- [ ] **NX-037** Canal alternativo de transporte por e-mail SMTP/POP3 somente se autorizado e implementado com cifragem, autenticação e limites.
- [ ] **NX-038** Diagnóstico local, teste de comunicação, teste de SNMP, validação de IP e logs sanitizados.
- [ ] **NX-039** Monitoramento e recuperação de serviços travados com supervisor, sem loop infinito de reinicialização.
- [ ] **NX-040** Atualização automática controlada, assinatura/verificação de artefatos, rollback e política de versões mínimas.
- [ ] **NX-041** Monitoramento de pontos instalados com data de última comunicação, versão, rede e estado.
- [ ] **NX-042** Reinstalação e recuperação dos dados locais sem replicar leituras já recebidas.
- [ ] **NX-043** Configuração remota segura limitada aos agentes do próprio provedor e a usuários autorizados.
- [ ] **NX-044** Identificação de duplicidade de agentes ou colisão de identidade de impressora.
- [ ] **NX-045** Política de retenção de dados na borda, limites de memória/CPU e suporte a clientes com conexão intermitente.
- [ ] **NX-046** Inventário e diagnóstico de compatibilidade de hardware sem declarar homologado um modelo não testado.

### 4. Parque, ciclo de vida e impressoras

- [ ] **NX-047** Parque completo, impressoras por cliente, novas impressoras e impressoras com monitoramento duplicado.
- [ ] **NX-048** Cadastro de fabricante, modelo, série, IP, MAC, patrimônio, responsável, proprietário, localização e departamento.
- [ ] **NX-049** Separação entre equipamentos próprios do provedor e pertencentes ao cliente.
- [ ] **NX-050** Registro de monitoramento automático em rede, USB, monitoramento por IP preparado remotamente e contadores manuais.
- [ ] **NX-051** Visões de disponível no estoque, instalado, em reparo, reserva/backup, retirado, vendido e descartado.
- [ ] **NX-052** Impressoras de backup sem falso alerta por período normal desligado.
- [ ] **NX-053** Histórico de passagens por clientes e datas de instalação/recolhimento/movimentação.
- [ ] **NX-054** Desativação com data efetiva, reativação com novo histórico ou continuidade deliberada do histórico anterior.
- [ ] **NX-055** Detecção de impressora nova usando IP já ocupado, alteração de IP e série inconsistentes.
- [ ] **NX-056** Correção assistida de duplicações entre clientes preservando trilha de auditoria.
- [ ] **NX-057** Vínculo automático opcional ao contrato-padrão e custos-padrão do cliente.
- [ ] **NX-058** Detalhes por equipamento com geral, contadores, suprimentos, histórico, gráficos, comentários, configurações e ações.
- [ ] **NX-059** Gerenciamento de recursos de monitoramento, alertas e regras de SLA específicos por impressora.
- [ ] **NX-060** Colunas, filtros, seleção em lote, relatório de sucesso/falha e exportação Excel.
- [ ] **NX-061** Status diferenciados: comunicando, sem comunicação, manual, aguardando detecção, reserva, não monitorado e descartado.
- [ ] **NX-062** Inventário de modelos homologados com teste por protocolo/capacidade, não apenas marca.

### 5. Telemetria, contadores e produção

- [ ] **NX-063** Armazenar amostras imutáveis brutas, normalizadas e suas origens, com timestamps de coleta e de ingestão.
- [ ] **NX-064** Contadores geral, P&B, cor, digitalização e detalhes disponíveis (cópia, impressão, cor única/duas cores etc.).
- [ ] **NX-065** Mapeamento por fabricante/modelo de qual contador entra em cada categoria faturável; impedir dupla contagem.
- [ ] **NX-066** Suporte a relatórios de páginas diárias, mensais, intervalo customizado e série histórica por impressora/cliente/contrato.
- [ ] **NX-067** Lançamento manual com data, autor, justificativa e valores P&B/cor/digitalização, inclusive para aparelhos sem conectividade.
- [ ] **NX-068** Correção de leituras manuais mediante política de auditoria e vedação se associadas a período financeiro congelado.
- [ ] **NX-069** Validação de leituras decrescentes, overflow, reset, troca de formatter/placa e substituição de equipamento.
- [ ] **NX-070** Tratamento de dados ausentes, antigos, inconsistentes, duplicados, fora de ordem e envio tardio.
- [ ] **NX-071** Idempotência e chave única por amostra para impedir produção duplicada por retransmissão.
- [ ] **NX-072** Reconciliação entre contador inicial e final por período e por passagem do equipamento.
- [ ] **NX-073** Produção parcial por instalação ou retirada no meio do período.
- [ ] **NX-074** Compatibilidade consciente das diferenças de contagem A3/A4 e tamanhos de mídia; configurar conversão somente quando comprovada.
- [ ] **NX-075** Gráficos com datas e fuso corretos e distinção clara entre produção zero e produção desconhecida.
- [ ] **NX-076** Armazenamento histórico particionado/retenção configurável e consulta eficiente em parque grande.
- [ ] **NX-077** Relatórios de impressões/cópias, digitalizações e totais por cliente com grupos por unidade e departamento.

### 6. Suprimentos, peças e inteligência de consumo

- [ ] **NX-078** Catálogo de suprimentos e peças com tipo, marca, modelo, código SKU, cor, preço, custo, rendimento e compatibilidade.
- [ ] **NX-079** Tipos personalizáveis (toner, cilindro, revelador, fusor, kit, reservatório, tinta e outros) com controles por tipo.
- [ ] **NX-080** Leitura e histórico de nível, capacidade, série, cobertura e indicadores reportados pela impressora.
- [ ] **NX-081** Separação entre nível informado pelo hardware e nível real estimado com confiabilidade explícita.
- [ ] **NX-082** Detecção de possível troca por aumento de nível, alteração de capacidade/série ou registro humano.
- [ ] **NX-083** Troca manual pelo técnico/cliente, com permissões e pendência de confirmação quando necessário.
- [ ] **NX-084** Listas de próximas trocas, pendentes, confirmadas, excluídas e prematuras.
- [ ] **NX-085** Confirmar, rejeitar, excluir logicamente, desconsiderar ou reativar para cálculos de médias, sempre com auditoria.
- [ ] **NX-086** Origem obrigatória da peça nova: estoque do provedor, técnico ou unidade do cliente, com validação de saldo.
- [ ] **NX-087** Confirmação automática opcional por cliente, condicionada a saldo, compatibilidade e ausência de conflito.
- [ ] **NX-088** Detecção de leituras inválidas/chipless e prevenção de trocas fictícias; revisão humana em casos duvidosos.
- [ ] **NX-089** Cálculo de páginas produzidas entre trocas confirmadas; rendimento observado, eficiência e cobertura.
- [ ] **NX-090** Estimativa de consumo individual por equipamento e suprimento; previsão de data e nível real.
- [ ] **NX-091** Definir volume mínimo de histórico antes de produzir previsão confiável; oferecer estimativa indisponível em vez de inventá-la.
- [ ] **NX-092** Regra parametrizável para troca prematura com base em nível declarado, estimado ou eficiência.
- [ ] **NX-093** Histórico, linha do tempo de suprimentos e peças, responsáveis e custos por cliente/equipamento.
- [ ] **NX-094** Filtros avançados salváveis, colunas arrastáveis, ordenação e exportação de níveis e indicadores.
- [ ] **NX-095** Identificação de desperdício, possibilidade de remanejamento/reutilização autorizada e custos evitáveis.
- [ ] **NX-096** Configuração granular de quais suprimentos geram alerta e sugestões de troca por impressora.
- [ ] **NX-097** Preparar motor estatístico determinístico; qualquer futuro uso de IA deverá ser isolado, validado e opcional.

### 7. Estoque, movimentação e reposição

- [ ] **NX-098** Estoque da empresa, depósitos, técnicos e cada localidade do cliente, com posições de saldo separadas.
- [ ] **NX-099** Cadastro de fornecedores, nota fiscal/documento, lote, custo, validade e entrada unitária ou em massa.
- [ ] **NX-100** Transferência provedor → técnico, provedor → cliente, técnico → cliente, cliente → técnico e devolução ao provedor.
- [ ] **NX-101** Movimento transacional com origem/destino, saldo antes/depois, autorização, justificativa e trilha de auditoria.
- [ ] **NX-102** Bloqueio de saldo negativo e de consumo concorrente da mesma unidade; reservas para chamados e reposições.
- [ ] **NX-103** Inventário físico, ajuste supervisionado, perdas, baixas, devoluções e histórico contábil de estoque.
- [ ] **NX-104** Controle de estoque mínimo, crítico, segurança e sugestão de compra.
- [ ] **NX-105** Programação de reposição por intervalo, clientes, modelos, tipos e demanda prevista.
- [ ] **NX-106** Revisão da sugestão com ajuste de quantidades, item de segurança e inserção manual de itens.
- [ ] **NX-107** Lista de reposições programadas com data limite, responsável e estado de envio/recebimento.
- [ ] **NX-108** Baixa no estoque de origem ao confirmar envio e entrada no estoque da unidade ao confirmar recebimento.
- [ ] **NX-109** Confirmação de recebimento pelo provedor ou usuário cliente autorizado.
- [ ] **NX-110** Tratamento de envio parcial, recebimento parcial, extravio, divergência e cancelamento.
- [ ] **NX-111** Rastreio por movimentação, técnico, cliente, unidade e impressora.

### 8. Alertas e manutenções preventivas

- [ ] **NX-112** Alertas de falha da impressora, de comunicação impressora/agente/servidor e de manutenção preventiva.
- [ ] **NX-113** Criticidade, categoria, origem, timestamps e estados ativo, reconhecido, vinculado, resolvido e ignorado.
- [ ] **NX-114** Atraso configurável antes do alerta de desconexão, para reduzir ruído de instabilidades curtas.
- [ ] **NX-115** Exigir comunicação estável antes de encerrar alerta automático de falha de conexão.
- [ ] **NX-116** Regras por dias, páginas produzidas, nível de suprimento, fabricante, modelo, cliente e impressora.
- [ ] **NX-117** Exceções por equipamento e aplicação das regras em novos equipamentos que correspondam ao filtro.
- [ ] **NX-118** Histórico de manutenções preventivas com data realizada, peças, técnico e reinício do ciclo da regra.
- [ ] **NX-119** Configuração de alertas específicos de USB/rede, catálogo de códigos e severidades por dispositivo.
- [ ] **NX-120** Supressão contextual em dispositivos reserva, em manutenção, ou em janelas agendadas.
- [ ] **NX-121** Silenciamento, reconhecimento, ignorar, encerramento automático e explicação do motivo.
- [ ] **NX-122** Deduplicação e correlação; alerta persistente não pode abrir chamados repetidos sem política.
- [ ] **NX-123** Relacionar alerta a chamado existente ou criar chamado novo com equipamento e cliente preenchidos.
- [ ] **NX-124** Estados de alertas vinculados a chamados e regra de encerramento coerente.
- [ ] **NX-125** Notificações por e-mail/eventos com preferências por destinatário, cliente, tipo e criticidade.
- [ ] **NX-126** Histórico de ações em alertas com autor e visibilidade para auditoria.

### 9. Chamados, técnicos, atendimento e SLA

- [ ] **NX-127** Chamados abertos pelo cliente ou pelo provedor, vinculados opcionalmente a impressora(s), dispositivo(s) e alerta(s).
- [ ] **NX-128** Permitir chamado interno sem cliente/equipamento para tarefas administrativas.
- [ ] **NX-129** Tipos de chamados configuráveis com opção de visibilidade para o portal cliente.
- [ ] **NX-130** Abertura com descrição, categoria, criticidade, prioridade, anexos e registro de origem.
- [ ] **NX-131** Fila sem responsável, quadro de chamados ativos e lista de finalizados/encerrados.
- [ ] **NX-132** Estados de triagem, aguardando atribuição, atendimento pendente, em atendimento, atendimento finalizado e encerrado.
- [ ] **NX-133** Responsável técnico, equipe, transferência, reabertura e histórico de alterações.
- [ ] **NX-134** Comentários internos e comentários públicos para cliente com autorização correta.
- [ ] **NX-135** Serviços prestados catalogados, valor por serviço, peças de estoque utilizadas e custos adicionais.
- [ ] **NX-136** Destino de peça usada: cliente (estoque) ou instalação na impressora, conforme natureza do atendimento.
- [ ] **NX-137** Cálculo de valor total do serviço, desconto, pagamento confirmado e conferência antes do encerramento.
- [ ] **NX-138** Relação entre chamados e alertas e encerramento com tratamento consistente dos alertas associados.
- [ ] **NX-139** Impressão de ordem de serviço com linhas adicionais configuráveis e identidade da empresa.
- [ ] **NX-140** Política de SLA por cliente, filial e impressora, com herança e substituição explícitas.
- [ ] **NX-141** Contagem em horas corridas, horas úteis do provedor ou horas úteis do cliente, conforme a regra.
- [ ] **NX-142** Calendário de feriados, horários de trabalho, relógio do servidor, pausas, atrasos e escalonamentos.
- [ ] **NX-143** Indicadores de tempo de primeira resposta, tempo até resolução, reincidências e atendimentos por técnico.
- [ ] **NX-144** Notificações de chamado aberto, comentário, alteração, prazo próximo e SLA vencido.
- [ ] **NX-145** Relatórios de chamados com construtor de colunas, filtros avançados, visões salvas e exportação.
- [ ] **NX-146** Auditoria sobre registros, serviços, materiais, descontos, alterações e fechamento.
- [ ] **NX-147** Bloqueio ou alerta por material pendente/valor em aberto no encerramento conforme política.

### 10. Outros dispositivos de TI

- [ ] **NX-148** Cadastro independente de impressoras de ativos locados: tipo, fabricante, modelo, série, patrimônio, IP e MAC.
- [ ] **NX-149** Cadastro de categorias, fabricantes e modelos de dispositivos.
- [ ] **NX-150** Localização, cliente, departamento, notas, comentários e histórico de movimentações.
- [ ] **NX-151** Estado de manutenção/uso e filtros por status.
- [ ] **NX-152** Vínculo de dispositivo a contrato e valor fixo periódico.
- [ ] **NX-153** Chamados vinculáveis apenas a dispositivos, a impressoras ou aos dois.
- [ ] **NX-154** Entrada/retirada parcial no ciclo financeiro com cobrança integral/proporcional revisável.
- [ ] **NX-155** Listagem com filtros por cliente, colunas configuráveis e exportação Excel.

### 11. Financeiro: contratos e regras de custo

- [ ] **NX-156** Contrato com cliente, unidade prestadora do provedor, número de documento, identificação e localização de cobrança.
- [ ] **NX-157** Data de início de faturamento independente do dia 1 e período que termina no dia anterior ao próximo ciclo.
- [ ] **NX-158** Vigência determinada/indeterminada, vencimento e alertas de fim de contrato.
- [ ] **NX-159** Contrato-padrão por cliente para inclusão automática de impressoras.
- [ ] **NX-160** Tabelas de custo reutilizáveis para produção P&B, colorida e digitalizações.
- [ ] **NX-161** CPP (custo por página) parametrizável, inclusive valores zero e discriminação por tipo de produção.
- [ ] **NX-162** Franquia com quantidade, valor base e excedente, compartilhada entre impressoras/tipos ou independente.
- [ ] **NX-163** Faixas de volume com preços fixos/por página, excedentes e agrupamento compartilhado opcional.
- [ ] **NX-164** Custo fixo por impressora, por dispositivo e custos adicionais do contrato.
- [ ] **NX-165** Mensalidade mínima aplicada à produção de impressoras, com adição posterior de custos fixos/adicionais.
- [ ] **NX-166** Custos-padrão em novos equipamentos e custos individualizados por impressora/contador.
- [ ] **NX-167** Cálculo proporcional ou integral para equipamentos parcialmente presentes no período, com confirmação quando aplicável.
- [ ] **NX-168** Reajuste imediato ou programado, preview comparativo, histórico de valores anteriores e posteriores.
- [ ] **NX-169** Indicação dos reajustes no relatório de fechamento e aviso de quais períodos abertos sofrerão alterações.
- [ ] **NX-170** Consolidação de produção sem dupla cobrança em contadores sobrepostos.
- [ ] **NX-171** Datas, timezone, regras monetárias com decimal exato e versão de cálculo.
- [ ] **NX-172** Auditoria de alterações contratuais e impedimento de edição retroativa de congelados.

### 12. Financeiro: fechamento e faturamento

- [ ] **NX-173** Fechamentos em linha do tempo com totais, status e quantidade por ciclo.
- [ ] **NX-174** Estados: em processamento, processado e congelado, além de lista independente de pendências.
- [ ] **NX-175** Congelamento automático condicionado à elegibilidade e sequência de fechamentos anteriores.
- [ ] **NX-176** Pendência por equipamento sem contrato, contrato sem custos ou leitura manual ausente.
- [ ] **NX-177** Pendência por equipamento offline além da tolerância em dias úteis e passagem parcial pelo cliente.
- [ ] **NX-178** Pendência por dispositivo movido no ciclo, com decisão registrada de cobrança total/proporcional.
- [ ] **NX-179** Prévia de produção e custo por impressora, dispositivo e custo adicional.
- [ ] **NX-180** Detalhamento de custo, franquia, excedentes, mínimos e descontos.
- [ ] **NX-181** Acréscimos, descontos e observações no fechamento com motivo/autor.
- [ ] **NX-182** Congelamento antecipado: absorver dias restantes no próximo ciclo ou criar ciclo complementar.
- [ ] **NX-183** Snapshot imutável do cálculo e das leituras, com número do documento e versão da regra.
- [ ] **NX-184** Descongelamento restrito ao mais recente e dentro de política definida, com auditoria e recálculo controlado.
- [ ] **NX-185** Bloqueio de edição de leituras ou valores usados em fechamento congelado.
- [ ] **NX-186** Personalização de título, campos, colunas, rodapé e detalhamento por linha no documento.
- [ ] **NX-187** Exportação PDF/Excel/CSV, impressão e envio automático de fechamento congelado.
- [ ] **NX-188** Configuração de destinatários por contrato e mecanismos para evitar e-mails duplicados.
- [ ] **NX-189** Dashboard financeiro com contratos por vencer, pendências, receita, custos e comparativo histórico.
- [ ] **NX-190** Motor de apuração executável por lote, idempotente, com transações, filas e testes de arredondamento.
- [ ] **NX-191** Conciliação com ERP e emissão documental/fiscal apenas quando integração correspondente estiver habilitada.

### 13. Relatórios, filtros, gráficos e painéis

- [ ] **NX-192** Dashboard com clientes, impressoras, alertas ativos, trocas, chamados, contratos, produção e ranking de clientes.
- [ ] **NX-193** Indicadores do parque online/offline/sem coleta, distribuição por fabricante/modelo e comparativo por período.
- [ ] **NX-194** Produção diária/mensal e ranking de impressoras mais usadas nos últimos 30 dias.
- [ ] **NX-195** Relatório impressões/cópias por impressora com separação por contrato, unidade e departamento.
- [ ] **NX-196** Relatório de digitalizações por impressora e totais por cliente.
- [ ] **NX-197** Históricos de nível de suprimento, troca/eficiência, estoque, reposições e custo.
- [ ] **NX-198** Relatório de chamados, SLA, técnico, motivos, peças aplicadas e valores de atendimento.
- [ ] **NX-199** Relatórios de contrato/fechamento e pendências de cobrança.
- [ ] **NX-200** Filtros avançados com condições combinadas, colunas customizadas, ordenação e visões salvas.
- [ ] **NX-201** Exportação confiável em CSV, Excel e PDF com formatos brasileiros e data/hora da última leitura.
- [ ] **NX-202** Agendamento de relatórios por e-mail, gráficos opcionais e agrupamento por unidade/departamento.
- [ ] **NX-203** Resumo por e-mail dos fechamentos quando iniciado novo ciclo.
- [ ] **NX-204** Relatórios rastreáveis: critérios, data de geração, timezone, autor e versão dos dados.
- [ ] **NX-205** Acessibilidade de gráficos com alternativa tabular.

### 14. Notificações e comunicação

- [ ] **NX-206** Notificações in-app com histórico, não lidas, preferências e agrupamento por prioridade.
- [ ] **NX-207** Gatilhos por chamados, comentários, prazo SLA, alterações e novos alertas.
- [ ] **NX-208** Gatilhos por vencimento de contrato, queda de estoque e risco de ruptura.
- [ ] **NX-209** Gatilhos por nível informado/estimado de suprimento e data prevista de troca.
- [ ] **NX-210** Gatilhos por troca pendente, troca prematura e novas impressoras descobertas.
- [ ] **NX-211** Regras por cliente/unidade, evento, criticidade, usuário/grupo e canal.
- [ ] **NX-212** Envio por e-mail com templates próprios, reputação de envio, retry e rastreamento de falhas.
- [ ] **NX-213** Supressão de duplicatas, digest, horário silencioso e prevenção de tempestade de alertas.
- [ ] **NX-214** Fila de notificações e registro de entrega, com painel de falhas.

### 15. API, webhooks e integrações

- [ ] **NX-215** API REST versionada para clientes, filiais, impressoras, contadores, suprimentos, estoques, chamados, alertas, contratos e fechamentos.
- [ ] **NX-216** Leitura e escrita autorizada por escopos; publicar OpenAPI e exemplos de integração.
- [ ] **NX-217** Tokens de integração com escopo, hash no banco, prazo/rotação/revogação e histórico de uso.
- [ ] **NX-218** Paginação, filtros, ordenação, pesquisa e limites configuráveis com mensagens consistentes.
- [ ] **NX-219** Idempotency-Key para operações de escrita e importações, evitando duplicação.
- [ ] **NX-220** Webhooks assinados para chamadas/eventos, retries exponenciais, replay e log de entregas.
- [ ] **NX-221** Mapeamento de códigos de cliente, produto, unidade, contrato e centro de custo com sistemas externos.
- [ ] **NX-222** Exportação/consumo por ERP, serviços web e BI, preservando tenant e autorização.
- [ ] **NX-223** Importação bilateral de dados somente com governança por registro e definição de sistema de origem.
- [ ] **NX-224** Autorização explícita para integração com outras plataformas MPS, sem acesso ou cópia indevida de APIs privadas.
- [ ] **NX-225** Versão de esquema, documentação de erros e política de descontinuação de endpoints.

### 16. Operação da plataforma, SaaS e cobrança do provedor

- [ ] **NX-226** Cadastro de provedores, isolamento dos ambientes e parâmetros de implantação self-hosted/SaaS.
- [ ] **NX-227** Planos/módulos ativáveis, licenças de impressoras ativas e histórico de uso, se Nexa for comercializado.
- [ ] **NX-228** Faturamento da assinatura do provedor separado dos fechamentos cobrados dos clientes do provedor.
- [ ] **NX-229** Central de faturas, status em dia/em atraso, documentos e permissões do financeiro, se houver serviço de assinatura.
- [ ] **NX-230** Política de cobrança, suspensão e reativação sem perda indevida de dados, caso o produto seja SaaS pago.
- [ ] **NX-231** Logs, métricas, tracing, saúde dos serviços, filas, jobs, backups e alertas de falhas operacionais.
- [ ] **NX-232** Atualização, migração de schema, restauração e rollback testados.
- [ ] **NX-233** Ambientes de desenvolvimento, homologação e produção segregados.
- [ ] **NX-234** Administração de idiomas PT-BR e internacionalização futura, formatos de moeda e fuso.
- [ ] **NX-235** Política de suporte, documentação de instalação de agente e catálogo de modelos testados.



## Auditoria adicional de paridade documentada — 08/10/2026

Os itens seguintes complementam os 235 requisitos iniciais. Esta é **cobertura documental**, não execução da aplicação. As fontes públicas descrevem comportamentos do produto de referência; o Nexa tem implementação, interface, código e identidade próprios. Todas as caixas permanecem pendentes.

### Interface e configurações

- [ ] **NX-236** Topbar com ajuda, novidades, notificações, perfil, recuperação de acesso, política, downloads do coletor e saída. [Fonte oficial](https://help.printwayy.com/visao-geral/)
- [ ] **NX-237** Listas com ações habilitadas pela seleção, pesquisa em todas as colunas, inativos ocultáveis, personalização e Excel. [Fonte oficial](https://help.printwayy.com/visao-geral/)
- [ ] **NX-238** Atalhos clicáveis para entidade associada (ex.: impressora para contrato) com autorização e preservação de contexto. [Fonte oficial](https://help.printwayy.com/visao-geral/)
- [ ] **NX-239** Tooltip de orientação em campo complexo e gráficos com controle para ocultar/mostrar cada série. [Fonte oficial](https://help.printwayy.com/visao-geral/)
- [ ] **NX-240** Visões salvas e temporárias dos filtros avançados, com ordenação customizada de colunas e rearranjo por arraste. [Fonte oficial](https://help.printwayy.com/visao-geral/)
- [ ] **NX-241** Mostrar a idade das informações nas telas; atualização periódica não pode ser descrita como instantânea. [Fonte oficial](https://help.printwayy.com/visao-geral/)
- [ ] **NX-242** Menus e botões baseados no par módulo contratado × autorização efetiva de usuário, incluindo invalidação de cache. [Fonte oficial](https://help.printwayy.com/habilitar-desabilitar-modulos/)

### Identidade, empresa e clientes

- [ ] **NX-243** Configuração do logotipo com prévia, dimensões/tipos de imagem validados e aplicação opcional aos relatórios. [Fonte oficial](https://help.printwayy.com/configuracoes-empresa/)
- [ ] **NX-244** Cabeçalho dos relatórios com dados fiscais/configuráveis do provedor e possibilidade de omiti-los. [Fonte oficial](https://help.printwayy.com/configuracoes-empresa/)
- [ ] **NX-245** Unidades do provedor associáveis aos contratos com CNPJs diferentes e histórico da unidade emissora. [Fonte oficial](https://help.printwayy.com/configuracoes-empresa/)
- [ ] **NX-246** Departamentos internos do provedor para impressoras devolvidas ao estoque, separados dos departamentos do cliente. [Fonte oficial](https://help.printwayy.com/configuracoes-empresa/)
- [ ] **NX-247** Horários comerciais do provedor utilizados em alertas e SLA, incluindo feriados e fuso. [Fonte oficial](https://help.printwayy.com/configuracoes-empresa/)
- [ ] **NX-248** Manter impressora desativada no contrato até desvinculação manual conforme parâmetro, sem faturar silenciosamente. [Fonte oficial](https://help.printwayy.com/configuracoes-empresa/)
- [ ] **NX-249** Controle global para permitir alteração de número de documento ainda aberto apenas a usuários financeiros autorizados. [Fonte oficial](https://help.printwayy.com/configuracoes-empresa/)

### Collector e comunicação

- [ ] **NX-250** Aplicação local administrativa com Impressoras, Ferramentas, Estado dos Serviços e Configurações. [Fonte oficial](https://help.printwayy.com/monitorando-impressoras/)
- [ ] **NX-251** Teste de IP e credenciais SNMP/HTTP mostrando identidade/contadores obtidos antes de ativar monitoramento. [Fonte oficial](https://help.printwayy.com/duvidas-frequentes/)
- [ ] **NX-252** Descoberta manual de sub-rede local/remota por broadcast ou intervalo com seleção múltipla e resumo de falhas. [Fonte oficial](https://help.printwayy.com/monitorando-impressoras/)
- [ ] **NX-253** Busca automática por intervalos autorizados com frequência de descoberta configurável entre 6 e 168 horas. [Fonte oficial](https://help.printwayy.com/monitorando-remotamente/)
- [ ] **NX-254** Cadastro remoto de impressora por IP vinculado ao ponto de instalação com aguardando descoberta e validação. [Fonte oficial](https://help.printwayy.com/monitorando-remotamente/)
- [ ] **NX-255** Estados e telemetria separados do coletor, upload de dados, atualização e supervisor. [Fonte oficial](https://help.printwayy.com/monitoramento-conexoes/)
- [ ] **NX-256** Coleta offline com pacotes cifrados e compactados na origem, integridade e eliminação após confirmação no servidor. [Fonte oficial](https://help.printwayy.com/duvidas-frequentes/)
- [ ] **NX-257** Monitoramento de conexão com detalhes por ponto, número de impressoras, serviços, erros e sugestões de diagnóstico. [Fonte oficial](https://help.printwayy.com/monitoramento-conexoes/)
- [ ] **NX-258** Captura USB ponto a ponto: host obrigatoriamente presente, correção de identificação/porta/driver e teste real por modelo. [Fonte oficial](https://help.printwayy.com/monitorando-impressoras/)
- [ ] **NX-259** Capacidades separadas de rede e USB; não inferir leituras coloridas/níveis quando só houver contador geral. [Fonte oficial](https://help.printwayy.com/duvidas-frequentes/)
- [ ] **NX-260** Cadência dos diferentes serviços configurável: documentação pública diverge entre 30 e 60 minutos; exibir última amostra real. [Fonte oficial](https://help.printwayy.com/monitorando-impressoras/)
- [ ] **NX-261** Diagnósticos para firewall, DNS, proxy, credenciais, IP alterado, troca de placa, antivírus e serviço parado. [Fonte oficial](https://help.printwayy.com/monitoramento-conexoes/)
- [ ] **NX-262** Transportes HTTPS e proxy com autenticação; alteração posterior de configuração sem reinstalar. [Fonte oficial](https://help.printwayy.com/proxy/)
- [ ] **NX-263** Transporte alternativo seguro por SMTP/POP3 com teste de comunicação e validação de origem quando habilitado. [Fonte oficial](https://help.printwayy.com/printwayy-client-via-email/)
- [ ] **NX-264** Revogação de ponto instalado permanentemente offline com reconciliação dos dispositivos do ponto. [Fonte oficial](https://help.printwayy.com/monitoramento-conexoes/)
- [ ] **NX-265** Não capturar spool, conteúdo ou imagem de documentos; somente metadados necessários para monitoramento. [Fonte oficial](https://help.printwayy.com/duvidas-frequentes/)
- [ ] **NX-266** Eventos e alarmes próprios para falha do serviço de atualização, distintos de agente offline. [Fonte oficial](https://help.printwayy.com/monitoramento-conexoes/)

### Contadores, equipamentos e compatibilidade

- [ ] **NX-267** Edição manual somente do último contador e durante janela configurável equivalente à referência de 30 dias. [Fonte oficial](https://help.printwayy.com/duvidas-frequentes/)
- [ ] **NX-268** Vedação de edição de contadores associados a fechamento congelado, exceto processo formal de descongelamento. [Fonte oficial](https://help.printwayy.com/duvidas-frequentes/)
- [ ] **NX-269** Preservar segmentos de medição antes/depois de reset de contador ou formatter sem duplicar produção. [Fonte oficial](https://help.printwayy.com/duvidas-frequentes/)
- [ ] **NX-270** Modelo de contador com Geral, Geral P&B, Geral Cor Total, Cor Única e 2 Cores, com fórmula por capacidade de modelo. [Fonte oficial](https://help.printwayy.com/duvidas-frequentes/)
- [ ] **NX-271** Modelo não homologado deve permitir cadastro e contador manual mas declarar ausência de telemetria não testada. [Fonte oficial](https://help.printwayy.com/duvidas-frequentes/)
- [ ] **NX-272** Série obtida por USB precisa de validação humana quando driver/SO reportar informação possivelmente persistida. [Fonte oficial](https://help.printwayy.com/duvidas-frequentes/)
- [ ] **NX-273** Painel da impressora deve mostrar último horário de leitura, condição, contadores e suprimentos com precisão por modelo. [Fonte oficial](https://help.printwayy.com/duvidas-frequentes/)

### Suprimentos, médias e estoque

- [ ] **NX-274** Não calcular data de próxima troca nem reposição preditiva sem duas trocas válidas confirmadas por equipamento/suprimento. [Fonte oficial](https://help.printwayy.com/reposicao-suprimentos/)
- [ ] **NX-275** Sugestão de troca documenta motivo exato (nível, capacidade, número de série ou informe por usuário cliente/colaborador). [Fonte oficial](https://help.printwayy.com/trocas-suprimento/)
- [ ] **NX-276** Troca manual deve reconciliar sugestão pendente da mesma ocorrência e evitar dupla baixa de estoque. [Fonte oficial](https://help.printwayy.com/trocas-suprimento/)
- [ ] **NX-277** Automação de troca limitada ao estoque da localização, compatibilidade e SKU previamente usado sem ambiguidade. [Fonte oficial](https://help.printwayy.com/configuracoes-suprimentos-estoque-trocas/)
- [ ] **NX-278** Impedir auto-confirmação se houver pendências de troca mais antigas em aberto para a impressora. [Fonte oficial](https://help.printwayy.com/configuracoes-suprimentos-estoque-trocas/)
- [ ] **NX-279** Trocas confirmadas podem ser excluídas com auditoria ou desconsideradas das médias, permitindo reabilitar média sem perda histórica. [Fonte oficial](https://help.printwayy.com/trocas-suprimento/)
- [ ] **NX-280** Trocas prematuras visualizáveis em recortes 30/60/90 dias com data e nível de entrada/saída. [Fonte oficial](https://help.printwayy.com/trocas-suprimento/)
- [ ] **NX-281** Rendimento calculado para SKU e impressora usando páginas entre trocas e rendimento nominal. [Fonte oficial](https://help.printwayy.com/indicadores-suprimentos/)
- [ ] **NX-282** Cobertura calculada como estimativa inferida de hipótese nominal (ex.: 5% padrão), nunca telemetria medida. [Fonte oficial](https://help.printwayy.com/indicadores-suprimentos/)
- [ ] **NX-283** Validação manual/algorítmica de trocas espúrias; IA opcional futura deve ser explicável, aferível e sem dependência obrigatória. [Fonte oficial](https://help.printwayy.com/indicadores-suprimentos/)
- [ ] **NX-284** Permitir cadastrar tipos, marcas, fornecedores e compatibilidades; bloquear exclusão de cadastro com histórico dependente. [Fonte oficial](https://help.printwayy.com/estoque-suprimentos/)
- [ ] **NX-285** SKU externo, cor, rendimento por compatibilidade, preço de venda e fornecedor associados a estoque e atendimentos. [Fonte oficial](https://help.printwayy.com/estoque-suprimentos/)
- [ ] **NX-286** Entrada múltipla para estoque do provedor ou cliente, com nota fiscal e origem documental. [Fonte oficial](https://help.printwayy.com/estoque-suprimentos/)
- [ ] **NX-287** Definir explicitamente compatibilidade ao criar SKU; vazio não pode significar todos os modelos inadvertidamente. [Fonte oficial](https://help.printwayy.com/estoque-suprimentos/)
- [ ] **NX-288** Permitir avaliação de troca prematura por nível informado, estimativa ou eficiência e limiar por tipo. [Fonte oficial](https://help.printwayy.com/configuracoes-suprimentos-estoque-trocas/)

### Alertas e preventivas

- [ ] **NX-289** Falha de comunicação só gera alerta após carência configurada, com recuperação por três leituras estáveis como referência. [Fonte oficial](https://help.printwayy.com/duvidas-frequentes/)
- [ ] **NX-290** Erro do equipamento pode ser ignorado e reaparecer; encerramento automático após janela configurada (referência: 4h). [Fonte oficial](https://help.printwayy.com/duvidas-frequentes/)
- [ ] **NX-291** Ao encerrar chamado vinculado, preventiva encerrada e alerta de hardware ignorado/resolvido conforme tipo. [Fonte oficial](https://help.printwayy.com/alertas/)
- [ ] **NX-292** Regras com múltiplos gatilhos emitem eventos rastreáveis individualmente para dias, páginas e níveis. [Fonte oficial](https://help.printwayy.com/configuracoes-alertas/)
- [ ] **NX-293** Alertas preventivos com reinício em data real da manutenção, impedindo mudança retroativa injustificada de política. [Fonte oficial](https://help.printwayy.com/configuracoes-alertas/)
- [ ] **NX-294** Regras de preventiva aplicam-se também a novas impressoras incluídas posteriormente no conjunto de filtros. [Fonte oficial](https://help.printwayy.com/configuracoes-alertas/)
- [ ] **NX-295** Catálogos de erros de rede/USB com criticidade configurável e desabilitação explícita de ocorrência inútil. [Fonte oficial](https://help.printwayy.com/alertas/)

### Chamados e regras de atendimento

- [ ] **NX-296** Abertura pelo equipamento ou lista geral; cliente pode selecionar impressora e/ou dispositivo e colaborador abrir tarefa interna. [Fonte oficial](https://help.printwayy.com/como-abrir-um-chamado/)
- [ ] **NX-297** Fila sem responsável e quadro ativo com três estados: atendimento pendente, em atendimento e atendimento finalizado. [Fonte oficial](https://help.printwayy.com/atendendo-chamados/)
- [ ] **NX-298** Item usado em chamado exige origem (provedor/técnico), destino (cliente/impressora) e opção de cobrança. [Fonte oficial](https://help.printwayy.com/atendendo-chamados/)
- [ ] **NX-299** Encerramento exige confirmação dos materiais usados, eventuais pagamentos, descontos e custos adicionais. [Fonte oficial](https://help.printwayy.com/atendendo-chamados/)
- [ ] **NX-300** Impressão da ordem de serviço com quantidade configurável de linhas em branco para escrita em campo. [Fonte oficial](https://help.printwayy.com/atendendo-chamados/)
- [ ] **NX-301** Tipos de chamado e serviços associados não podem ser excluídos destrutivamente enquanto existir histórico. [Fonte oficial](https://help.printwayy.com/configuracoes-de-chamados/)

### Contratos e fechamentos

- [ ] **NX-302** Contrato-padrão único por cliente sob concorrência, com vínculo automático apenas onde autorizado. [Fonte oficial](https://help.printwayy.com/adicionando-contrato/)
- [ ] **NX-303** Local fiscal do fechamento limitada às localizações habilitadas e CNPJs aplicáveis ao contrato. [Fonte oficial](https://help.printwayy.com/adicionando-contrato/)
- [ ] **NX-304** Impressoras do cliente com propriedade distinta não podem gerar pendência automática indevida. [Fonte oficial](https://help.printwayy.com/adicionando-contrato/)
- [ ] **NX-305** Fechamento automático depende de ausência de pendências e de congelamento de todos os ciclos anteriores. [Fonte oficial](https://help.printwayy.com/fechamentos-mensais/)
- [ ] **NX-306** Permitir congelamento manual mesmo com pendência apenas mediante permissão especial e justificativa. [Fonte oficial](https://help.printwayy.com/fechamentos-mensais/)
- [ ] **NX-307** Descongelar somente o fechamento congelado mais recente por cliente, dentro de janela equivalente à referência 30 dias. [Fonte oficial](https://help.printwayy.com/duvidas-frequentes/)
- [ ] **NX-308** Fechamento congelado preserva snapshot de custos, contadores e regras mesmo após reajuste de contrato. [Fonte oficial](https://help.printwayy.com/duvidas-frequentes/)
- [ ] **NX-309** Documentos personalizados: título, colunas, texto adicional, dados fiscais e logotipo. [Fonte oficial](https://help.printwayy.com/configuracao-relatorio-fechamento/)
- [ ] **NX-310** Envio automático do PDF/relatório apenas no primeiro congelamento, com deduplicação em reprocessamentos. [Fonte oficial](https://help.printwayy.com/configuracao-relatorio-fechamento/)
- [ ] **NX-311** Cobrança parcial ou integral selecionável e auditada para dispositivos e impressoras em passagem parcial. [Fonte oficial](https://help.printwayy.com/fechamentos-mensais/)
- [ ] **NX-312** Reajustes com mês/ano, prévia, histórico, percentual e impacto explícito nos fechamentos ainda abertos. [Fonte oficial](https://help.printwayy.com/aplicando-um-reajuste-no-contrato/)
- [ ] **NX-313** Documento de cobrança numerado com permissão para edição somente antes de congelamento. [Fonte oficial](https://help.printwayy.com/fechamentos-mensais/)
- [ ] **NX-314** Receita provisória de ciclos em andamento separada de faturamento congelado. [Fonte oficial](https://help.printwayy.com/fechamentos-mensais/)

### Relatórios, integrações e operação

- [ ] **NX-315** Relatórios agendados para digitalizações e impressões/cópias usando janela de 30 dias e gráficos opcionais. [Fonte oficial](https://help.printwayy.com/relatorio-email/)
- [ ] **NX-316** Resumo do ciclo financeiro por e-mail quando um novo fechamento começa, com destinatários por cliente. [Fonte oficial](https://help.printwayy.com/relatorio-email/)
- [ ] **NX-317** Notificar níveis lidos ou estimados, trocas futuras, troca pendente/prematura, estoque baixo no cliente e no provedor. [Fonte oficial](https://help.printwayy.com/notificar-eventos/)
- [ ] **NX-318** Token de integração com permissão de administrador, revogação e confirmação, escopos e auditoria. [Fonte oficial](https://help.printwayy.com/integracao/)
- [ ] **NX-319** API pública versionada com read-only em domínios e entrada autorizada de clientes/estoques. [Fonte oficial](https://help.printwayy.com/integracao/)
- [ ] **NX-320** Paginação e ordenação estável; documentar diferenças entre API Nexa e referência que usa até 100 itens e Skip. [Fonte oficial](https://help.printwayy.com/duvidas-frequentes/)
- [ ] **NX-321** Relatório de leituras explicita data de coleta e nunca apresenta consumo zero para dados desatualizados. [Fonte oficial](https://help.printwayy.com/integracao/)
- [ ] **NX-322** Caso Nexa tenha plano SaaS, tarifação por impressora/módulo e piso, sem cobrança duplicada quando muda de cliente. [Fonte oficial](https://help.printwayy.com/cobranca-printwayy/)
- [ ] **NX-323** Central de assinatura opcional com status, faturas, boleto/Pix via parceiro autorizado, lembretes e reativação. [Fonte oficial](https://help.printwayy.com/cobranca-printwayy/)
- [ ] **NX-324** Catálogo de incidentes de campo e checklist de homologação de modelos/firmwares e protocolos. [Fonte oficial](https://help.printwayy.com/duvidas-frequentes/)


### Pontos com divergência ou sem comprovação

| Tema | Conclusão da revisão |
| --- | --- |
| Frequência de coleta | A FAQ menciona 30 min e outro tutorial, 60 min; configurar e medir em vez de prometer intervalo universal |
| Atualização do painel | Página de visão geral menciona atualização periódica; nunca alegar streaming em tempo real só pelo layout |
| Coletores/USB | A publicação comercial apresenta capacidades distintas para USB e rede; requer matriz real por fabricante/modelo |
| IA na validação | A ajuda declara IA na validação de trocas, mas não divulga algoritmo/dataset; recurso Nexa só pode ser validado por resultados mensuráveis |
| SLA/alertas | Janelas e critérios podem mudar por configuração e por versão; simular e testar todos os estados |
| Interface autenticada | Não auditada com credenciais autorizadas; modais, perfis, detalhes e combinações de licença continuam pendentes |
| Preço/planos do concorrente | Não são regra obrigatória para o Nexa; separar contrato com clientes e eventual cobrança SaaS |
| Sistema operacional | Menções públicas a Windows obsoleto não devem ser replicadas como suporte moderno ou requisito de segurança |
| APIs do concorrente | API documentada é uma referência funcional, não acesso autorizado a endpoints privados ou cópia de contratos internos |

### Critério de 100% do escopo

Cobertura real só pode ser afirmada com **inventário versionado e congelado**, acesso autorizado aos fluxos que se deseja comparar, prova de funcionamento de cada requisito, execução de testes e homologação em equipamentos reais. O índice **NX** cobre o que foi encontrado na documentação pública e também propostas próprias, não garante uma lista exaustiva de recursos não publicados.

## Regras de negócio críticas (não podem virar simplificações)

### Agentes e impressoras

- O estado **desconhecido** não é sinônimo de **zero páginas**, **sem toner** ou **offline**.
- Heartbeat do agente, última coleta da impressora e última entrega no servidor são relógios distintos.
- Conexão HTTP/SNMP/IPP falha, troca de IP, impressora substituída e conflito de número de série possuem diagnósticos diferentes.
- Impressora reserva pode ficar desligada sem produzir falsos incidentes.
- Uma mudança de contador pode representar reset, troca de peça, overflow ou erro de leitura; deve gerar análise/segmentação, não valor negativo.
- Coletas recebidas fora de ordem nunca substituem uma leitura mais nova sem critério de reconciliação.
- USB exige suporte real no host; nenhuma promessa genérica de funcionar com qualquer modelo.
- Configurações potencialmente destrutivas ou mudanças remotas precisam de permissão, histórico e confirmação.

### Suprimentos e logística

- Uma possível troca é uma **hipótese**, não uma troca confirmada. Mudança de nível isolada não prova substituição.
- Confirmar troca consome um item compatível em um local de estoque válido; não pode surgir estoque negativo.
- O material enviado ainda não está necessariamente recebido; transporte e recebimento são eventos distintos.
- Saldo em técnico, cliente e provedor são posições independentes.
- Eficiência, cobertura e previsão precisam indicar o período, a amostra e a base do cálculo.
- Troca falsa não pode contaminar estatística; permitir excluir da média mantendo auditoria.
- Cliente só movimenta/recebe estoque conforme autorização expressa.

### Contratos e fechamento

- O período é definido pelo **dia de ciclo do contrato**, não necessariamente pelo mês-calendário.
- Configurações podem diferir por impressora e tipo de contador (P&B, cor, digitalização).
- Franquia compartilhada considera os agrupamentos previstos, evitando múltipla aplicação indevida.
- Mensalidade mínima incide sobre a parcela prevista no contrato; custos adicionais são somados conforme regra.
- Equipamento incluído ou retirado no meio do ciclo precisa de tratamento parcial configurável e auditável.
- Pendências de comunicação, custos e leituras precisam bloquear o congelamento automático conforme política.
- Congelamento grava cópia imutável dos contadores, custos, alíquotas e ajustes usados no documento.
- Descongelamento, quando permitido, é ação excepcional com autorização e trilha de reprocessamento.
- O motor financeiro opera com decimal exato e arredondamento explícito: nunca com valores financeiros em float.
- Reajustes programados devem informar impacto em ciclos abertos antes de aplicar.

### Chamados, alertas e SLA

- Atribuição, triagem, execução, conclusão técnica e encerramento administrativo não são a mesma etapa.
- Comentário interno jamais pode aparecer no portal do cliente.
- Prazo de atendimento usa o calendário correto (corrido, expediente do provedor ou do cliente).
- Chamado encerrado com peça pendente ou valor a pagar precisa de regra explícita.
- Alerta técnico resolvido pela impressora pode ser encerrado automaticamente; preventivo precisa da data efetiva se a configuração exigir.
- Falhas intermitentes não devem criar centenas de alertas ou chamados idênticos.

## Telas e navegação planejadas

    /login                          Entrar, recuperar acesso
    /dashboard                      Visão operacional executiva
    /clientes                       Lista, cadastro e detalhes
    /clientes/:id/unidades          Filiais, departamentos, centros de custo
    /parque                         Parque completo
    /parque/por-cliente             Impressoras por cliente
    /parque/novas                   Descobertas aguardando adoção
    /parque/duplicadas              Monitoramentos conflitantes
    /parque/:id                      Detalhes, gráficos, suprimentos, histórico
    /coleta/agentes                 Pontos de coleta, versões e status
    /coleta/agentes/:id             Diagnóstico e configurações
    /dispositivos                   Ativos não impressoras
    /suprimentos/niveis             Níveis e filtros avançados
    /suprimentos/indicadores        Rendimento, cobertura e eficiência
    /suprimentos/trocas/proximas    Previsão de troca
    /suprimentos/trocas/pendentes   Revisão de trocas
    /suprimentos/trocas/confirmadas Histórico confirmado
    /suprimentos/trocas/excluidas   Trocas rejeitadas
    /suprimentos/trocas/prematuras  Desperdício/possível remanejamento
    /estoque                        Catálogo e saldos por localização
    /estoque/entradas               Entradas/fornecedores
    /estoque/movimentacoes          Transferências/devoluções
    /reposicoes                     Programações, envio e recebimento
    /alertas/ativos                 Falhas e incidentes abertos
    /alertas/chamados               Alertas vinculados a chamados
    /alertas/encerrados             Histórico de alertas
    /chamados/sem-responsavel       Fila de triagem
    /chamados/ativos                Kanban e lista por técnico/SLA
    /chamados/encerrados            Histórico, custos e evidências
    /financeiro/contratos           Custos, vigência, reajustes
    /financeiro/fechamentos         Linha do tempo, pendências e congelamento
    /financeiro/fechamentos/:id     Detalhamento/ajustes/exportação
    /relatorios                     Produção, digitalização, estoque, chamados etc.
    /relatorios/agendamentos        Envios periódicos
    /configuracoes/empresa          Identidade, unidades e expediente
    /configuracoes/usuarios         Convites, usuários e permissões
    /configuracoes/modulos          Módulos por cliente
    /configuracoes/alertas          Eventos/preventivas
    /configuracoes/chamados         Tipos, serviços e SLA
    /configuracoes/suprimentos      Tipos, marcas, compatibilidades
    /configuracoes/integracoes      Tokens, webhooks e mapeamentos
    /configuracoes/notificacoes     Eventos e destinatários
    /cliente                        Portal do cliente
    /cliente/chamados               Chamados autorizados
    /cliente/suprimentos            Recebimento de materiais
    /plataforma                     Administração SaaS (somente se aplicável)

O menu deve mostrar apenas recursos liberados **por módulo e permissão**. Todas as tabelas precisam de busca, ordenação, filtros, colunas customizáveis, estado de carregamento, vazio e erro; ações sensíveis exigem confirmação. Cada detalhe exige histórico rastreável.

## Arquitetura proposta

**Estratégia:** monólito modular para regras de negócio + ingestão assíncrona + **coletor local separado**. Não criar microsserviços por padrão antes da necessidade operacional.

    Impressoras (SNMP/HTTP/IPP/USB)
                    |
               Nexa Collector
        (serviço local, buffer offline)
                    |
            HTTPS + autenticação mútua
                    |
           API de ingestão / filas
                    |
              Validação / eventos
                    |
        Laravel API (módulos isolados)
             /       |        \
      PostgreSQL   Redis     Object Storage
             \       |        /
               API versionada
                    |
        React + TypeScript (portal)

| Camada | Escolha proposta | Responsabilidade |
| --- | --- | --- |
| Backend | PHP 8.3+ / Laravel 13+ | API, autorização, serviços de domínio, eventos e integração |
| Frontend | React 18+ / TypeScript strict / Vite ou Next.js | Painéis, formulários, relatórios e portal responsivo |
| Coletor | Rust | Serviço local, SNMP, descoberta, buffer, instalação e diagnóstico |
| Banco | PostgreSQL | Dados transacionais, histórico, integridade e fechamento |
| Filas/cache | Redis + workers | Ingestão, alertas, cálculos, e-mail, exportações |
| Armazenamento | S3 compatível | Documentos, anexos e relatórios |
| Infra | Docker, proxy HTTPS, CI, observabilidade | Ambientes reproduzíveis e operação |
| Qualidade | Pest/PHPUnit, Vitest, Playwright, testes de integração Rust | Contratos e regressão |

A escolha definitiva de versões, bibliotecas, provedor de hospedagem e estratégia de implantação será registrada em ADRs antes da implementação. Serviços do collector precisam poder operar sem depender de uma janela gráfica aberta. **Vercel pode hospedar o frontend**, mas a coleta local, jobs persistentes e processamento assíncrono exigem serviços compatíveis com execução contínua.

### Fronteiras de domínio

- **Identity & Tenancy:** usuários, permissões, provedores e escopo.
- **Customers & Fleet:** clientes, locais, ativos, impressoras, passagens e homologação.
- **Collector & Telemetry:** agentes, diagnósticos, coleta, amostras e normalização.
- **Supplies & Inventory:** catálogo, compatibilidade, estoques, trocas, previsões e reposições.
- **Incidents & Helpdesk:** alertas, manutenção, chamados, técnicos e SLA.
- **Contracts & Billing:** regras de preço, vigências, reajustes e fechamentos.
- **Reporting & Integrations:** relatórios, agendamentos, notificações, API e webhooks.
- **Platform:** parâmetros, licenciamento e cobrança de assinatura (se comercializada).

### Modelo de dados (entidades iniciais)

\`tenants\`, \`provider_units\`, \`users\`, \`roles\`, \`permissions\`, \`user_scopes\`, \`customers\`, \`customer_locations\`, \`departments\`, \`cost_centers\`, \`collector_sites\`, \`collector_agents\`, \`agent_health_events\`, \`device_models\`, \`device_capabilities\`, \`printer_assets\`, \`printer_assignments\`, \`printer_monitorings\`, \`printer_readings\`, \`meter_definitions\`, \`meter_mappings\`, \`telemetry_events\`, \`supplies\`, \`supply_compatibilities\`, \`supply_samples\`, \`supply_changes\`, \`inventory_locations\`, \`stock_ledger\`, \`inventory_reservations\`, \`replenishments\`, \`replenishment_lines\`, \`alerts\`, \`maintenance_rules\`, \`maintenance_events\`, \`tickets\`, \`ticket_comments\`, \`ticket_services\`, \`ticket_materials\`, \`sla_policies\`, \`contracts\`, \`contract_rates\`, \`contract_assignments\`, \`contract_adjustments\`, \`contract_revisions\`, \`closing_periods\`, \`closing_snapshots\`, \`closing_lines\`, \`closing_issues\`, \`saved_reports\`, \`scheduled_reports\`, \`notification_rules\`, \`notification_deliveries\`, \`integration_tokens\`, \`webhook_deliveries\`, \`audit_events\` e \`attachments\`.

Todos os registros de negócio devem ser associados ao tenant e/ou a chaves externas com constraints adequadas. Priorizar índices compostos nas leituras por \`(tenant_id, printer_id, collected_at)\`, filas por status/data e chamado por cliente/status/prazo. Considerar particionamento temporal para telemetria volumosa. **Controle de estoque via ledger**, não apenas campo \`quantity\` alterado sem trilha.

### Contratos internos de dados

- Todos os endpoints de leitura e escrita com \`tenant\` derivado da sessão/token, **nunca** aceito cegamente do corpo da requisição.
- Valores financeiros são representados como decimal com precisão declarada ou unidades inteiras; datas no armazenamento em UTC e apresentação no fuso do contrato.
- Telemetria inclui identificação estável de agente, dispositivo, amostra, schema version e sequência/idempotency key.
- Alteração de estado em processamento assíncrono exige machine state explícita e jobs idempotentes.
- APIs públicas com paginação, limites, validação estrita, erros padronizados e documentação OpenAPI.
- Nenhum segredo, senha SNMP/HTTP, credencial de proxy ou token deve aparecer em screenshot, log, URL ou evento de auditoria.

## Segurança, privacidade e confiabilidade

- **Segurança:** HTTPS/TLS, proteção contra SQL injection, XSS, CSRF, SSRF e IDOR, Form Requests, authorization policies, rate limit e validação de uploads.
- **Credenciais:** hash seguro de senhas, criptografia de segredos locais, gestão de chaves, rotação de tokens, princípio do menor privilégio e revogação por agente.
- **Multi-tenant:** políticas e escopo em backend; testes para tentativa de acessar id de outro tenant mesmo alterando querystring ou URL.
- **LGPD:** finalidade, controle de acesso, retenção, exportação e eliminação segura quando aplicável, logs de acesso e contrato de processamento de dados.
- **Resiliência:** filas duráveis, retry com backoff, dead-letter, limites de memória/disco, backup automatizado e testes reais de restauração.
- **Operação:** métricas de ingestão/latência/fila, auditoria, logs estruturados, rastreio de erro e alarmes de jobs presos.
- **Performance:** eager loading para evitar N+1; consultas indexadas, paginação cursor nas tabelas extensas, processamento em lotes e cálculos incrementais rastreáveis.
- **Compatibilidade:** homologação por modelo e versão de firmware para cada contador e nível, com fixtures reais anonimizadas.
- **Qualidade da UX:** responsividade, navegação por teclado, ARIA, feedback de operações, valores localizados em PT-BR, estados vazio/erro/offline e confirmação de ações destrutivas.

## Critérios mínimos de aceite (cenários end-to-end)

| ID | Cenário | Resultado obrigatório |
| --- | --- | --- |
| QA-01 | Cadastro de cliente, filial, departamento e centro de custo | Acesso e filtros respeitam os limites da conta |
| QA-02 | Instalação/registro do agente em uma unidade | Coletor vinculado à chave correta, visível e revogável |
| QA-03 | Descoberta por IP e varredura autorizada | Impressoras encontradas aguardam aprovação; sem scans fora do escopo |
| QA-04 | Coleta SNMP e USB em modelos homologados | Contadores e capacidades reais, data de coleta e origem |
| QA-05 | Agente sem internet por 24h | Buffer local, retransmissão e 0 leituras duplicadas |
| QA-06 | SNMP indisponível, IP trocado e agente offline | Estados e alertas diferentes, sem inventar produção zero |
| QA-07 | Dois agentes detectam a mesma impressora | Sem cobrança dupla; conflito é resolvível |
| QA-08 | Troca de hardware e reset de contadores | Produção anterior preservada, evento e ajuste auditado |
| QA-09 | Impressora removida no meio do ciclo | Passagem encerrada na data correta; cálculo parcial |
| QA-10 | Equipamento reserva desligado | Sem alerta inadequado de ausência de comunicação |
| QA-11 | Impressora sem telemetria com contador manual | Dados aceitos, validáveis e com responsável |
| QA-12 | Nível de suprimento oscila sem troca física | Não gera baixa de estoque automaticamente sem regras |
| QA-13 | Troca real com estoque do técnico | Registro de troca e baixa única do saldo correto |
| QA-14 | Duas pessoas tentam retirar último item | Uma transação aprova e outra recebe erro correto |
| QA-15 | Reposição com envio parcial e recebimento parcial | Saldos de origem, trânsito e destino consistentes |
| QA-16 | Preventiva por dias/páginas/nível | Alertas sem duplicidade e reinício correto após execução |
| QA-17 | Chamado do cliente com mensagem interna | Cliente jamais vê conteúdo interno |
| QA-18 | SLA em horário comercial e feriado | Prazo calculado no servidor com calendário correto |
| QA-19 | Encerrar chamado com peça e serviço | Estoque/valor/alerta atualizados uma vez |
| QA-20 | Franquia compartilhada entre dois dispositivos | Limite e excedente aplicados uma única vez |
| QA-21 | CPP P&B/cor/digitalização e mínimo mensal | Totais com precisão decimal e regras verificadas |
| QA-22 | Contrato iniciando dia 15, fevereiro e ano bissexto | Período correto, inclusive mudança de horário/fuso |
| QA-23 | Dispositivo instalado apenas parte do mês | Opção integral/proporcional registrada |
| QA-24 | Fechamento com pendências e tentativa automática | Bloqueado com motivos verificáveis |
| QA-25 | Congelar, enviar, reabrir sob autorização, recongelar | Snapshot/histórico preservados; nenhum envio duplicado |
| QA-26 | Reajuste programado em contrato | Simulação, aprovação, aplicação e auditoria corretas |
| QA-27 | Relatórios e planilhas grandes | Paginação, processamento, exportação e colunas corretos |
| QA-28 | API token revogado / webhook duplicado | Bloqueio imediato / idempotência |
| QA-29 | Usuário tenta alterar tenant_id na API | 403/404; nenhum vazamento ou modificação cruzada |
| QA-30 | Restauração de backup em ambiente de homologação | Integridade de leitura, estoque e fechamento verificada |
| QA-31 | Navegação do cliente por celular | Operação real sem telas quebradas e com acessibilidade |
| QA-32 | Implantação em ambiente vazio | Migrações, onboarding e primeiros dados operam sem mocks |


### Casos adicionais descobertos na auditoria

| ID | Cenário de teste de aceite |
| --- | --- |
| QA-33 | Descoberta agendada e busca manual respeitam faixas autorizadas e retornam relatório por equipamento |
| QA-34 | Teste de SNMP/HTTP apresenta somente os contadores efetivamente coletados |
| QA-35 | USB alterado de porta ou com série inválida não causa vínculo entre clientes |
| QA-36 | Falha de DNS/firewall/proxy/atualizador sinaliza diagnóstico distinto |
| QA-37 | Envio após 24h sem internet preserva ordem, integridade e não duplica leituras |
| QA-38 | Correção de último contador após 30 dias é bloqueada segundo configuração |
| QA-39 | Reset de contador divide o histórico sem valores negativos nem dupla cobrança |
| QA-40 | Combinação de contadores Geral Cor, Cor Única e 2 Cores segue fórmula homologada |
| QA-41 | Previsão com menos de duas trocas confirmadas retorna amostra insuficiente |
| QA-42 | Troca manual sobre pendente resulta em evento único |
| QA-43 | Confirmação automática com SKU incompatível, ambíguo ou sem estoque é rejeitada |
| QA-44 | Desconsiderar troca falsa recalcula média e mantém trilha de auditoria |
| QA-45 | Estoque no técnico e no cliente é afetado apenas pela movimentação/transação correta |
| QA-46 | Erro da impressora recupera, é ignorado ou reaparece conforme parametrização |
| QA-47 | Alerta sem comunicação encerra apenas depois de estabilidade conforme política |
| QA-48 | Vários gatilhos de preventiva são correlacionados sem perda de origem |
| QA-49 | Chamado com material para cliente versus instalação no equipamento mantém saldo e cobrança |
| QA-50 | Contrato tenta definir dois padrões para mesmo cliente em concorrência: rejeita um |
| QA-51 | Congelamento automático não ultrapassa pendências ou ciclos antigos não congelados |
| QA-52 | Congelamento manual ignorando pendência exige permissão, motivação e audit trail |
| QA-53 | Descongelamento fora da janela ou diferente do mais recente é rejeitado |
| QA-54 | Reajuste do contrato não modifica snapshot congelado e mostra períodos afetados |
| QA-55 | Relatório enviado no primeiro congelamento não é reenviado involuntariamente no recongelamento |
| QA-56 | Movimentação parcial no ciclo exige decisão integral/proporcional explícita |
| QA-57 | Relatórios de produção indicam última coleta e desatualização |
| QA-58 | Portal cliente nunca revela relatório/estoque de outro cliente |
| QA-59 | API paginada mantém ordem/limites e rejeita token revogado |
| QA-60 | Plano SaaS opcional cobra impressora uma só vez mesmo após troca de cliente |
| QA-61 | Agenda de relatório respeita destinatário, janela, formato e autorização |
| QA-62 | Teste de privacidade garante nenhuma captura do conteúdo ou imagem de documentos |

**Conclusão de um requisito:** código revisado + migration/contrato de dados + autorização + teste feliz + testes de falha + documentação + evidência de validação. Build ou interface visual isolados **não** comprovam entrega.

## Roadmap sugerido (dependências)

| Etapa | Entregável validável |
| --- | --- |
| Fase 0 — Fundamentos | Monorepo, CI, banco, autenticação, roles, tenants, design system, contrato API, testes |
| Fase 1 — Núcleo de coleta | Agente instalável, descoberta, SNMP, fila offline, upload, telemetria, parque |
| Fase 2 — Operação | Locais/contratos básicos, contadores manuais, alertas, preventivas, dashboard |
| Fase 3 — Suprimentos | Catálogo, níveis, troca, estoques, movimentos, previsões, reposições |
| Fase 4 — Help Desk | Portal cliente, técnicos, Kanban, SLA, serviços, materiais, fechamento |
| Fase 5 — Financeiro | CPP, franquia, faixas, mínimo, reajuste, fechamento, congelamento, relatórios |
| Fase 6 — Expansão | Outros dispositivos, integrações ERP/BI, API pública, envios programados |
| Fase 7 — Produção | Homologação de fabricantes, carga, segurança, restauração, instaladores, documentação |

O sistema pode ser lançado por fases **somente deixando claro quais módulos estão operacionais**. Nenhum marco deve ser marcado concluído antes dos testes da matriz.

## Fontes públicas estudadas

Documentação oficial consultada em **08/10/2026**:

- [Site e visão comercial PrintWayy Dragon](https://printwayy.com/)
- [Central de Ajuda e módulos](https://help.printwayy.com/)
- [Visão geral da interface](https://help.printwayy.com/visao-geral/)
- [Clientes, filiais e centros de custo](https://help.printwayy.com/cadastrar-um-cliente/)
- [Usuários e permissões](https://help.printwayy.com/cadastrar-usuarios/)
- [Instalação do coletor](https://help.printwayy.com/instalacao-client/)
- [Monitoramento via rede e USB](https://help.printwayy.com/monitorando-impressoras/)
- [Monitoramento remoto, busca por IP e varredura](https://help.printwayy.com/monitorando-remotamente/)
- [Monitoramento de conexões](https://help.printwayy.com/monitoramento-conexoes/)
- [Novas impressoras](https://help.printwayy.com/novas-impressoras/)
- [Parque e histórico](https://help.printwayy.com/visualizando-impressoras/)
- [Configuração por impressora](https://help.printwayy.com/configuracoes-impressora/)
- [Movimentações entre clientes](https://help.printwayy.com/movimentando-impressoras/)
- [Monitoramento duplicado](https://help.printwayy.com/monitoramentos-duplicados/)
- [Contadores manuais](https://help.printwayy.com/adicionando-contadores/)
- [Níveis de suprimentos](https://help.printwayy.com/niveis-suprimentos/)
- [Trocas de suprimentos](https://help.printwayy.com/trocas-suprimento/)
- [Estoque e fornecedores](https://help.printwayy.com/estoque-suprimentos/)
- [Movimentação de estoque](https://help.printwayy.com/movimentando-estoque/)
- [Previsão e reposição](https://help.printwayy.com/reposicao-suprimentos/)
- [Indicadores de uso](https://help.printwayy.com/indicadores-suprimentos/)
- [Configuração de suprimentos](https://help.printwayy.com/configuracoes-suprimentos-estoque-trocas/)
- [Abertura de chamados](https://help.printwayy.com/como-abrir-um-chamado/)
- [Atendimento e custos](https://help.printwayy.com/atendendo-chamados/)
- [Configuração de chamados e SLA](https://help.printwayy.com/configuracoes-de-chamados/)
- [Alertas](https://help.printwayy.com/alertas/)
- [Regras de alertas e preventivas](https://help.printwayy.com/configuracoes-alertas/)
- [Contratos, franquia e custos](https://help.printwayy.com/adicionando-contrato/)
- [Reajustes de contrato](https://help.printwayy.com/aplicando-um-reajuste-no-contrato/)
- [Fechamentos mensais](https://help.printwayy.com/fechamentos-mensais/)
- [Configuração de relatório de fechamento](https://help.printwayy.com/configuracao-relatorio-fechamento/)
- [Dispositivos de TI](https://help.printwayy.com/cadastrando-um-dispositivo/)
- [Configuração de dispositivos](https://help.printwayy.com/configuracoes-de-dispositivos/)
- [Relatórios agendados](https://help.printwayy.com/relatorio-email/)
- [Notificações por eventos](https://help.printwayy.com/notificar-eventos/)
- [Relatórios de chamados](https://help.printwayy.com/relatorio-chamados/)
- [Integrações e APIs](https://help.printwayy.com/integracao/)
- [Dúvidas técnicas e exceções](https://help.printwayy.com/duvidas-frequentes/)
- [Atualizações públicas](https://help.printwayy.com/ultimas-atualizacoes/)

## Verificações pendentes antes de afirmar equivalência completa

- **Interface autenticada:** menu exato, todas as telas e modais, filtros, componentes, relatórios e responsividade por nível de permissão.
- **Matriz real de permissões e regras de licença:** combinações por provedor, cliente, usuário e equipamento.
- **Formatos de exportação e anexos:** opções e layouts disponíveis em cada tela e caso de negócio.
- **Compatibilidade de hardware:** catálogo concreto, contadores/OIDs, firmware, qualidade do suporte USB e limitações reais, incluindo restrições de rede/USB.
- **API e autenticação:** comportamento detalhado dos endpoints documentados, paginação, erros e limites; só com acesso autorizado.
- **Regras e casos excepcionais não documentados:** cálculos de fechamento, cancelamentos, relatórios, atualizações e suporte.
- **Desempenho e operação em larga escala:** testes reais de rede, volume de dados e falhas, com SLO, backup validado e tempos RTO/RPO definidos.
- **Conformidade legal e produto:** definir licença de código própria, política de privacidade, termos e governança de dados.

Qualquer descoberta posterior deve originar novo requisito **NX-...**, teste **QA-...**, referência pública ou evidência autorizada, para que não se percam detalhes durante a implementação.

---

**Nexa System — arquitetura própria, operação verificável, sem funcionalidades fictícias.**
