# Nexa System — monitoramento remoto, descoberta e adoção de impressoras

**Situação em 16/10/2026: especificação e comparação com referência pública; fluxo remoto completo não implementado.**
O objetivo é disponibilizar no portal uma experiência de monitoramento remoto *sem depender de acesso remoto interativo ao computador do cliente*, por meio do Nexa Collector instalado e vinculado ao cliente.

## Fontes analisadas

1. [PrintWayy — Monitorando impressoras de forma remota](https://help.printwayy.com/monitorando-remotamente/): a ferramenta local suporta descoberta periódica (6 a 168 horas), faixa IP configurável, descoberta local na ausência de faixa e envio de candidatos para Novas impressoras; oferece ainda pré-cadastro de impressora por cliente com modo manual ou automático e ponto de instalação/IP reservados.
   - [Captura: configuração da varredura](https://help.printwayy.com/wp-content/uploads/2024/12/varredura.png)
   - [Captura: seleção entre monitoramento manual/automático](https://help.printwayy.com/wp-content/uploads/2024/12/impressora-manual.png)
2. [PrintWayy — Novas impressoras](https://help.printwayy.com/novas-impressoras/): revisão da descoberta, preenchimento de proprietário, localização, departamento e contrato; dispositivos rejeitados são ignorados em buscas futuras.
3. [PrintWayy — Monitorando impressoras](https://help.printwayy.com/monitorando-impressoras/): diferencia **Salvar impressoras** de **Monitorar**; descoberta USB e rede não equivalem a cadastro homologado.
4. [Especificação prévia do Nexa Collector](NEXA-COLLECTOR.md) e [instalador/proxy](NEXA-COLLECTOR-INSTALLER-PROXY.md).

Estas referências são de funcionalidade, **não** autorização para copiar telas, marcas, ativos, software ou protocolos privados.

## Fluxo A: descoberta programada da rede do cliente

1. No **portal Nexa → Pontos de instalação**, o administrador autorizado escolhe cliente e collector ativo, liga a busca periódica e informa **intervalo de 6–168 horas**, faixas CIDR/IP autorizadas e limite de hosts/conexões; o portal mostra próximo agendamento e último resultado.
2. O backend persiste uma revisão assinada da política vinculada a `tenant_id`, `customer_id` e `collector_id`. O agente busca a política por HTTPS autenticado, com *ETag/revision*; não recebe shell ou scripts executáveis.
3. O daemon agenda a descoberta de forma independente do ciclo de medições e do heartbeat. Limitar duração, concurrency, rate e lista de IPs; scans apenas nas redes autorizadas do cliente. Configuração vazia pode habilitar **somente sub-redes locais privadas permitidas** (não varrer internet ou VPNs sem aprovação explícita).
4. O dispositivo é identificado como **candidato**, com IP, evidência de portas, protocolo, timestamp e identificadores verificáveis disponíveis; nome/modelo/nº série permanecem `null` se desconhecidos. Descoberta não implica impressora pertencente ao cliente, não realiza comandos de escrita e não configura SNMP/OID automaticamente.
5. O agente envia **candidatos** por lote, com chave idempotente, token individual e outbox dedicada / controle de backpressure; a API valida limites, escopo e unicidade por *tenant/customer/collector*.
6. O portal mostra **Novas impressoras** com status `pendente`, `aprovada`, `ignorada`, `indisponível` ou `conflito`. O operador pode identificar proprietário, unidade/localização, departamento, centro de custo e contrato apropriados ao cadastro existente.
7. **Aprovar/adotar** requer permissões de gestor, validade de cliente/unidade, comparação contra parque existente (serie física confirmada preferencialmente) e perfil homologado de medição. Só então o backend emite vínculo de monitoramento e instrução específica ao coletor.
8. **Ignorar** mantém evidência/auditoria e bloqueia reapresentação por fingerprint estável; permitir reversão auditada. Reinicialização do agente não pode ressuscitar dispositivos ignorados.

**Cuidados:** a documentação PrintWayy menciona broadcast, mas o Nexa deve implementar primeiro varredura limitada por CIDR privado aprovado, para não criar alcance amplo não autorizado. A política deve ter cota de hosts e ser tolerante a intervalos longos; sem revarrer redes externas automaticamente.

## Fluxo B: reservar IP/ponto antes de encontrar a impressora

1. No portal **Parque → Impressoras por cliente → Adicionar impressora**, selecionar equipamento cadastrado, cliente e contrato.
2. Selecionar `manual` ou `automático via rede`. Apenas modelos com perfil homologado e cliente ativo podem receber automação de medidores.
3. No modo automático, escolher **um ponto de instalação pertencente ao mesmo cliente**, cadastrar IP privado de destino explicitamente permitido pela política e confirmar uma reserva de vínculo `pending_device` (data, usuário e versão).
4. O collector consulta periodicamente *somente IPs autorizados reservados* além da busca local aprovada, valida identidade (serial físico quando disponível, fabricante/modelo/OID compatível, capacidades) e relata resultado.
5. Apenas confirmação correta da identidade ativa o monitoramento. **Uma porta 9100 aberta ou um IP responder não prova que seja o equipamento esperado**; se o IP mudar de titular, serial divergir, prova estiver ausente ou já houver coleta em outro cliente, marcar `needs_review` e parar antes do envio de contadores.
6. Modo manual usa um endpoint/auditoria próprios; dados manuais nunca são identificados como coletados automaticamente. Guardar autor, origem, horário, motivo e séries anteriores, sem enfileirar medidor como leitura do agente.
7. Reservas vencidas, cliente inativo, coletor revogado e equipamento transferido devem bloquear ativação. Revogar ou editar reserva mantém histórico.

## Interfaces propostas no Nexa

| Superfície | Ação/estado |
| --- | --- |
| Portal → Pontos de instalação | Configurar intervalo, redes aprovadas, estado do coletor, última/ próxima varredura, último envio |
| Portal → Novas impressoras | Lista de candidatos, evidências, vínculo com cliente, aprovação, ignorar, revisar conflitos |
| Portal → Parque por cliente | Pré-cadastro por IP, selecionar coletor, manual/automático, aguardar detecção, ativado, suspenso |
| Console Tauri → Impressoras | Busca USB e rede sob demanda, candidato vs homologado, situação da comunicação e monitoramento |
| Auditoria | Quem autorizou escaneamento, aprovou/ignorou candidato, vinculou equipamento e habilitou medição |

A interface deve distinguir claramente **descoberto ≠ homologado ≠ cadastrado ≠ monitorado**.

## Componentes / contratos propostos (ainda sem endpoints publicados)

| Componente | Responsabilidade |
| --- | --- |
| Laravel `discovery_policies` | Tenant/customer/collector, revisão, `enabled`, intervalo 6–168 horas, redes permitidas, `updated_by` |
| Laravel `discovery_candidates` | Evidências de última descoberta, fingerprint com algoritmo versionado, seen_at/first_seen_at, situação, origem |
| Laravel `monitoring_reservations` | Impressora cadastrada, agente, IP, identidade esperada, expiração, modo, status, ator |
| Laravel `GET /api/v1/collector/policy` | Somente política do coletor autenticado, com revisão/ETag |
| Laravel `POST /api/v1/collector/discovery` | Ingestão idempotente de candidatos (NÃO de leituras), autorização por tenant/customer/collector |
| Laravel `POST /api/v1/collector/reservation-result` | Confirmação de vínculo físico, estados `matched`/`mismatch`/`unverified` |
| Rust `discovery_scheduler` + `outbox` | Agenda, varre redes permitidas e reporta candidato/estado confiavelmente sem bloquear heartbeat |
| Rust `monitoring_verifier` | Confirma identidade e OID homologado antes de habilitar coleta |
| React portal / TanStack Query | Gerencia políticas/candidatos/reservas com autorização no backend e invalidação de cache |

**Não implementar** autorização por mera posse de endereço IP, configuração arbitrária de SNMPv2c sem OID homologado, comando remoto generalizado, acesso à rede do cliente a partir do navegador, nem registro automático de todo hardware da rede.

## Gap do código Nexa verificado

| Capacidade atual | Situação |
| --- | --- |
| `collector/core/src/discovery.rs`: scan local privado (portas TCP 9100/515/631), limitado e sob demanda | **Implementado**, retorna *candidatos* não homologados |
| `collector/core/src/usb_devices.rs`: descoberta Windows USB impressora/imagem sob demanda | **Na PR #51**, diagnóstico sem contador |
| Daemon, SQLite outbox e HTTPS para contadores **já configurados** por ID/OID | **Implementado** com limites; não serve de fila de *candidatos* automaticamente |
| Laravel heartbeat/ingestão autenticados, isolamento por cliente e impressoras cadastradas | **Implementado** |
| Laravel política remota de descoberta, ingestão de candidatos e fila de adoção | **Não implementado** |
| Agenda automática de 6–168 horas, faixas aprovadas geridas no portal | **Não implementado** |
| Reserva prévia de IP+coletor, verificação física e ativação automática | **Não implementado** |
| Portal Novas impressoras com aprovação/ignorar e anti-duplicidade | **Não implementado** |
| Inserção manual auditada de contadores distinguível de telemetria | **Exige validação/implementação específica** |

## Testes mínimos de aceite

- [ ] Intervalos 6/168h aceitos; 5/169h, intervalos nulos malformados e redes públicas não autorizadas recusados.
- [ ] Sem faixas aprovadas, nenhum IP remoto não explicitamente autorizado é testado; limites de /24 e 1024 hosts respeitados quando aplicáveis.
- [ ] Descoberta sob demanda e automática não bloqueiam heartbeat, SNMP nem a entrega da outbox existente.
- [ ] Candidato duplicado por retries não vira múltiplas impressoras; `ignore` sobrevive a novo scan e restart; reversão somente por autorizado.
- [ ] API rejeita token revogado, customer/collector de outro tenant e payloads de modelos/seriais/contadores não confiáveis.
- [ ] Candidato com apenas porta TCP aberta fica `identidade não verificada`, sem medição e sem associação silenciosa.
- [ ] Reserva de IP do mesmo cliente ativa apenas após identidade confirmada; IP reutilizado, serial divergente e impressora já monitorada em outro cliente geram conflito sem faturamento.
- [ ] Cadastro manual exige autorização, origem, autor e auditoria; não aparece como amostra automática.
- [ ] Offline prolongado: preservação de dados de monitoramento já coletados, sem saturar disco com descobertas repetidas; recuperação sem duplicação.
- [ ] Processos simultâneos de aprovação e transferência do parque são serializados em transações, sem reassociação cruzada.
- [ ] E2E no browser: configurar política → ver novas impressoras → aprovar/ignorar → reservar IP → detectar e ativar corretamente.
- [ ] Teste Windows do instalador/serviço na rede autorizada, queda de rede e migração/reinstalação preservando estados.

**Gate de liberação:** o fluxo remoto só está pronto quando os endpoints, agenda, backend de candidatos, telas do portal, aprovação, verificação de identidade, outbox, logs e testes de hardware/CI estiverem integrados. Esta especificação não comprova o funcionamento do produto.
