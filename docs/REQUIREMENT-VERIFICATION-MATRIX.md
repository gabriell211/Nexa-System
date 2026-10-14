# Nexa System — matriz de rastreabilidade verificável

**Data da análise:** 2026-10-12.
**Fonte de verdade funcional:** [README principal](../README.md).
**Branch em análise:** `feat/foundation-admin-portal-20261009`.
**Regra:** nenhum requisito abaixo significa "CONCLUÍDO" sem prova do fluxo integral em execução real, incluindo autorização, persistência, erros e UI.

## Achados iniciais da auditoria estática

- `main` ainda contém somente o esqueleto inicial; a expansão está em PR #1 como **Draft**, ainda não em release.
- Trabalho comprovadamente existente na branch: Laravel API multi-tenant, portal React, migrations, autenticação web por sessão, Collector inicial Rust, organização de clientes e histórico de instalação de impressoras.
- Módulos operacionais abrangentes **sem evidência de implementação final**: estoque com razão contábil, suprimentos, alertas, chamados/SLA, contratos, fechamento/faturamento, relatórios completos, ERP/webhooks, SaaS/billing, portal do cliente.
- CSRF foi exercitado na suíte Laravel de testes, mas não houve teste E2E em browser real com middleware de CSRF habilitado sob `APP_ENV=production`.
- A homologação Windows/Linux/macOS e modelos/firmwares de impressoras reais não foi feita no ambiente do CI.
- O último CI completo antes desta matriz reportou **35 testes / 170 assertions**, PostgreSQL e SQLite, build do React, Docker e Rust. Isso representa testes existentes, não os 62 QA funcionais.
- Não há evidência de testes de carga, restauração de backup, failover, isolamento sob concorrência real nem OWASP DAST.

## Critérios para marcar concluído

1. Implementação do backend e frontend/agent, quando aplicável.
2. Verificação de tenancy, carteira, perfil e acesso por objeto.
3. Testes unitários/integração e teste E2E explícito para o requisito, com logs datados.
4. Migração retrocompatível aplicada e validada em PostgreSQL com dados existentes.
5. Compatibilidade com periféricos, protocolos e sistemas operacionais comprovada onde exigida.

## Requisitos NX — inventário completo (324)

