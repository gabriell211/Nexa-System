# Nexa Collector — assistente de instalação e configuração de proxy

**Estado: especificação de implementação; não representa um instalador já entregue.**

## Referências públicas verificadas

- [PrintWayy — Instalação do Client](https://help.printwayy.com/instalacao-client/):
  escolha de idioma, canal Internet/Web Services ou e-mail, proxy na instalação, chave para identificar cliente, confirmação de cliente, diretório e nome do ponto de instalação.
- [PrintWayy — Proxy durante/depois da instalação](https://help.printwayy.com/proxy/#instalacao):
  a opção de proxy surge ao selecionar Internet/Web Services; depois da instalação há Configurações, Salvar e Testar comunicação.
- Referências visuais do fornecedor: [instalação/etapa de comunicação](https://help.printwayy.com/wp-content/uploads/2024/12/instalador-1a.png), [identificação de cliente](https://help.printwayy.com/wp-content/uploads/2024/12/instalador-2.png), [diretório/ponto](https://help.printwayy.com/wp-content/uploads/2024/12/instalador-3.png), [proxy no instalador (GIF)](https://help.printwayy.com/wp-content/uploads/2021/11/config_proxy.gif), [proxy no Client instalado](https://help.printwayy.com/wp-content/uploads/2021/11/config_proxy_instalado.png).
- A documentação de terceiros serve **somente como referência de fluxo**. Não reproduzir interface, logotipo, arquivos privados, endpoints ou protocolos proprietários.

## Estado auditado no Nexa em 16/10/2026

- Console Tauri 2 + React: existe, porém `collector/console/src-tauri/tauri.conf.json` contém `bundle.active=false`.
- Windows Service Rust: o daemon suporta `--service` e há `collector/windows/install.ps1` que registra manualmente `NexaCollector`. Não é um pacote instalável com wizard; não inicia automaticamente.
- Configurações do console: apenas leitura; ainda não há gestão de proxy, edição de credenciais nem IPC autorizado de configuração/controle do serviço.
- Comunicação HTTPS do daemon usa cliente reqwest. Configuração explícita, teste e autenticação de proxy ainda não são recursos comprovados/operacionais.
- A descoberta USB sob demanda está na PR, mas não comprova leitura de contadores.

## Fluxo proposto do instalador profissional Windows

| Etapa | Interface Nexa | Requisito técnico / validação |
| --- | --- | --- |
| 1 | Boas-vindas, licença e idioma PT-BR | Windows compatível; checagem de assinatura do instalador e permissões |
| 2 | Comunicação: Internet (HTTPS); opção **Usar servidor proxy** | Sem proxy por padrão; canal e conexão não expõem segredos |
| 2a | Proxy: endereço/host, porta, exige autenticação?, usuário, senha | Validar host e porta 1–65535; usuário/senha somente quando necessário; segredo fora do JSON |
| 2b | **Testar comunicação** antes de continuar | Diagnóstico HTTPS com TLS validado, usando as configurações efetivamente aplicadas ao serviço; erros distinguíveis |
| 3 | Chave de instalação vinculada ao cliente | Token de uso único/curta vida, expiração e troca por credencial de coletor; não logar chave |
| 4 | Confirmar cliente/unidade e nome do ponto de coleta | O vínculo é verificado pelo servidor; não permitir forjar `customer_id` |
| 5 | Selecionar pasta, revisar opções de serviço | Diretórios e ACL corretas, inicialização automática, elevação UAC quando necessária |
| 6 | Instalar binários do daemon e Console, registrar serviço | Instalação transacional/rollback e supervisão com limites; não deixar serviço parcialmente configurado |
| 7 | Iniciar e confirmar serviço, heartbeat e vínculo no Nexa | `Running` isolado não comprova conexão nem medidores |
| 8 | Concluir e oferecer **Abrir Nexa Collector** | Console pode fechar sem encerrar coleta; discovery USB/rede e monitoramento continuam separados |

A opção de transporte por e-mail é um **requisito separado** com segurança e fila próprias; não apresentar como funcional até estar operacional e testada.

## Configuração de proxy depois da instalação

Tela **Configurações → Comunicação** do Nexa Collector:

- Chave **Usar servidor proxy** (desabilitada por padrão).
- Endereço, porta, autenticação opcional, usuário e campo de senha mascarado; senha preexistente nunca devolvida pela API/UI. O console pode mostrar apenas `senha configurada: sim/não`.
- **Testar comunicação:** simular configuração informada sem gravar senha; emitir diagnóstico classificado de conexão recusada, DNS, timeout, HTTP 407 (credenciais do proxy), TLS/certificado, endpoint da API ou autenticação do coletor. Não imprimir resposta bruta, cabeçalhos `Proxy-Authorization`, URL com credenciais nem token.
- **Salvar:** escrita autenticada e atômica em destino protegido; credenciais no armazenamento seguro do sistema com identidade acessível exclusivamente à conta do serviço; validar antes de aplicar/recarregar daemon.
- Aplicar mudança ao heartbeat, ingestão e futuros serviços de atualização; não redirecionar SNMP e USB local ao proxy.
- Sem conectividade: não perder amostras da outbox e oferecer **Reverter configurações**. Evitar autodestruição do vínculo em caso de teste inválido.

### Regras de segurança

1. Rejeitar URL de API que não seja HTTPS, exceto ambiente de testes explicitamente isolado; **nunca desabilitar validação TLS** como mecanismo de correção.
2. Para proxy HTTP que encaminha HTTPS, usar CONNECT sobre túnel TLS validado para o servidor Nexa. Se o proxy exigir TLS próprio, configurar com cadeia de confiança revisada.
3. Credenciais de proxy diferentes do token Nexa. Não inserir no repositório, no JSON de configuração, CLI, variáveis globais de usuário, logs ou eventos de telemetria.
4. Restringir endpoints externos do teste de conexão para evitar SSRF e exfiltração por URL livre. Usar exclusivamente a API Nexa já vinculada/verificada.
5. Alterações do serviço devem ser feitas via IPC protegido ou elevador autorizado; o Tauri não deve herdar permissões irrestritas do serviço.
6. O instalador não deve criar um serviço funcionalmente iniciado até existirem chave, configuração e segredos válidos; se falhar, restaurar estado/instalação anterior.
7. Definir suporte explícito a proxy por ambiente (sem proxy / manual HTTP CONNECT). Autodetecção PAC/WPAD, NTLM/Kerberos e proxy HTTPS devem ser negociados e testados antes de prometer compatibilidade.

## Testes de aceite

- [ ] Instalação **sem proxy**, servidor HTTPS acessível, chave válida, cliente confirmado, heartbeat recebido.
- [ ] Proxy manual sem autenticação: teste e envio completos por CONNECT.
- [ ] Proxy com usuário/senha: autenticação positiva, 407 e correção de credenciais.
- [ ] Host inválido, porta vazia/zero/acima de 65535, URL malformada, DNS inválido, timeout e TLS expirado/CA não confiável.
- [ ] Desativar proxy e testar rota direta; reativar sem perder token ou fila.
- [ ] Trocar configurações após instalação, salvar e recarregar serviço sem reiniciar o Windows.
- [ ] Interface de usuário comum não lê segredo de proxy nem token Nexa; conta LocalService do daemon consegue autenticar.
- [ ] Queda de internet com leituras já enfileiradas e retomada: ACK idempotente, sem descartar medições.
- [ ] Reinstalação e atualização assinada preservam vinculação, dados, chave e configuração de proxy sem sobrescrevê-los.
- [ ] A UI diferencia teste de conexão do proxy, teste com backend, heartbeat e contador físico de impressora.
- [ ] Multi-tenant: token de instalação não pode vincular coletores a cliente alheio.
- [ ] Windows x64, instalação limpa, atualização, desinstalação com retenção opcional segura, falha de energia e rollback verificados em VM.

**Gate de release:** nenhum instalador ou recurso de proxy deve ser anunciado como funcional antes de CI de empacotamento, teste real em Windows/ambiente com proxy autenticado e validação de que o serviço usa exatamente a configuração testada.
