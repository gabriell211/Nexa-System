# Nexa Collector — estudo do PrintWayy Client e especificação técnica

> **Referência analisada:** PrintWayy Client, segundo a documentação pública oficial consultada em 08/10/2026. **Status Nexa:** primeira versão de código Rust/React/Tauri e API de ingestão adicionada, ainda sem build, testes/hardware homologados, instalador ou serviço operacional em produção. Ver [Collector](../collector/README.md). Este arquivo **não contém** código proprietário nem protocolo privado de terceiros.

## 1. O que foi confirmado na documentação

O PrintWayy Client é instalado em um computador do ambiente do cliente e comunica-se com impressoras pela rede/USB, produzindo informações que serão tratadas pelo portal PrintWayy Dragon. O **portal web e o coletor local são programas diferentes**.

### Telas oficiais da ferramenta Windows

A navegação descrita tem **Impressoras**, **Ferramentas**, **Status do Client** e **Configurações**. Fontes com capturas:

| Tela / tarefa | Referência visual oficial |
| --- | --- |
| Janela inicial do Client | [client-vazio.png](https://help.printwayy.com/wp-content/uploads/2023/01/client-vazio.png) |
| Adicionar por IP, SNMP e credenciais HTTP | [adicionar-impressora.png](https://help.printwayy.com/wp-content/uploads/2023/01/adicionar-impressora.png) |
| Teste/localização de impressora e contadores | [busca-impressora.png](https://help.printwayy.com/wp-content/uploads/2023/01/busca-impressora.png) |
| Ação para iniciar monitoramento | [monitorar-impressora.png](https://help.printwayy.com/wp-content/uploads/2023/01/monitorar-impressora.png) |
| Impressora cadastrada/monitorada | [impressora-monitorada.png](https://help.printwayy.com/wp-content/uploads/2023/01/impressora-monitorada.png) |
| Busca de impressoras na rede | [busca-em-massa.png](https://help.printwayy.com/wp-content/uploads/2023/01/busca-em-massa.png) |
| Sub-redes e modos de busca | [sub-rede.png](https://help.printwayy.com/wp-content/uploads/2023/01/sub-rede.png) · [modo-busca.png](https://help.printwayy.com/wp-content/uploads/2023/01/modo-busca.png) |
| Resumo do cadastro em massa | [salvar-impressoras.png](https://help.printwayy.com/wp-content/uploads/2023/01/salvar-impressoras.png) |
| Detecção USB | [busca-USB.png](https://help.printwayy.com/wp-content/uploads/2023/01/busca-USB.png) |
| Assistente de instalação | [instalador-1a.png](https://help.printwayy.com/wp-content/uploads/2024/12/instalador-1a.png) · [instalador-2.png](https://help.printwayy.com/wp-content/uploads/2024/12/instalador-2.png) · [instalador-3.png](https://help.printwayy.com/wp-content/uploads/2024/12/instalador-3.png) |
| Proxy após instalação | [config_proxy_instalado.png](https://help.printwayy.com/wp-content/uploads/2021/11/config_proxy_instalado.png) |

A documentação descreve duas ações distintas: **Salvar impressoras** (traz à lista local) e **Monitorar** (ativa a coleta/transmissão). Na busca por IP, o software testa a comunicação e exibe os contadores antes de ativar.

### Quatro serviços internos citados pela FAQ

| Serviço publicado | Responsabilidade informada | Implicação para Nexa |
| --- | --- | --- |
| Net Client Service | Consulta impressoras via SNMP e grava dados em arquivos compactados/criptografados `.plog` | Isolar processo de coleta e buffer local persistente |
| Link Service | Observa pacotes locais e os envia ao servidor por HTTPS (porta 443) | Emissor/retry independente do scanner |
| Updater Service | Consulta atualizações aproximadamente a cada 2 horas | Atualizador autenticado, assinado, com rollback |
| Updater Guardian Service | Verifica os demais serviços e busca mantê-los ativos | Health monitor/watchdog sem reinício em loop |

**Não copiar:** nomes dos executáveis e serviços, formato `.plog`, conteúdo/algoritmo privado, endpoints internos ou identidade visual. Nexa precisa de protocolo **próprio** com contratos e testes de interoperabilidade internos.

A referência pública menciona coleta a **30 minutos** na FAQ e a **60 minutos** no tutorial de monitoramento por IP. Não presumir uma única cadência universal: registrar divergência documental e oferecer **intervalos independentes, configuráveis** em Nexa.

### Instalação e identificação

1. O provedor cadastra um cliente no portal e obtém uma chave de instalação daquele cliente.
2. Instala o programa no computador ou servidor da rede de impressão.
3. Escolhe comunicação por internet/HTTPS com proxy opcional ou e-mail (modo alternativo).
4. Apresenta chave ao instalador; valida e confirma o cliente associado.
5. Define diretório e nome do ponto de instalação e conclui.
6. Inicia o programa de administração e adiciona impressoras por IP, busca em rede ou USB.

A página de downloads pública indica dependência de **.NET Framework 4.5.1** para Windows 7 ou superior e **.NET 4.0** para sistemas muito antigos. **Isso descreve a documentação da referência, não os requisitos do Nexa; não reproduzir compatibilidade insegura com Windows XP/Server 2003.** A instalação do Nexa deve ser simples, com serviços Windows modernos e política de sistemas operacionais suportados.

A documentação do Client ensina copiar a pasta **Database** antes de reinstalar para preservar o vínculo com as impressoras. O Nexa deve implementar recuperação/backup do estado local de modo seguro e automatizado, sem depender de cópia manual frágil nem armazenar segredos de forma legível.

### Canais, permissões de rede e protocolos

| Caminho | Referência pública | Requisito proposto |
| --- | --- | --- |
| Rede local para impressoras | SNMP, porta UDP 161; a FAQ também menciona 162 | Suportar consulta UDP 161; traps UDP 162 só quando adotados e explicitamente autorizados |
| Impressora com credenciais adicionais | HTTP/HTTPS e credenciais cadastráveis para modelos que as exigem | Perfis protegidos com testes de credenciais e adaptadores de fabricante |
| Dispositivos USB | Descoberta local, obrigatoriamente instalada no computador que recebe o cabo | Coleta USB local por plataforma/driver e homologação de capacidades |
| Coletor para nuvem | HTTPS 443; Link Service envia pacotes cifrados | Somente saída de HTTPS e confirmação idempotente |
| Ambiente com proxy | Proxy configurado na instalação ou depois com teste de conectividade | HTTP CONNECT autenticado, TLS e diagnósticos |
| Ambiente sem Web Services | Alternativa de comunicação por e-mail SMTP + POP3 com TLS/SSL | Extensão opcional transportável, criptografia da carga, assinatura, antifraude e replay-safe |

No modo alternativo por e-mail, a documentação recomenda caixa exclusiva e alerta para alto volume de mensagens. Portanto **não** torná-lo padrão no Nexa. Quando for implementado, usar conta dedicada, cotas/controle de envios e **não usar métodos descontinuados de segurança ou desabilitar MFA**.

### Descoberta e operação remota

- Cadastro manual por IP, credenciais SNMP e HTTP, com teste e leitura real antes de confirmar.
- Busca detalhada manual na sub-rede local ou em outra sub-rede alcançável, por broadcast ou faixa de endereços.
- Cadastro e monitoramento em massa com seleção de equipamentos, sucesso/falha e relatório de resultado.
- Descoberta automática configurada, nas fontes, entre **6 e 168 horas**; faixas IP predefinidas ou broadcast da rede local.
- Reserva remota de IP e ponto de instalação para adotar impressora quando detectada.
- Equipamento USB precisa estar conectado ao host com o Client; alterações de porta, driver e identificador podem prejudicar detecção.
- As fontes alertam que identificadores de série USB podem ser lidos de dados remanescentes do sistema operacional; validar associação com série física antes de monitorar.
- Impressoras novas detectadas vão para aprovação/adoção no portal; não assumir que todo dispositivo da rede é do provedor.
- O portal possui gerenciamento de pontos de instalação, status de comunicação, contagem de impressoras monitoradas e sintomas de falha.

### Conteúdo coletado e limitações conhecidas

Os materiais descrevem **contadores, níveis de suprimentos, estados e alertas**, além de algumas características do equipamento. A capacidade varia por fabricante, modelo, firmware e meio de conexão; **a detecção de USB não garante nível de toner ou contadores separados por cor**. A documentação pública de segurança afirma que não captura o conteúdo/imagem dos trabalhos impressos, mas somente metadados operacionais.

O Nexa deve coletar apenas dados necessários e manter catálogo de homologação por `marca/modelo/firmware/protocolo/capacidade`, por exemplo:
- Contador geral; P&B, cor e digitalizações quando existentes.
- Identidade, modelo, número de série, status e localização informada.
- Níveis/estados de toner, cilindro, outros consumíveis e peças disponíveis.
- Códigos de erro, alertas SNMP/status e carimbo de origem.
- Timestamp da amostra, versão do coletor, qualidade da leitura e método/protocolo.
- Ausência de capacidade marcada como **não suportado**, nunca igual a **zero**.

### Diagnóstico, recuperação e falhas

A documentação identifica causas como:
- Impressora desligada, IP alterado, troca de placa/número de série, mudança de porta USB e senha SNMP/HTTP alterada.
- Firewall, DNS, antivírus, host do coletor desligado/reinstalado e serviço de atualização indisponível.
- Reset de contador por peça/firmware/manutenção/erro elétrico; separar passagens de leitura para preservar produção.
- Impressora de reserva normalmente desligada não deve gerar alerta de comunicação padrão quando assim configurada.
- Telemetria da impressora e heartbeat de cada serviço devem ser monitorados separadamente.

**Observação de confiabilidade:** geração de arquivos `.plog` comprova buffer local no produto de referência, mas a documentação sozinha **não permite afirmar** sem testes reais a política exata de fsync, garantia de entrega, retenção, retry e tratamento de arquivos corrompidos. Essas garantias são **requisitos próprios do Nexa**.

## 2. Arquitetura do Nexa Collector (implementação parcial; subsistemas pendentes)

### Separação entre serviço e interface administrativa

```text
                    REDE DO CLIENTE
        Impressoras SNMP / IPP / HTTP(S) / USB
                       |
                 Nexa Collector
                 (serviço Rust)
            +----------+----------+
            |          |          |
         Discovery    Poller     Watchdog
            |          |          |
            +----------+----------+
                       |
             Normalize + Validate
                       |
             SQLite WAL / Outbox
          criptografia e idempotência
                       |
               Uploader HTTPS
             retry / backoff / ACK
                       |
          API Nexa de ingestão (Laravel)
                       |
             Jobs de processamento
                       |
               PostgreSQL / Redis
                       |
               Painel Nexa React

  Nexa Collector Console (Tauri / React) --- IPC local seguro ---> Serviço Rust
               (pode fechar; serviço continua funcionando)
```

**Decisões técnicas:**

- **Daemon em Rust** (sem interface obrigatória) para execução como serviço Windows e suporte planejado a systemd/launchd conforme homologação.
- **Console Tauri + React**, instalado opcionalmente, que gerencia a instância local por IPC autenticado/named pipes no Windows e Unix sockets onde aplicável. Não expor a administração em uma porta HTTP aberta à LAN.
- **SQLite em modo WAL** como outbox durável e registro de estado/credenciais criptografadas. Não apagar antes de confirmação da API. Política de retenção, limite de disco e integridade documentados.
- **Laravel API** recebe lotes em endpoint próprio, com autenticação por coletor, limite de payload, autorização por tenant, hash/seq/UUID de amostra, validação de schema e gravação transacional.
- **PostgreSQL** armazena amostras e estado normalizados, com índices por tenant, impressora e data. **Redis workers** processam alertas, consolidação e efeitos colaterais de forma idempotente.
- **Segurança:** TLS 1.2+/1.3, tokens rotativos ou mTLS, certificados válidos, armazenamento de segredos pelo sistema operacional quando possível, rotação de credenciais, eventos de segurança e atualizador com binários assinados.
- **Suporte a múltiplos coletores na mesma rede**: deduplicação pelo identificador físico e pelo escopo autorizado, sem misturar tenants e sem produzir duas faturas.
- **Atualização e recuperação**: download verificado, estágio temporário, atualização atômica, reinicialização controlada, health check, rollback e bloqueio de versão incompatível.
- **Desempenho**: limites de concorrência, rate limit por subnet/dispositivo, jitter, timeouts e prioridades de coleta. Discovery isolada da coleta para que varredura longa não atrase telemetria.

### Separação de responsabilidades dentro do processo Rust

| Componente Nexa | Escopo |
| --- | --- |
| `discovery` | Scan aprovado por CIDR, SNMP/IPP/HTTP/USB, detecção e catálogo |
| `device-registry` | Identidade estável do equipamento e configuração por meio |
| `collector` | Rotinas agendadas de contadores, consumíveis, estados e erros |
| `normalizer` | Validação, mapear MIB/OID/campos de fabricante, status de capacidade |
| `local-store` | Banco SQLite, migração, outbox, limites e backups locais |
| `transport` | HTTPS, autenticação, lotes, compressão, ACK, retry, proxy |
| `update-manager` | Verificação de assinatura, deploy e rollback |
| `health` | Métricas, diagnóstico e estados de componentes |
| `local-ipc` | Autorização local da UI/CLI e operações sensíveis |
| `optional-email-transport` | Extensão SMTP/POP3 autenticada e auditável |

### Especificação das telas Nexa Collector Console

**Início / Visão geral:** cliente, local, ID do ponto, estado dos serviços, conectividade Nexa API, versão, últimas coletas e envios, número de impressoras e fila pendente. Status sem cores como única indicação.

**Impressoras:** grid com IP/USB, modelo, série, status, forma de coleta, monitorada, última leitura e contadores encontrados. Ações para adicionar IP, testar, habilitar/desabilitar, editar credenciais, remover/reativar e abrir diagnóstico. Seleção em lote e resultados por item.

**Descoberta de rede:** interfaces/sub-redes detectadas, CIDRs explicitamente autorizados, broadcast quando aplicável, busca manual, progresso, cancelamento, resultados, credenciais, homologação e adoção controlada.

**USB:** lista de dispositivos físicos/portas/drivers, teste de leitura, capacidade, identificação de série e aviso de possível registro residual.

**Serviços / Saúde:** estado por componente, uptime, carga, uso em disco, outbox, fila de envio, última API, erros por categoria, botão de diagnóstico/exportação de logs sanitizados, reinício autorizado do serviço.

**Configurações:** cliente e chave, endpoint Nexa, proxy, modo de transporte, credenciais, horários de varredura e coleta, política de atualização, logs/retenção, restauração e segurança.

**Atualizações:** versão instalada, canal de release, novas versões assinadas, histórico, progresso, reinício e rollback.

**Logs:** filtro por impressora/evento/severidade/período e exportação com credenciais mascaradas.

### Estados que devem ser distinguidos

- **Agente:** não registrado; registrando; ativo; envio atrasado; sem internet; revogado; desatualizado; atualização falhou; falha local.
- **Impressora:** descoberta; aguardando adoção; monitorando; falha de leitura; sem comunicação; não suportada; retirada; backup; leitura manual.
- **Amostra:** coletada; persistida; em envio; confirmada; rejeitada permanentemente com erro; aguardando retry; expirada após política autorizada.
- **Atualizador:** saudável; nova versão disponível; baixando; aplicando; verificando; concluído; revertendo; falhou.

## 3. Fluxos que precisam funcionar ponta a ponta

| Cenário | Critério objetivo |
| --- | --- |
| Instalação e matrícula | Associação válida ao tenant e cliente autorizado com geração de identidade local única |
| Cadastro por IP | Teste real, visualização de capacidades e ação distinta de registrar/monitorar |
| SNMP desabilitado | Mensagem de causa, sem contrafazer contador, opção de perfil seguro |
| HTTP autenticado | Credencial mantida cifrada e erro distinto de impressora offline |
| Descoberta por CIDR | Não varrer redes fora da autorização; relatório de falhas por IP |
| USB | Detecção real, verificação de série e fallback declarando capacidades faltantes |
| Queda de internet por 48 h | Outbox persistente e retransmissão sem perder e sem duplicar dados |
| Servidor retorna erro 429/503 | Backoff e retry com jitter e integridade de dados |
| Pacote chega duplicado ou fora de ordem | API idempotente e preservação do relógio de coleta |
| Alteração de IP / série / porta USB | Diagnóstico, correção segura e reassociação auditada |
| Troca de cliente de impressora | Histórico de passagens separado e nenhum vazamento entre tenants |
| Reinício do Windows no meio da escrita | SQLite íntegro, restauração e replay correto |
| Disco local cheio | Bloqueio e alerta explícitos, política segura de retenção e backpressure |
| Proxy autenticado | Configuração segura, teste de conexão, TLS end-to-end |
| Instância revogada | Envio bloqueado sem permitir reutilizar credencial/ID |
| Dois coletores veem a mesma impressora | Sem produção/faturamento em dobro |
| Atualização interrompida | Rollback e restabelecimento de serviço sem descarte da outbox |
| Sem módulo de suprimentos | Não executar captura avançada desnecessária nem gerar cobrança/alerta associado |
| Impressora backup offline | Sem alarme falso, leitura quando ligada |
| Cliente tenta acessar o collector de outro tenant | Negado no backend e no registro dos dispositivos |
| Log de diagnóstico | Contém origem, horário e erro, nunca senha ou conteúdo de impressão |

**Definition of Done**: um recurso só é concluído após teste automatizado, validação em impressora ou simulador fiel, cenário de falha, autorização, logs e instrução de operação.

## 4. Itens que exigem inspeção autorizada do executável / laboratório

Sem executar o instalador oficial em uma máquina Windows autorizada e sem credenciais de um ambiente de teste, não afirmar:
- Exatamente qual .NET runtime e quais bibliotecas compõem **a versão atual** (a documentação de sistemas antigos pode estar obsoleta).
- Layout de cada campo e comportamento dos quatro painéis em todas as versões.
- Estrutura interna e formato binário `.plog`/`.dlog`, criptografia concreta e processo de recuperação dos arquivos.
- Protocolo privado, detalhes de troca de chaves e dados enviados para endpoints internos.
- Garantias reais de retransmissão, política de logs, uso de CPU/disco, crescimento de fila offline, prioridades.
- Cobertura efetiva de SNMPv3, IPP, HTTPS, USB e modelos/firmwares específicos.
- Capacidade de operar simultaneamente com várias impressoras USB por host e cenários críticos em drivers de fabricante.

**O Nexa deve implementar sua própria solução e homologá-la com equipamentos físicos.** A documentação pública é suficiente para um desenho inicial, mas não comprova paridade de 100% do comportamento de um programa fechado.

## 5. Fontes oficiais

- [Primeiros passos: chave de instalação](https://help.printwayy.com/etapa-1-cadastro-cliente-gerando-chave/)
- [Tutorial principal de instalação e interface](https://help.printwayy.com/etapa-2-instalando-client-monitorando-impressoras/)
- [Instalação, modos de transporte e restauração](https://help.printwayy.com/instalacao-client/)
- [Download e dependência .NET](https://help.printwayy.com/downloads/)
- [FAQ: serviços, cadência, SNMP e problemas USB](https://help.printwayy.com/duvidas-frequentes/)
- [Monitoramento por IP, busca em massa e USB](https://help.printwayy.com/monitorando-impressoras/)
- [Monitoramento remoto e varredura automática](https://help.printwayy.com/monitorando-remotamente/)
- [Diagnóstico de conexão e status dos pontos](https://help.printwayy.com/monitoramento-conexoes/)
- [Configuração de proxy](https://help.printwayy.com/proxy/)
- [Transporte por e-mail](https://help.printwayy.com/printwayy-client-via-email/)
- [Central pública de segurança e metadados](https://printwayy.com/seguranca/)
- [Dispositivos homologados](https://help.printwayy.com/dispositivos-homologados/)

---

**Escopo documental revisado em 08/10/2026 · sem código funcional implementado.**