| ID | Categoria | Evidência hoje | Necessidade resumida |
|---|---|---|---|
| NX-001 | Base, tenants, clientes e locais | PARCIAL — sem aceite E2E | Operação multiempresa (provedores/tenants) isolada, com estrutura de unidades do próprio provedor e respectivos CNPJs. |
| NX-002 | Base, tenants, clientes e locais | PARCIAL — sem aceite E2E | Clientes pessoa jurídica/física, razão social, nome fantasia, documento, inscrição, contatos e código externo de ERP. |
| NX-003 | Base, tenants, clientes e locais | PARCIAL — sem aceite E2E | Unidades/filiais com endereço, documento próprio ou compartilhado e identificador de localização. |
| NX-004 | Base, tenants, clientes e locais | PARCIAL — sem aceite E2E | Departamentos, setores, centros de custo, contatos e responsáveis por localidade. |
| NX-005 | Base, tenants, clientes e locais | PARCIAL — sem aceite E2E | Vínculos entre cliente, unidade, contrato, impressora, dispositivo, responsável e centro de custo com histórico temporal. |
| NX-006 | Base, tenants, clientes e locais | PARCIAL — sem aceite E2E | Cadastro, edição, ativação e inativação sem exclusão destrutiva de registros vinculados. |
| NX-007 | Base, tenants, clientes e locais | NÃO COMPROVADO | Importação validada de clientes, equipamentos e contratos por CSV/Excel com prévia e relatório de erros. |
| NX-008 | Base, tenants, clientes e locais | NÃO COMPROVADO | Chaves individuais de registro dos agentes por cliente, revogáveis e com rotação segura. |
| NX-009 | Base, tenants, clientes e locais | NÃO COMPROVADO | Configuração de horários de expediente do provedor e de cada filial do cliente, incluindo feriados e fuso. |
| NX-010 | Base, tenants, clientes e locais | NÃO COMPROVADO | Ativação granular de módulos por provedor, cliente e impressora e valores-padrão para novos clientes. |
| NX-011 | Base, tenants, clientes e locais | PARCIAL — sem aceite E2E | Pesquisa global, filtros persistentes, paginação, classificação, seleção múltipla, exportação e colunas personalizáveis. |
| NX-012 | Base, tenants, clientes e locais | PARCIAL — sem aceite E2E | Histórico de ações por entidade com autor, origem, antes/depois e carimbo de data/hora. |
| NX-013 | Identidade, perfis e portal do cliente | PARCIAL — sem aceite E2E | Login, logout, recuperação e redefinição segura de senha, convite por e-mail e verificação da conta. |
| NX-014 | Identidade, perfis e portal do cliente | NÃO COMPROVADO | Autenticação multifator para perfis privilegiados; sessões gerenciáveis e revogação. |
| NX-015 | Identidade, perfis e portal do cliente | PARCIAL — sem aceite E2E | Perfis: dono da plataforma, administrador do provedor, financeiro, gestor, supervisor, técnico, almoxarifado e cliente. |
| NX-016 | Identidade, perfis e portal do cliente | PARCIAL — sem aceite E2E | RBAC por ação (listar, ver, criar, editar, excluir, exportar, congelar, administrar) e autorização objeto a objeto. |
| NX-017 | Identidade, perfis e portal do cliente | PARCIAL — sem aceite E2E | Restrições simultâneas por provedor, clientes atribuídos, unidades, departamentos e módulos licenciados. |
| NX-018 | Identidade, perfis e portal do cliente | NÃO COMPROVADO | Usuários de cliente vinculados a um ou mais clientes, com limites por localização. |
| NX-019 | Identidade, perfis e portal do cliente | PARCIAL — sem aceite E2E | Usuários internos do provedor com acesso a todos os clientes ou apenas carteiras/unidades autorizadas. |
| NX-020 | Identidade, perfis e portal do cliente | NÃO COMPROVADO | Portal do cliente para equipamentos, consumos, suprimentos, chamados, relatórios e recebimentos autorizados. |
| NX-021 | Identidade, perfis e portal do cliente | NÃO COMPROVADO | Preferências de idioma (PT-BR inicialmente), tema, notificações, período e colunas. |
| NX-022 | Identidade, perfis e portal do cliente | NÃO COMPROVADO | Auditoria de login, tentativas suspeitas, autenticação e acesso administrativo. |
| NX-023 | Agente local e comunicação | PARCIAL — sem aceite E2E | Agente local multiplataforma planejado para Windows, Linux e macOS, com matriz explícita de recursos suportados por sistema. |
| NX-024 | Agente local e comunicação | NÃO COMPROVADO | Instaladores assinados, versão de serviço, execução em segundo plano, inicialização automática e desinstalação limpa. |
| NX-025 | Agente local e comunicação | NÃO COMPROVADO | Ativação pelo cadastro do cliente com chave de instalação e identificação única do ponto de coleta. |
| NX-026 | Agente local e comunicação | NÃO COMPROVADO | Serviços/processos separados logicamente: descoberta/coleta, transmissão, atualização e supervisor de saúde. |
| NX-027 | Agente local e comunicação | NÃO COMPROVADO | Descoberta autorizada por broadcast, subnet e intervalos de IP configurados; varredura programada e manual. |
| NX-028 | Agente local e comunicação | PARCIAL — sem aceite E2E | Cadastro direto de IP e preparação remota para monitoramento quando o agente reconhecer o dispositivo. |
| NX-029 | Agente local e comunicação | PARCIAL — sem aceite E2E | SNMP v1/v2c/v3, perfis de credenciais, OIDs padronizados e adaptadores por fabricante/modelo. |
| NX-030 | Agente local e comunicação | NÃO COMPROVADO | HTTP/HTTPS, IPP e autenticação do fabricante onde necessário, sem contornar segurança do equipamento. |
| NX-031 | Agente local e comunicação | NÃO COMPROVADO | Descoberta de impressoras USB, com suporte condicionado ao sistema operacional, drivers e fabricante. |
| NX-032 | Agente local e comunicação | NÃO COMPROVADO | Configuração de credenciais por impressora com armazenamento cifrado, nunca na interface ou em logs. |
| NX-033 | Agente local e comunicação | PARCIAL — sem aceite E2E | Coleta configurável de contadores, capacidade, consumíveis, eventos, dados de identificação e estado. |
| NX-034 | Agente local e comunicação | NÃO COMPROVADO | Intervalos separados de consulta, descoberta, heartbeat e upload, com jitter, timeout e backoff. |
| NX-035 | Agente local e comunicação | PARCIAL — sem aceite E2E | Fila de envio persistente, criptografada, limitada em disco, reenvio ordenado e deduplicação no backend. |
| NX-036 | Agente local e comunicação | PARCIAL — sem aceite E2E | Envio por HTTPS/TLS usando apenas conexão iniciada pelo agente; compatibilidade com proxy autenticado. |
| NX-037 | Agente local e comunicação | NÃO COMPROVADO | Canal alternativo de transporte por e-mail SMTP/POP3 somente se autorizado e implementado com cifragem, autenticação e limites. |
| NX-038 | Agente local e comunicação | NÃO COMPROVADO | Diagnóstico local, teste de comunicação, teste de SNMP, validação de IP e logs sanitizados. |
| NX-039 | Agente local e comunicação | NÃO COMPROVADO | Monitoramento e recuperação de serviços travados com supervisor, sem loop infinito de reinicialização. |
| NX-040 | Agente local e comunicação | NÃO COMPROVADO | Atualização automática controlada, assinatura/verificação de artefatos, rollback e política de versões mínimas. |
| NX-041 | Agente local e comunicação | NÃO COMPROVADO | Monitoramento de pontos instalados com data de última comunicação, versão, rede e estado. |
| NX-042 | Agente local e comunicação | NÃO COMPROVADO | Reinstalação e recuperação dos dados locais sem replicar leituras já recebidas. |
| NX-043 | Agente local e comunicação | NÃO COMPROVADO | Configuração remota segura limitada aos agentes do próprio provedor e a usuários autorizados. |
| NX-044 | Agente local e comunicação | NÃO COMPROVADO | Identificação de duplicidade de agentes ou colisão de identidade de impressora. |
| NX-045 | Agente local e comunicação | NÃO COMPROVADO | Política de retenção de dados na borda, limites de memória/CPU e suporte a clientes com conexão intermitente. |
| NX-046 | Agente local e comunicação | NÃO COMPROVADO | Inventário e diagnóstico de compatibilidade de hardware sem declarar homologado um modelo não testado. |
| NX-047 | Parque, ciclo de vida e impressoras | PARCIAL — sem aceite E2E | Parque completo, impressoras por cliente, novas impressoras e impressoras com monitoramento duplicado. |
| NX-048 | Parque, ciclo de vida e impressoras | PARCIAL — sem aceite E2E | Cadastro de fabricante, modelo, série, IP, MAC, patrimônio, responsável, proprietário, localização e departamento. |
| NX-049 | Parque, ciclo de vida e impressoras | NÃO COMPROVADO | Separação entre equipamentos próprios do provedor e pertencentes ao cliente. |
| NX-050 | Parque, ciclo de vida e impressoras | NÃO COMPROVADO | Registro de monitoramento automático em rede, USB, monitoramento por IP preparado remotamente e contadores manuais. |
| NX-051 | Parque, ciclo de vida e impressoras | NÃO COMPROVADO | Visões de disponível no estoque, instalado, em reparo, reserva/backup, retirado, vendido e descartado. |
| NX-052 | Parque, ciclo de vida e impressoras | NÃO COMPROVADO | Impressoras de backup sem falso alerta por período normal desligado. |
| NX-053 | Parque, ciclo de vida e impressoras | PARCIAL — sem aceite E2E | Histórico de passagens por clientes e datas de instalação/recolhimento/movimentação. |
| NX-054 | Parque, ciclo de vida e impressoras | PARCIAL — sem aceite E2E | Desativação com data efetiva, reativação com novo histórico ou continuidade deliberada do histórico anterior. |
| NX-055 | Parque, ciclo de vida e impressoras | NÃO COMPROVADO | Detecção de impressora nova usando IP já ocupado, alteração de IP e série inconsistentes. |
| NX-056 | Parque, ciclo de vida e impressoras | NÃO COMPROVADO | Correção assistida de duplicações entre clientes preservando trilha de auditoria. |
| NX-057 | Parque, ciclo de vida e impressoras | NÃO COMPROVADO | Vínculo automático opcional ao contrato-padrão e custos-padrão do cliente. |
| NX-058 | Parque, ciclo de vida e impressoras | PARCIAL — sem aceite E2E | Detalhes por equipamento com geral, contadores, suprimentos, histórico, gráficos, comentários, configurações e ações. |
| NX-059 | Parque, ciclo de vida e impressoras | NÃO COMPROVADO | Gerenciamento de recursos de monitoramento, alertas e regras de SLA específicos por impressora. |
| NX-060 | Parque, ciclo de vida e impressoras | NÃO COMPROVADO | Colunas, filtros, seleção em lote, relatório de sucesso/falha e exportação Excel. |
| NX-061 | Parque, ciclo de vida e impressoras | PARCIAL — sem aceite E2E | Status diferenciados: comunicando, sem comunicação, manual, aguardando detecção, reserva, não monitorado e descartado. |
| NX-062 | Parque, ciclo de vida e impressoras | NÃO COMPROVADO | Inventário de modelos homologados com teste por protocolo/capacidade, não apenas marca. |
| NX-063 | Telemetria, contadores e produção | PARCIAL — sem aceite E2E | Armazenar amostras imutáveis brutas, normalizadas e suas origens, com timestamps de coleta e de ingestão. |
| NX-064 | Telemetria, contadores e produção | NÃO COMPROVADO | Contadores geral, P&B, cor, digitalização e detalhes disponíveis (cópia, impressão, cor única/duas cores etc.). |
| NX-065 | Telemetria, contadores e produção | NÃO COMPROVADO | Mapeamento por fabricante/modelo de qual contador entra em cada categoria faturável; impedir dupla contagem. |
| NX-066 | Telemetria, contadores e produção | NÃO COMPROVADO | Suporte a relatórios de páginas diárias, mensais, intervalo customizado e série histórica por impressora/cliente/contrato. |
| NX-067 | Telemetria, contadores e produção | NÃO COMPROVADO | Lançamento manual com data, autor, justificativa e valores P&B/cor/digitalização, inclusive para aparelhos sem conectividade. |
| NX-068 | Telemetria, contadores e produção | NÃO COMPROVADO | Correção de leituras manuais mediante política de auditoria e vedação se associadas a período financeiro congelado. |
| NX-069 | Telemetria, contadores e produção | NÃO COMPROVADO | Validação de leituras decrescentes, overflow, reset, troca de formatter/placa e substituição de equipamento. |
| NX-070 | Telemetria, contadores e produção | PARCIAL — sem aceite E2E | Tratamento de dados ausentes, antigos, inconsistentes, duplicados, fora de ordem e envio tardio. |
| NX-071 | Telemetria, contadores e produção | PARCIAL — sem aceite E2E | Idempotência e chave única por amostra para impedir produção duplicada por retransmissão. |
| NX-072 | Telemetria, contadores e produção | NÃO COMPROVADO | Reconciliação entre contador inicial e final por período e por passagem do equipamento. |
| NX-073 | Telemetria, contadores e produção | NÃO COMPROVADO | Produção parcial por instalação ou retirada no meio do período. |
| NX-074 | Telemetria, contadores e produção | NÃO COMPROVADO | Compatibilidade consciente das diferenças de contagem A3/A4 e tamanhos de mídia; configurar conversão somente quando comprovada. |
| NX-075 | Telemetria, contadores e produção | PARCIAL — sem aceite E2E | Gráficos com datas e fuso corretos e distinção clara entre produção zero e produção desconhecida. |
| NX-076 | Telemetria, contadores e produção | NÃO COMPROVADO | Armazenamento histórico particionado/retenção configurável e consulta eficiente em parque grande. |
| NX-077 | Telemetria, contadores e produção | NÃO COMPROVADO | Relatórios de impressões/cópias, digitalizações e totais por cliente com grupos por unidade e departamento. |
| NX-078 | Suprimentos, peças e inteligência de consumo | PARCIAL — implementação e CI; sem aceite integral | Catálogo de suprimentos e peças com tipo, marca, modelo, código SKU, cor, preço, custo, rendimento e compatibilidade. |
| NX-079 | Suprimentos, peças e inteligência de consumo | NÃO COMPROVADO | Tipos personalizáveis (toner, cilindro, revelador, fusor, kit, reservatório, tinta e outros) com controles por tipo. |
| NX-080 | Suprimentos, peças e inteligência de consumo | NÃO COMPROVADO | Leitura e histórico de nível, capacidade, série, cobertura e indicadores reportados pela impressora. |
| NX-081 | Suprimentos, peças e inteligência de consumo | NÃO COMPROVADO | Separação entre nível informado pelo hardware e nível real estimado com confiabilidade explícita. |
| NX-082 | Suprimentos, peças e inteligência de consumo | NÃO COMPROVADO | Detecção de possível troca por aumento de nível, alteração de capacidade/série ou registro humano. |
| NX-083 | Suprimentos, peças e inteligência de consumo | NÃO COMPROVADO | Troca manual pelo técnico/cliente, com permissões e pendência de confirmação quando necessário. |
| NX-084 | Suprimentos, peças e inteligência de consumo | NÃO COMPROVADO | Listas de próximas trocas, pendentes, confirmadas, excluídas e prematuras. |
| NX-085 | Suprimentos, peças e inteligência de consumo | NÃO COMPROVADO | Confirmar, rejeitar, excluir logicamente, desconsiderar ou reativar para cálculos de médias, sempre com auditoria. |
| NX-086 | Suprimentos, peças e inteligência de consumo | NÃO COMPROVADO | Origem obrigatória da peça nova: estoque do provedor, técnico ou unidade do cliente, com validação de saldo. |
| NX-087 | Suprimentos, peças e inteligência de consumo | NÃO COMPROVADO | Confirmação automática opcional por cliente, condicionada a saldo, compatibilidade e ausência de conflito. |
| NX-088 | Suprimentos, peças e inteligência de consumo | NÃO COMPROVADO | Detecção de leituras inválidas/chipless e prevenção de trocas fictícias; revisão humana em casos duvidosos. |
| NX-089 | Suprimentos, peças e inteligência de consumo | NÃO COMPROVADO | Cálculo de páginas produzidas entre trocas confirmadas; rendimento observado, eficiência e cobertura. |
| NX-090 | Suprimentos, peças e inteligência de consumo | NÃO COMPROVADO | Estimativa de consumo individual por equipamento e suprimento; previsão de data e nível real. |
| NX-091 | Suprimentos, peças e inteligência de consumo | NÃO COMPROVADO | Definir volume mínimo de histórico antes de produzir previsão confiável; oferecer estimativa indisponível em vez de inventá-la. |
| NX-092 | Suprimentos, peças e inteligência de consumo | NÃO COMPROVADO | Regra parametrizável para troca prematura com base em nível declarado, estimado ou eficiência. |
| NX-093 | Suprimentos, peças e inteligência de consumo | NÃO COMPROVADO | Histórico, linha do tempo de suprimentos e peças, responsáveis e custos por cliente/equipamento. |
| NX-094 | Suprimentos, peças e inteligência de consumo | NÃO COMPROVADO | Filtros avançados salváveis, colunas arrastáveis, ordenação e exportação de níveis e indicadores. |
| NX-095 | Suprimentos, peças e inteligência de consumo | NÃO COMPROVADO | Identificação de desperdício, possibilidade de remanejamento/reutilização autorizada e custos evitáveis. |
| NX-096 | Suprimentos, peças e inteligência de consumo | NÃO COMPROVADO | Configuração granular de quais suprimentos geram alerta e sugestões de troca por impressora. |
| NX-097 | Suprimentos, peças e inteligência de consumo | NÃO COMPROVADO | Preparar motor estatístico determinístico; qualquer futuro uso de IA deverá ser isolado, validado e opcional. |
| NX-098 | Estoque, movimentação e reposição | PARCIAL — implementação e CI; sem aceite integral | Estoque da empresa, depósitos, técnicos e cada localidade do cliente, com posições de saldo separadas. |
| NX-099 | Estoque, movimentação e reposição | PARCIAL — fornecedor e lote de entrada; sem rastreamento pós-entrada | Cadastro de fornecedores, nota fiscal/documento, lote, custo, validade e entrada unitária ou em massa. |
| NX-100 | Estoque, movimentação e reposição | PARCIAL — implementação e CI; sem aceite integral | Transferência provedor → técnico, provedor → cliente, técnico → cliente, cliente → técnico e devolução ao provedor. |
| NX-101 | Estoque, movimentação e reposição | PARCIAL — implementação e CI; sem aceite integral | Movimento transacional com origem/destino, saldo antes/depois, autorização, justificativa e trilha de auditoria. |
| NX-102 | Estoque, movimentação e reposição | PARCIAL — implementação e CI; sem aceite integral | Bloqueio de saldo negativo e de consumo concorrente da mesma unidade; reservas para chamados e reposições. |
| NX-103 | Estoque, movimentação e reposição | PARCIAL — seleção por lote e vencimento; inventário físico não implementado | Inventário físico, ajuste supervisionado, perdas, baixas, devoluções e histórico contábil de estoque. |
| NX-104 | Estoque, movimentação e reposição | NÃO COMPROVADO | Controle de estoque mínimo, crítico, segurança e sugestão de compra. |
| NX-105 | Estoque, movimentação e reposição | NÃO COMPROVADO | Programação de reposição por intervalo, clientes, modelos, tipos e demanda prevista. |
| NX-106 | Estoque, movimentação e reposição | NÃO COMPROVADO | Revisão da sugestão com ajuste de quantidades, item de segurança e inserção manual de itens. |
| NX-107 | Estoque, movimentação e reposição | NÃO COMPROVADO | Lista de reposições programadas com data limite, responsável e estado de envio/recebimento. |
| NX-108 | Estoque, movimentação e reposição | PARCIAL — implementação e CI; sem aceite integral | Baixa no estoque de origem ao confirmar envio e entrada no estoque da unidade ao confirmar recebimento. |
| NX-109 | Estoque, movimentação e reposição | NÃO COMPROVADO | Confirmação de recebimento pelo provedor ou usuário cliente autorizado. |
| NX-110 | Estoque, movimentação e reposição | PARCIAL — implementação e CI; sem aceite integral | Tratamento de envio parcial, recebimento parcial, extravio, divergência e cancelamento. |
| NX-111 | Estoque, movimentação e reposição | PARCIAL — implementação e CI; sem aceite integral | Rastreio por movimentação, técnico, cliente, unidade e impressora. |
| NX-112 | Alertas e manutenções preventivas | NÃO COMPROVADO | Alertas de falha da impressora, de comunicação impressora/agente/servidor e de manutenção preventiva. |
| NX-113 | Alertas e manutenções preventivas | NÃO COMPROVADO | Criticidade, categoria, origem, timestamps e estados ativo, reconhecido, vinculado, resolvido e ignorado. |
| NX-114 | Alertas e manutenções preventivas | NÃO COMPROVADO | Atraso configurável antes do alerta de desconexão, para reduzir ruído de instabilidades curtas. |
| NX-115 | Alertas e manutenções preventivas | NÃO COMPROVADO | Exigir comunicação estável antes de encerrar alerta automático de falha de conexão. |
| NX-116 | Alertas e manutenções preventivas | NÃO COMPROVADO | Regras por dias, páginas produzidas, nível de suprimento, fabricante, modelo, cliente e impressora. |
| NX-117 | Alertas e manutenções preventivas | NÃO COMPROVADO | Exceções por equipamento e aplicação das regras em novos equipamentos que correspondam ao filtro. |
| NX-118 | Alertas e manutenções preventivas | NÃO COMPROVADO | Histórico de manutenções preventivas com data realizada, peças, técnico e reinício do ciclo da regra. |
| NX-119 | Alertas e manutenções preventivas | NÃO COMPROVADO | Configuração de alertas específicos de USB/rede, catálogo de códigos e severidades por dispositivo. |
| NX-120 | Alertas e manutenções preventivas | NÃO COMPROVADO | Supressão contextual em dispositivos reserva, em manutenção, ou em janelas agendadas. |
| NX-121 | Alertas e manutenções preventivas | NÃO COMPROVADO | Silenciamento, reconhecimento, ignorar, encerramento automático e explicação do motivo. |
| NX-122 | Alertas e manutenções preventivas | NÃO COMPROVADO | Deduplicação e correlação; alerta persistente não pode abrir chamados repetidos sem política. |
| NX-123 | Alertas e manutenções preventivas | NÃO COMPROVADO | Relacionar alerta a chamado existente ou criar chamado novo com equipamento e cliente preenchidos. |
| NX-124 | Alertas e manutenções preventivas | NÃO COMPROVADO | Estados de alertas vinculados a chamados e regra de encerramento coerente. |
| NX-125 | Alertas e manutenções preventivas | NÃO COMPROVADO | Notificações por e-mail/eventos com preferências por destinatário, cliente, tipo e criticidade. |
| NX-126 | Alertas e manutenções preventivas | NÃO COMPROVADO | Histórico de ações em alertas com autor e visibilidade para auditoria. |
| NX-127 | Chamados, técnicos, atendimento e SLA | PARCIAL — API/portal inicial; QA E2E limitado | Chamados abertos pelo cliente ou pelo provedor, vinculados opcionalmente a impressora(s), dispositivo(s) e alerta(s). |
| NX-128 | Chamados, técnicos, atendimento e SLA | PARCIAL — API/portal inicial; QA E2E limitado | Permitir chamado interno sem cliente/equipamento para tarefas administrativas. |
| NX-129 | Chamados, técnicos, atendimento e SLA | NÃO COMPROVADO | Tipos de chamados configuráveis com opção de visibilidade para o portal cliente. |
| NX-130 | Chamados, técnicos, atendimento e SLA | NÃO COMPROVADO | Abertura com descrição, categoria, criticidade, prioridade, anexos e registro de origem. |
| NX-131 | Chamados, técnicos, atendimento e SLA | PARCIAL — API/portal inicial; QA E2E limitado | Fila sem responsável, quadro de chamados ativos e lista de finalizados/encerrados. |
| NX-132 | Chamados, técnicos, atendimento e SLA | PARCIAL — API/portal inicial; QA E2E limitado | Estados de triagem, aguardando atribuição, atendimento pendente, em atendimento, atendimento finalizado e encerrado. |
| NX-133 | Chamados, técnicos, atendimento e SLA | PARCIAL — API/portal inicial; QA E2E limitado | Responsável técnico, equipe, transferência, reabertura e histórico de alterações. |
| NX-134 | Chamados, técnicos, atendimento e SLA | PARCIAL — API/portal inicial; QA E2E limitado | Comentários internos e comentários públicos para cliente com autorização correta. |
| NX-135 | Chamados, técnicos, atendimento e SLA | NÃO COMPROVADO | Serviços prestados catalogados, valor por serviço, peças de estoque utilizadas e custos adicionais. |
| NX-136 | Chamados, técnicos, atendimento e SLA | NÃO COMPROVADO | Destino de peça usada: cliente (estoque) ou instalação na impressora, conforme natureza do atendimento. |
| NX-137 | Chamados, técnicos, atendimento e SLA | NÃO COMPROVADO | Cálculo de valor total do serviço, desconto, pagamento confirmado e conferência antes do encerramento. |
| NX-138 | Chamados, técnicos, atendimento e SLA | NÃO COMPROVADO | Relação entre chamados e alertas e encerramento com tratamento consistente dos alertas associados. |
| NX-139 | Chamados, técnicos, atendimento e SLA | NÃO COMPROVADO | Impressão de ordem de serviço com linhas adicionais configuráveis e identidade da empresa. |
| NX-140 | Chamados, técnicos, atendimento e SLA | NÃO COMPROVADO | Política de SLA por cliente, filial e impressora, com herança e substituição explícitas. |
| NX-141 | Chamados, técnicos, atendimento e SLA | NÃO COMPROVADO | Contagem em horas corridas, horas úteis do provedor ou horas úteis do cliente, conforme a regra. |
| NX-142 | Chamados, técnicos, atendimento e SLA | NÃO COMPROVADO | Calendário de feriados, horários de trabalho, relógio do servidor, pausas, atrasos e escalonamentos. |
| NX-143 | Chamados, técnicos, atendimento e SLA | NÃO COMPROVADO | Indicadores de tempo de primeira resposta, tempo até resolução, reincidências e atendimentos por técnico. |
| NX-144 | Chamados, técnicos, atendimento e SLA | NÃO COMPROVADO | Notificações de chamado aberto, comentário, alteração, prazo próximo e SLA vencido. |
| NX-145 | Chamados, técnicos, atendimento e SLA | NÃO COMPROVADO | Relatórios de chamados com construtor de colunas, filtros avançados, visões salvas e exportação. |
| NX-146 | Chamados, técnicos, atendimento e SLA | NÃO COMPROVADO | Auditoria sobre registros, serviços, materiais, descontos, alterações e fechamento. |
| NX-147 | Chamados, técnicos, atendimento e SLA | NÃO COMPROVADO | Bloqueio ou alerta por material pendente/valor em aberto no encerramento conforme política. |
| NX-148 | Outros dispositivos de TI | NÃO COMPROVADO | Cadastro independente de impressoras de ativos locados: tipo, fabricante, modelo, série, patrimônio, IP e MAC. |
| NX-149 | Outros dispositivos de TI | NÃO COMPROVADO | Cadastro de categorias, fabricantes e modelos de dispositivos. |
| NX-150 | Outros dispositivos de TI | NÃO COMPROVADO | Localização, cliente, departamento, notas, comentários e histórico de movimentações. |
| NX-151 | Outros dispositivos de TI | NÃO COMPROVADO | Estado de manutenção/uso e filtros por status. |
| NX-152 | Outros dispositivos de TI | NÃO COMPROVADO | Vínculo de dispositivo a contrato e valor fixo periódico. |
| NX-153 | Outros dispositivos de TI | NÃO COMPROVADO | Chamados vinculáveis apenas a dispositivos, a impressoras ou aos dois. |
| NX-154 | Outros dispositivos de TI | PARCIAL — sem aceite E2E | Entrada/retirada parcial no ciclo financeiro com cobrança integral/proporcional revisável. |
| NX-155 | Outros dispositivos de TI | PARCIAL — sem aceite E2E | Listagem com filtros por cliente, colunas configuráveis e exportação Excel. |
| NX-156 | Financeiro: contratos e regras de custo | PARCIAL — sem aceite E2E | Contrato com cliente, unidade prestadora do provedor, número de documento, identificação e localização de cobrança. |
| NX-157 | Financeiro: contratos e regras de custo | PARCIAL — sem aceite E2E | Data de início de faturamento independente do dia 1 e período que termina no dia anterior ao próximo ciclo. |
| NX-158 | Financeiro: contratos e regras de custo | NÃO COMPROVADO | Vigência determinada/indeterminada, vencimento e alertas de fim de contrato. |
| NX-159 | Financeiro: contratos e regras de custo | NÃO COMPROVADO | Contrato-padrão por cliente para inclusão automática de impressoras. |
| NX-160 | Financeiro: contratos e regras de custo | NÃO COMPROVADO | Tabelas de custo reutilizáveis para produção P&B, colorida e digitalizações. |
| NX-161 | Financeiro: contratos e regras de custo | NÃO COMPROVADO | CPP (custo por página) parametrizável, inclusive valores zero e discriminação por tipo de produção. |
| NX-162 | Financeiro: contratos e regras de custo | NÃO COMPROVADO | Franquia com quantidade, valor base e excedente, compartilhada entre impressoras/tipos ou independente. |
| NX-163 | Financeiro: contratos e regras de custo | NÃO COMPROVADO | Faixas de volume com preços fixos/por página, excedentes e agrupamento compartilhado opcional. |
| NX-164 | Financeiro: contratos e regras de custo | NÃO COMPROVADO | Custo fixo por impressora, por dispositivo e custos adicionais do contrato. |
| NX-165 | Financeiro: contratos e regras de custo | NÃO COMPROVADO | Mensalidade mínima aplicada à produção de impressoras, com adição posterior de custos fixos/adicionais. |
| NX-166 | Financeiro: contratos e regras de custo | NÃO COMPROVADO | Custos-padrão em novos equipamentos e custos individualizados por impressora/contador. |
| NX-167 | Financeiro: contratos e regras de custo | NÃO COMPROVADO | Cálculo proporcional ou integral para equipamentos parcialmente presentes no período, com confirmação quando aplicável. |
| NX-168 | Financeiro: contratos e regras de custo | NÃO COMPROVADO | Reajuste imediato ou programado, preview comparativo, histórico de valores anteriores e posteriores. |
| NX-169 | Financeiro: contratos e regras de custo | NÃO COMPROVADO | Indicação dos reajustes no relatório de fechamento e aviso de quais períodos abertos sofrerão alterações. |
| NX-170 | Financeiro: contratos e regras de custo | NÃO COMPROVADO | Consolidação de produção sem dupla cobrança em contadores sobrepostos. |
| NX-171 | Financeiro: contratos e regras de custo | NÃO COMPROVADO | Datas, timezone, regras monetárias com decimal exato e versão de cálculo. |
| NX-172 | Financeiro: contratos e regras de custo | NÃO COMPROVADO | Auditoria de alterações contratuais e impedimento de edição retroativa de congelados. |
| NX-173 | Financeiro: fechamento e faturamento | NÃO COMPROVADO | Fechamentos em linha do tempo com totais, status e quantidade por ciclo. |
| NX-174 | Financeiro: fechamento e faturamento | NÃO COMPROVADO | Estados: em processamento, processado e congelado, além de lista independente de pendências. |
| NX-175 | Financeiro: fechamento e faturamento | NÃO COMPROVADO | Congelamento automático condicionado à elegibilidade e sequência de fechamentos anteriores. |
| NX-176 | Financeiro: fechamento e faturamento | NÃO COMPROVADO | Pendência por equipamento sem contrato, contrato sem custos ou leitura manual ausente. |
| NX-177 | Financeiro: fechamento e faturamento | NÃO COMPROVADO | Pendência por equipamento offline além da tolerância em dias úteis e passagem parcial pelo cliente. |
| NX-178 | Financeiro: fechamento e faturamento | NÃO COMPROVADO | Pendência por dispositivo movido no ciclo, com decisão registrada de cobrança total/proporcional. |
| NX-179 | Financeiro: fechamento e faturamento | NÃO COMPROVADO | Prévia de produção e custo por impressora, dispositivo e custo adicional. |
| NX-180 | Financeiro: fechamento e faturamento | NÃO COMPROVADO | Detalhamento de custo, franquia, excedentes, mínimos e descontos. |
| NX-181 | Financeiro: fechamento e faturamento | NÃO COMPROVADO | Acréscimos, descontos e observações no fechamento com motivo/autor. |
| NX-182 | Financeiro: fechamento e faturamento | NÃO COMPROVADO | Congelamento antecipado: absorver dias restantes no próximo ciclo ou criar ciclo complementar. |
| NX-183 | Financeiro: fechamento e faturamento | NÃO COMPROVADO | Snapshot imutável do cálculo e das leituras, com número do documento e versão da regra. |
| NX-184 | Financeiro: fechamento e faturamento | NÃO COMPROVADO | Descongelamento restrito ao mais recente e dentro de política definida, com auditoria e recálculo controlado. |
| NX-185 | Financeiro: fechamento e faturamento | NÃO COMPROVADO | Bloqueio de edição de leituras ou valores usados em fechamento congelado. |
| NX-186 | Financeiro: fechamento e faturamento | NÃO COMPROVADO | Personalização de título, campos, colunas, rodapé e detalhamento por linha no documento. |
| NX-187 | Financeiro: fechamento e faturamento | NÃO COMPROVADO | Exportação PDF/Excel/CSV, impressão e envio automático de fechamento congelado. |
| NX-188 | Financeiro: fechamento e faturamento | NÃO COMPROVADO | Configuração de destinatários por contrato e mecanismos para evitar e-mails duplicados. |
| NX-189 | Financeiro: fechamento e faturamento | NÃO COMPROVADO | Dashboard financeiro com contratos por vencer, pendências, receita, custos e comparativo histórico. |
| NX-190 | Financeiro: fechamento e faturamento | NÃO COMPROVADO | Motor de apuração executável por lote, idempotente, com transações, filas e testes de arredondamento. |
| NX-191 | Financeiro: fechamento e faturamento | NÃO COMPROVADO | Conciliação com ERP e emissão documental/fiscal apenas quando integração correspondente estiver habilitada. |
| NX-192 | Relatórios, filtros, gráficos e painéis | NÃO COMPROVADO | Dashboard com clientes, impressoras, alertas ativos, trocas, chamados, contratos, produção e ranking de clientes. |
| NX-193 | Relatórios, filtros, gráficos e painéis | NÃO COMPROVADO | Indicadores do parque online/offline/sem coleta, distribuição por fabricante/modelo e comparativo por período. |
| NX-194 | Relatórios, filtros, gráficos e painéis | NÃO COMPROVADO | Produção diária/mensal e ranking de impressoras mais usadas nos últimos 30 dias. |
| NX-195 | Relatórios, filtros, gráficos e painéis | NÃO COMPROVADO | Relatório impressões/cópias por impressora com separação por contrato, unidade e departamento. |
| NX-196 | Relatórios, filtros, gráficos e painéis | NÃO COMPROVADO | Relatório de digitalizações por impressora e totais por cliente. |
| NX-197 | Relatórios, filtros, gráficos e painéis | NÃO COMPROVADO | Históricos de nível de suprimento, troca/eficiência, estoque, reposições e custo. |
| NX-198 | Relatórios, filtros, gráficos e painéis | NÃO COMPROVADO | Relatório de chamados, SLA, técnico, motivos, peças aplicadas e valores de atendimento. |
| NX-199 | Relatórios, filtros, gráficos e painéis | NÃO COMPROVADO | Relatórios de contrato/fechamento e pendências de cobrança. |
| NX-200 | Relatórios, filtros, gráficos e painéis | NÃO COMPROVADO | Filtros avançados com condições combinadas, colunas customizadas, ordenação e visões salvas. |
| NX-201 | Relatórios, filtros, gráficos e painéis | NÃO COMPROVADO | Exportação confiável em CSV, Excel e PDF com formatos brasileiros e data/hora da última leitura. |
| NX-202 | Relatórios, filtros, gráficos e painéis | NÃO COMPROVADO | Agendamento de relatórios por e-mail, gráficos opcionais e agrupamento por unidade/departamento. |
| NX-203 | Relatórios, filtros, gráficos e painéis | NÃO COMPROVADO | Resumo por e-mail dos fechamentos quando iniciado novo ciclo. |
| NX-204 | Relatórios, filtros, gráficos e painéis | NÃO COMPROVADO | Relatórios rastreáveis: critérios, data de geração, timezone, autor e versão dos dados. |
| NX-205 | Relatórios, filtros, gráficos e painéis | NÃO COMPROVADO | Acessibilidade de gráficos com alternativa tabular. |
| NX-206 | Notificações e comunicação | NÃO COMPROVADO | Notificações in-app com histórico, não lidas, preferências e agrupamento por prioridade. |
| NX-207 | Notificações e comunicação | NÃO COMPROVADO | Gatilhos por chamados, comentários, prazo SLA, alterações e novos alertas. |
| NX-208 | Notificações e comunicação | NÃO COMPROVADO | Gatilhos por vencimento de contrato, queda de estoque e risco de ruptura. |
| NX-209 | Notificações e comunicação | NÃO COMPROVADO | Gatilhos por nível informado/estimado de suprimento e data prevista de troca. |
| NX-210 | Notificações e comunicação | NÃO COMPROVADO | Gatilhos por troca pendente, troca prematura e novas impressoras descobertas. |
| NX-211 | Notificações e comunicação | NÃO COMPROVADO | Regras por cliente/unidade, evento, criticidade, usuário/grupo e canal. |
| NX-212 | Notificações e comunicação | NÃO COMPROVADO | Envio por e-mail com templates próprios, reputação de envio, retry e rastreamento de falhas. |
| NX-213 | Notificações e comunicação | NÃO COMPROVADO | Supressão de duplicatas, digest, horário silencioso e prevenção de tempestade de alertas. |
| NX-214 | Notificações e comunicação | NÃO COMPROVADO | Fila de notificações e registro de entrega, com painel de falhas. |
| NX-215 | API, webhooks e integrações | NÃO COMPROVADO | API REST versionada para clientes, filiais, impressoras, contadores, suprimentos, estoques, chamados, alertas, contratos e fechamentos. |
| NX-216 | API, webhooks e integrações | NÃO COMPROVADO | Leitura e escrita autorizada por escopos; publicar OpenAPI e exemplos de integração. |
| NX-217 | API, webhooks e integrações | NÃO COMPROVADO | Tokens de integração com escopo, hash no banco, prazo/rotação/revogação e histórico de uso. |
| NX-218 | API, webhooks e integrações | NÃO COMPROVADO | Paginação, filtros, ordenação, pesquisa e limites configuráveis com mensagens consistentes. |
| NX-219 | API, webhooks e integrações | NÃO COMPROVADO | Idempotency-Key para operações de escrita e importações, evitando duplicação. |
| NX-220 | API, webhooks e integrações | NÃO COMPROVADO | Webhooks assinados para chamadas/eventos, retries exponenciais, replay e log de entregas. |
| NX-221 | API, webhooks e integrações | NÃO COMPROVADO | Mapeamento de códigos de cliente, produto, unidade, contrato e centro de custo com sistemas externos. |
| NX-222 | API, webhooks e integrações | NÃO COMPROVADO | Exportação/consumo por ERP, serviços web e BI, preservando tenant e autorização. |
| NX-223 | API, webhooks e integrações | NÃO COMPROVADO | Importação bilateral de dados somente com governança por registro e definição de sistema de origem. |
| NX-224 | API, webhooks e integrações | NÃO COMPROVADO | Autorização explícita para integração com outras plataformas MPS, sem acesso ou cópia indevida de APIs privadas. |
| NX-225 | API, webhooks e integrações | NÃO COMPROVADO | Versão de esquema, documentação de erros e política de descontinuação de endpoints. |
| NX-226 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Cadastro de provedores, isolamento dos ambientes e parâmetros de implantação self-hosted/SaaS. |
| NX-227 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Planos/módulos ativáveis, licenças de impressoras ativas e histórico de uso, se Nexa for comercializado. |
| NX-228 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Faturamento da assinatura do provedor separado dos fechamentos cobrados dos clientes do provedor. |
| NX-229 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Central de faturas, status em dia/em atraso, documentos e permissões do financeiro, se houver serviço de assinatura. |
| NX-230 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Política de cobrança, suspensão e reativação sem perda indevida de dados, caso o produto seja SaaS pago. |
| NX-231 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Logs, métricas, tracing, saúde dos serviços, filas, jobs, backups e alertas de falhas operacionais. |
| NX-232 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Atualização, migração de schema, restauração e rollback testados. |
| NX-233 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Ambientes de desenvolvimento, homologação e produção segregados. |
| NX-234 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Administração de idiomas PT-BR e internacionalização futura, formatos de moeda e fuso. |
| NX-235 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Política de suporte, documentação de instalação de agente e catálogo de modelos testados. |
| NX-236 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Topbar com ajuda, novidades, notificações, perfil, recuperação de acesso, política, downloads do coletor e saída. |
| NX-237 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Listas com ações habilitadas pela seleção, pesquisa em todas as colunas, inativos ocultáveis, personalização e Excel. |
| NX-238 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Atalhos clicáveis para entidade associada (ex.: impressora para contrato) com autorização e preservação de contexto. |
| NX-239 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Tooltip de orientação em campo complexo e gráficos com controle para ocultar/mostrar cada série. |
| NX-240 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Visões salvas e temporárias dos filtros avançados, com ordenação customizada de colunas e rearranjo por arraste. |
| NX-241 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Mostrar a idade das informações nas telas; atualização periódica não pode ser descrita como instantânea. |
| NX-242 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Menus e botões baseados no par módulo contratado × autorização efetiva de usuário, incluindo invalidação de cache. |
| NX-243 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Configuração do logotipo com prévia, dimensões/tipos de imagem validados e aplicação opcional aos relatórios. |
| NX-244 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Cabeçalho dos relatórios com dados fiscais/configuráveis do provedor e possibilidade de omiti-los. |
| NX-245 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Unidades do provedor associáveis aos contratos com CNPJs diferentes e histórico da unidade emissora. |
| NX-246 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Departamentos internos do provedor para impressoras devolvidas ao estoque, separados dos departamentos do cliente. |
| NX-247 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Horários comerciais do provedor utilizados em alertas e SLA, incluindo feriados e fuso. |
| NX-248 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Manter impressora desativada no contrato até desvinculação manual conforme parâmetro, sem faturar silenciosamente. |
| NX-249 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Controle global para permitir alteração de número de documento ainda aberto apenas a usuários financeiros autorizados. |
| NX-250 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Aplicação local administrativa com Impressoras, Ferramentas, Estado dos Serviços e Configurações. |
| NX-251 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Teste de IP e credenciais SNMP/HTTP mostrando identidade/contadores obtidos antes de ativar monitoramento. |
| NX-252 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Descoberta manual de sub-rede local/remota por broadcast ou intervalo com seleção múltipla e resumo de falhas. |
| NX-253 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Busca automática por intervalos autorizados com frequência de descoberta configurável entre 6 e 168 horas. |
| NX-254 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Cadastro remoto de impressora por IP vinculado ao ponto de instalação com aguardando descoberta e validação. |
| NX-255 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Estados e telemetria separados do coletor, upload de dados, atualização e supervisor. |
| NX-256 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Coleta offline com pacotes cifrados e compactados na origem, integridade e eliminação após confirmação no servidor. |
| NX-257 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Monitoramento de conexão com detalhes por ponto, número de impressoras, serviços, erros e sugestões de diagnóstico. |
| NX-258 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Captura USB ponto a ponto: host obrigatoriamente presente, correção de identificação/porta/driver e teste real por modelo. |
| NX-259 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Capacidades separadas de rede e USB; não inferir leituras coloridas/níveis quando só houver contador geral. |
| NX-260 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Cadência dos diferentes serviços configurável: documentação pública diverge entre 30 e 60 minutos; exibir última amostra real. |
| NX-261 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Diagnósticos para firewall, DNS, proxy, credenciais, IP alterado, troca de placa, antivírus e serviço parado. |
| NX-262 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Transportes HTTPS e proxy com autenticação; alteração posterior de configuração sem reinstalar. |
| NX-263 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Transporte alternativo seguro por SMTP/POP3 com teste de comunicação e validação de origem quando habilitado. |
| NX-264 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Revogação de ponto instalado permanentemente offline com reconciliação dos dispositivos do ponto. |
| NX-265 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Não capturar spool, conteúdo ou imagem de documentos; somente metadados necessários para monitoramento. |
| NX-266 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Eventos e alarmes próprios para falha do serviço de atualização, distintos de agente offline. |
| NX-267 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Edição manual somente do último contador e durante janela configurável equivalente à referência de 30 dias. |
| NX-268 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Vedação de edição de contadores associados a fechamento congelado, exceto processo formal de descongelamento. |
| NX-269 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Preservar segmentos de medição antes/depois de reset de contador ou formatter sem duplicar produção. |
| NX-270 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Modelo de contador com Geral, Geral P&B, Geral Cor Total, Cor Única e 2 Cores, com fórmula por capacidade de modelo. |
| NX-271 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Modelo não homologado deve permitir cadastro e contador manual mas declarar ausência de telemetria não testada. |
| NX-272 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Série obtida por USB precisa de validação humana quando driver/SO reportar informação possivelmente persistida. |
| NX-273 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Painel da impressora deve mostrar último horário de leitura, condição, contadores e suprimentos com precisão por modelo. |
| NX-274 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Não calcular data de próxima troca nem reposição preditiva sem duas trocas válidas confirmadas por equipamento/suprimento. |
| NX-275 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Sugestão de troca documenta motivo exato (nível, capacidade, número de série ou informe por usuário cliente/colaborador). |
| NX-276 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Troca manual deve reconciliar sugestão pendente da mesma ocorrência e evitar dupla baixa de estoque. |
| NX-277 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Automação de troca limitada ao estoque da localização, compatibilidade e SKU previamente usado sem ambiguidade. |
| NX-278 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Impedir auto-confirmação se houver pendências de troca mais antigas em aberto para a impressora. |
| NX-279 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Trocas confirmadas podem ser excluídas com auditoria ou desconsideradas das médias, permitindo reabilitar média sem perda histórica. |
| NX-280 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Trocas prematuras visualizáveis em recortes 30/60/90 dias com data e nível de entrada/saída. |
| NX-281 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Rendimento calculado para SKU e impressora usando páginas entre trocas e rendimento nominal. |
| NX-282 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Cobertura calculada como estimativa inferida de hipótese nominal (ex.: 5% padrão), nunca telemetria medida. |
| NX-283 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Validação manual/algorítmica de trocas espúrias; IA opcional futura deve ser explicável, aferível e sem dependência obrigatória. |
| NX-284 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Permitir cadastrar tipos, marcas, fornecedores e compatibilidades; bloquear exclusão de cadastro com histórico dependente. |
| NX-285 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | SKU externo, cor, rendimento por compatibilidade, preço de venda e fornecedor associados a estoque e atendimentos. |
| NX-286 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Entrada múltipla para estoque do provedor ou cliente, com nota fiscal e origem documental. |
| NX-287 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Definir explicitamente compatibilidade ao criar SKU; vazio não pode significar todos os modelos inadvertidamente. |
| NX-288 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Permitir avaliação de troca prematura por nível informado, estimativa ou eficiência e limiar por tipo. |
| NX-289 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Falha de comunicação só gera alerta após carência configurada, com recuperação por três leituras estáveis como referência. |
| NX-290 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Erro do equipamento pode ser ignorado e reaparecer; encerramento automático após janela configurada (referência: 4h). |
| NX-291 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Ao encerrar chamado vinculado, preventiva encerrada e alerta de hardware ignorado/resolvido conforme tipo. |
| NX-292 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Regras com múltiplos gatilhos emitem eventos rastreáveis individualmente para dias, páginas e níveis. |
| NX-293 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Alertas preventivos com reinício em data real da manutenção, impedindo mudança retroativa injustificada de política. |
| NX-294 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Regras de preventiva aplicam-se também a novas impressoras incluídas posteriormente no conjunto de filtros. |
| NX-295 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Catálogos de erros de rede/USB com criticidade configurável e desabilitação explícita de ocorrência inútil. |
| NX-296 | Operação da plataforma, SaaS e cobrança do provedor | PARCIAL — API/portal inicial; QA E2E limitado | Abertura pelo equipamento ou lista geral; cliente pode selecionar impressora e/ou dispositivo e colaborador abrir tarefa interna. |
| NX-297 | Operação da plataforma, SaaS e cobrança do provedor | PARCIAL — API/portal inicial; QA E2E limitado | Fila sem responsável e quadro ativo com três estados: atendimento pendente, em atendimento e atendimento finalizado. |
| NX-298 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Item usado em chamado exige origem (provedor/técnico), destino (cliente/impressora) e opção de cobrança. |
| NX-299 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Encerramento exige confirmação dos materiais usados, eventuais pagamentos, descontos e custos adicionais. |
| NX-300 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Impressão da ordem de serviço com quantidade configurável de linhas em branco para escrita em campo. |
| NX-301 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Tipos de chamado e serviços associados não podem ser excluídos destrutivamente enquanto existir histórico. |
| NX-302 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Contrato-padrão único por cliente sob concorrência, com vínculo automático apenas onde autorizado. |
| NX-303 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Local fiscal do fechamento limitada às localizações habilitadas e CNPJs aplicáveis ao contrato. |
| NX-304 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Impressoras do cliente com propriedade distinta não podem gerar pendência automática indevida. |
| NX-305 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Fechamento automático depende de ausência de pendências e de congelamento de todos os ciclos anteriores. |
| NX-306 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Permitir congelamento manual mesmo com pendência apenas mediante permissão especial e justificativa. |
| NX-307 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Descongelar somente o fechamento congelado mais recente por cliente, dentro de janela equivalente à referência 30 dias. |
| NX-308 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Fechamento congelado preserva snapshot de custos, contadores e regras mesmo após reajuste de contrato. |
| NX-309 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Documentos personalizados: título, colunas, texto adicional, dados fiscais e logotipo. |
| NX-310 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Envio automático do PDF/relatório apenas no primeiro congelamento, com deduplicação em reprocessamentos. |
| NX-311 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Cobrança parcial ou integral selecionável e auditada para dispositivos e impressoras em passagem parcial. |
| NX-312 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Reajustes com mês/ano, prévia, histórico, percentual e impacto explícito nos fechamentos ainda abertos. |
| NX-313 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Documento de cobrança numerado com permissão para edição somente antes de congelamento. |
| NX-314 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Receita provisória de ciclos em andamento separada de faturamento congelado. |
| NX-315 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Relatórios agendados para digitalizações e impressões/cópias usando janela de 30 dias e gráficos opcionais. |
| NX-316 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Resumo do ciclo financeiro por e-mail quando um novo fechamento começa, com destinatários por cliente. |
| NX-317 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Notificar níveis lidos ou estimados, trocas futuras, troca pendente/prematura, estoque baixo no cliente e no provedor. |
| NX-318 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Token de integração com permissão de administrador, revogação e confirmação, escopos e auditoria. |
| NX-319 | Operação da plataforma, SaaS e cobrança do provedor | PARCIAL — sem aceite E2E | API pública versionada com read-only em domínios e entrada autorizada de clientes/estoques. |
| NX-320 | Operação da plataforma, SaaS e cobrança do provedor | PARCIAL — sem aceite E2E | Paginação e ordenação estável; documentar diferenças entre API Nexa e referência que usa até 100 itens e Skip. |
| NX-321 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Relatório de leituras explicita data de coleta e nunca apresenta consumo zero para dados desatualizados. |
| NX-322 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Caso Nexa tenha plano SaaS, tarifação por impressora/módulo e piso, sem cobrança duplicada quando muda de cliente. |
| NX-323 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Central de assinatura opcional com status, faturas, boleto/Pix via parceiro autorizado, lembretes e reativação. |
| NX-324 | Operação da plataforma, SaaS e cobrança do provedor | NÃO COMPROVADO | Catálogo de incidentes de campo e checklist de homologação de modelos/firmwares e protocolos. |

## QA — critérios documentados (62)

| Caso | Estado | Critério original (trecho do README) |
|---|---|---|
| QA-01 | SEM ACEITE INTEGRAL | QA-01 / Cadastro de cliente, filial, departamento e centro de custo / Acesso e filtros respeitam os limites da conta / |
| QA-02 | SEM ACEITE INTEGRAL | QA-02 / Instalação/registro do agente em uma unidade / Coletor vinculado à chave correta, visível e revogável / |
| QA-03 | SEM ACEITE INTEGRAL | QA-03 / Descoberta por IP e varredura autorizada / Impressoras encontradas aguardam aprovação; sem scans fora do escopo / |
| QA-04 | SEM ACEITE INTEGRAL | QA-04 / Coleta SNMP e USB em modelos homologados / Contadores e capacidades reais, data de coleta e origem / |
| QA-05 | SEM ACEITE INTEGRAL | QA-05 / Agente sem internet por 24h / Buffer local, retransmissão e 0 leituras duplicadas / |
| QA-06 | SEM ACEITE INTEGRAL | QA-06 / SNMP indisponível, IP trocado e agente offline / Estados e alertas diferentes, sem inventar produção zero / |
| QA-07 | SEM ACEITE INTEGRAL | QA-07 / Dois agentes detectam a mesma impressora / Sem cobrança dupla; conflito é resolvível / |
| QA-08 | SEM ACEITE INTEGRAL | QA-08 / Troca de hardware e reset de contadores / Produção anterior preservada, evento e ajuste auditado / |
| QA-09 | SEM ACEITE INTEGRAL | QA-09 / Impressora removida no meio do ciclo / Passagem encerrada na data correta; cálculo parcial / |
| QA-10 | SEM ACEITE INTEGRAL | QA-10 / Equipamento reserva desligado / Sem alerta inadequado de ausência de comunicação / |
| QA-11 | SEM ACEITE INTEGRAL | QA-11 / Impressora sem telemetria com contador manual / Dados aceitos, validáveis e com responsável / |
| QA-12 | SEM ACEITE INTEGRAL | QA-12 / Nível de suprimento oscila sem troca física / Não gera baixa de estoque automaticamente sem regras / |
| QA-13 | SEM ACEITE INTEGRAL | QA-13 / Troca real com estoque do técnico / Registro de troca e baixa única do saldo correto / |
| QA-14 | SEM ACEITE INTEGRAL | QA-14 / Duas pessoas tentam retirar último item / Uma transação aprova e outra recebe erro correto / |
| QA-15 | SEM ACEITE INTEGRAL | QA-15 / Reposição com envio parcial e recebimento parcial / Saldos de origem, trânsito e destino consistentes / |
| QA-16 | SEM ACEITE INTEGRAL | QA-16 / Preventiva por dias/páginas/nível / Alertas sem duplicidade e reinício correto após execução / |
| QA-17 | SEM ACEITE INTEGRAL | QA-17 / Chamado do cliente com mensagem interna / Cliente jamais vê conteúdo interno / |
| QA-18 | SEM ACEITE INTEGRAL | QA-18 / SLA em horário comercial e feriado / Prazo calculado no servidor com calendário correto / |
| QA-19 | SEM ACEITE INTEGRAL | QA-19 / Encerrar chamado com peça e serviço / Estoque/valor/alerta atualizados uma vez / |
| QA-20 | SEM ACEITE INTEGRAL | QA-20 / Franquia compartilhada entre dois dispositivos / Limite e excedente aplicados uma única vez / |
| QA-21 | SEM ACEITE INTEGRAL | QA-21 / CPP P&B/cor/digitalização e mínimo mensal / Totais com precisão decimal e regras verificadas / |
| QA-22 | SEM ACEITE INTEGRAL | QA-22 / Contrato iniciando dia 15, fevereiro e ano bissexto / Período correto, inclusive mudança de horário/fuso / |
| QA-23 | SEM ACEITE INTEGRAL | QA-23 / Dispositivo instalado apenas parte do mês / Opção integral/proporcional registrada / |
| QA-24 | SEM ACEITE INTEGRAL | QA-24 / Fechamento com pendências e tentativa automática / Bloqueado com motivos verificáveis / |
| QA-25 | SEM ACEITE INTEGRAL | QA-25 / Congelar, enviar, reabrir sob autorização, recongelar / Snapshot/histórico preservados; nenhum envio duplicado / |
| QA-26 | SEM ACEITE INTEGRAL | QA-26 / Reajuste programado em contrato / Simulação, aprovação, aplicação e auditoria corretas / |
| QA-27 | SEM ACEITE INTEGRAL | QA-27 / Relatórios e planilhas grandes / Paginação, processamento, exportação e colunas corretos / |
| QA-28 | SEM ACEITE INTEGRAL | QA-28 / API token revogado / webhook duplicado / Bloqueio imediato / idempotência / |
| QA-29 | SEM ACEITE INTEGRAL | QA-29 / Usuário tenta alterar tenant_id na API / 403/404; nenhum vazamento ou modificação cruzada / |
| QA-30 | SEM ACEITE INTEGRAL | QA-30 / Restauração de backup em ambiente de homologação / Integridade de leitura, estoque e fechamento verificada / |
| QA-31 | SEM ACEITE INTEGRAL | QA-31 / Navegação do cliente por celular / Operação real sem telas quebradas e com acessibilidade / |
| QA-32 | SEM ACEITE INTEGRAL | QA-32 / Implantação em ambiente vazio / Migrações, onboarding e primeiros dados operam sem mocks / |
| QA-33 | SEM ACEITE INTEGRAL | QA-33 / Descoberta agendada e busca manual respeitam faixas autorizadas e retornam relatório por equipamento / |
| QA-34 | SEM ACEITE INTEGRAL | QA-34 / Teste de SNMP/HTTP apresenta somente os contadores efetivamente coletados / |
| QA-35 | SEM ACEITE INTEGRAL | QA-35 / USB alterado de porta ou com série inválida não causa vínculo entre clientes / |
| QA-36 | SEM ACEITE INTEGRAL | QA-36 / Falha de DNS/firewall/proxy/atualizador sinaliza diagnóstico distinto / |
| QA-37 | SEM ACEITE INTEGRAL | QA-37 / Envio após 24h sem internet preserva ordem, integridade e não duplica leituras / |
| QA-38 | SEM ACEITE INTEGRAL | QA-38 / Correção de último contador após 30 dias é bloqueada segundo configuração / |
| QA-39 | SEM ACEITE INTEGRAL | QA-39 / Reset de contador divide o histórico sem valores negativos nem dupla cobrança / |
| QA-40 | SEM ACEITE INTEGRAL | QA-40 / Combinação de contadores Geral Cor, Cor Única e 2 Cores segue fórmula homologada / |
| QA-41 | SEM ACEITE INTEGRAL | QA-41 / Previsão com menos de duas trocas confirmadas retorna amostra insuficiente / |
| QA-42 | SEM ACEITE INTEGRAL | QA-42 / Troca manual sobre pendente resulta em evento único / |
| QA-43 | SEM ACEITE INTEGRAL | QA-43 / Confirmação automática com SKU incompatível, ambíguo ou sem estoque é rejeitada / |
| QA-44 | SEM ACEITE INTEGRAL | QA-44 / Desconsiderar troca falsa recalcula média e mantém trilha de auditoria / |
| QA-45 | SEM ACEITE INTEGRAL | QA-45 / Estoque no técnico e no cliente é afetado apenas pela movimentação/transação correta / |
| QA-46 | SEM ACEITE INTEGRAL | QA-46 / Erro da impressora recupera, é ignorado ou reaparece conforme parametrização / |
| QA-47 | SEM ACEITE INTEGRAL | QA-47 / Alerta sem comunicação encerra apenas depois de estabilidade conforme política / |
| QA-48 | SEM ACEITE INTEGRAL | QA-48 / Vários gatilhos de preventiva são correlacionados sem perda de origem / |
| QA-49 | SEM ACEITE INTEGRAL | QA-49 / Chamado com material para cliente versus instalação no equipamento mantém saldo e cobrança / |
| QA-50 | SEM ACEITE INTEGRAL | QA-50 / Contrato tenta definir dois padrões para mesmo cliente em concorrência: rejeita um / |
| QA-51 | SEM ACEITE INTEGRAL | QA-51 / Congelamento automático não ultrapassa pendências ou ciclos antigos não congelados / |
| QA-52 | SEM ACEITE INTEGRAL | QA-52 / Congelamento manual ignorando pendência exige permissão, motivação e audit trail / |
| QA-53 | SEM ACEITE INTEGRAL | QA-53 / Descongelamento fora da janela ou diferente do mais recente é rejeitado / |
| QA-54 | SEM ACEITE INTEGRAL | QA-54 / Reajuste do contrato não modifica snapshot congelado e mostra períodos afetados / |
| QA-55 | SEM ACEITE INTEGRAL | QA-55 / Relatório enviado no primeiro congelamento não é reenviado involuntariamente no recongelamento / |
| QA-56 | SEM ACEITE INTEGRAL | QA-56 / Movimentação parcial no ciclo exige decisão integral/proporcional explícita / |
| QA-57 | SEM ACEITE INTEGRAL | QA-57 / Relatórios de produção indicam última coleta e desatualização / |
| QA-58 | SEM ACEITE INTEGRAL | QA-58 / Portal cliente nunca revela relatório/estoque de outro cliente / |
| QA-59 | SEM ACEITE INTEGRAL | QA-59 / API paginada mantém ordem/limites e rejeita token revogado / |
| QA-60 | SEM ACEITE INTEGRAL | QA-60 / Plano SaaS opcional cobra impressora uma só vez mesmo após troca de cliente / |
| QA-61 | SEM ACEITE INTEGRAL | QA-61 / Agenda de relatório respeita destinatário, janela, formato e autorização / |
| QA-62 | SEM ACEITE INTEGRAL | QA-62 / Teste de privacidade garante nenhuma captura do conteúdo ou imagem de documentos / |

## Ordem obrigatória de implementação/segurança

1. Segregação por carteira e por recurso além do tenant, incluindo testes de negação e auditoria.
2. Inventário patrimonial / responsabilidades / contratos temporais, com rastreabilidade.
3. Suprimentos, estoque e ledger transacional, com bloqueio de saldo negativo e integração com chamados.
4. Engine de alertas com deduplicação e SLA/expediente.
5. Contratos, ciclos de medição, reconciliação financeira decimal e congelamento imutável.
6. Relatórios, integrações, API pública limitada por scopes, logs e retenção LGPD.
7. Homologação do Collector em OS e modelos reais, testes E2E, segurança, backup/restore, DR e performance.

**Proibição de conclusão fictícia:** somente atualizar linha para APROVADO com commit, teste, ambiente, artefato do CI e prova E2E correspondente.

## Evidências incrementais de estoque e remessas (13/10/2026)

A classificação `PARCIAL` acima NÃO significa aceitação integral. Esta tabela foi atualizada somente para os itens cujo backend e parte da interface passaram na suíte de integração.

- `NX-078`: item com SKU, categoria, unidade e escopo multiempresa. Ainda sem preço, rendimento e compatibilidade.
- `NX-098/100/101/102`: local provedor/técnico/unidade, razão transacional, saldos físicos, saldo reservado, disponível, saídas seguras e UUID idempotente. Sem reservas por chamado, testes de corrida interprocessos e integridade financeira.
- `NX-108/110`: remessas, expedição parcial, recebimento parcial, encerramento com cancelamento e registro explícito de perdas. Sem aprovação do destinatário, transportadoras, documentos, reenvio/retorno ou execução fiscal.
- `NX-111`: rastreabilidade por localização, ator, data e remessa. Ainda não integra o registro de instalação por impressora nem operação do cliente.
- **Evidência automatizada:** [Nexa validation #38420512354](https://github.com/gabriell211/Nexa-System/actions/runs/38420512354), 61 testes Laravel e 442 asserts em SQLite e PostgreSQL 17, Rust, React/TypeScript, Docker/Nginx, verificação README e smoke HTTP de reserva/expedição/recebimento/perda.
- **Falta para APROVADO:** execução E2E em navegador real, teste concorrente multiprocesso, homologação com operadores e clientes, demais subrequisitos originais, regras de lote/expiração e provas de instalação.

## Prova de concorrência com banco real (13/10/2026)

O caso `NX-102` continua PARCIAL, mas ganhou evidência adicional além de testes sequenciais:
[CI PostgreSQL #38421263725](https://github.com/gabriell211/Nexa-System/actions/runs/38421263725)
executou dois processos PHP com conexões separadas na mesma instância PostgreSQL 17; a disputa pela última unidade produziu exatamente uma reserva e uma recusa e, após cancelamento, exatamente uma baixa e uma recusa. O teste também provou que uma saída direta não consome unidade reservada.

Essa prova não valida reservas vinculadas a chamados, cluster PostgreSQL, outros modos de isolamento, múltiplos depósitos em alta escala, nem compatibilidade com impressoras físicas.

## Suprimentos, compatibilidade e recepção fiscal (2026-10-13)

- `NX-078`: SKU enriquecido com marca, cor, rendimento nominal, custo e preço exatos em centavos; mapeamento explícito fabricante/modelo. Compatibilidade desconhecida não é falsamente confirmada.
- `NX-099`: fornecedor escopado ao tenant, documento, lote, validade e custo registrados num recebimento atômico vinculado ao razão de estoque.
- Não existe rastreio por lote nas movimentações posteriores, FIFO/FEFO, importação NF-e, item serializado nem troca confirmada por equipamento. Logo, o aceite integral **não** está alcançado.
- Testes de banco, API e sessão HTTP são condição necessária; não substituem teste de operação de almoxarifado e qualidade de documentação fiscal.

## Progresso de lote e FEFO (13/10/2026)

- FEFO agora identifica lotes por recebimento, registra alocações do movimento e conserva posições rastreadas por local. Os registros legados permanecem sem proveniência comprovada.
- Remessas mantêm vínculo contábil dos lotes enviados, recebidos e perdidos. Atribuição parcial por FEFO é uma **inferência operacional** até existir leitura física do lote.
- Saldos `available_quantity` excluem estoque conhecido vencido, sem diminuir o saldo físico; `expired_quantity` é apresentado separadamente.
- `NX-099/102/103`: avanço técnico PARCIAL. Aceite exige reconciliação físico-contábil, reserva por lote, confirmação de picking/recebimento e homologação operacional em campo.

## Chamados e testes em navegador real (13/10/2026)

- `NX-127/128/131/132/133/134/296/297`: implementação inicial de abertura backoffice, tarefa interna, fila, estados, atribuição, comentários e auditoria, todos **PARCIAIS**. Não há portal do cliente, anexos, serviços, SLA completo nem ligações a alertas/dispositivos.
- A matriz mantém `QA-17` como não comprovado: comentários internos são persistidos para operadores, mas ainda não há portal de cliente para testar a restrição externa de ponta a ponta.
- Playwright real (Chromium desktop e mobile) foi acrescentado ao GitHub Actions; prova apenas a jornada implementada, distinta de homologação de produção ou dos 62 cenários completos.
- Corrigidas incompatibilidades de busca com `q=` e `active_only=true` e adicionada regressão para ambos os endpoints. Isso foi descoberto pela navegação E2E, não por mocks.
