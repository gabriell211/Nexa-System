# Canon imageFORMULA DR-C225 II — homologação USB para o Nexa

**Situação: leitura local do contador total comprovada no scanner conectado; perfil experimental, ainda sem homologação para serviço Windows ou outras unidades.**

Em 16/10/2026, o comando real `--discover-usb` identificou neste Windows `CANON DR-C225 USB`, classe scanner, status `OK` e `VID:PID 1083:1658`. O usuário confirmou que a etiqueta física diz **DR-C225 II**; o driver instalado usa o nome da família `DR-C225`. O identificador de instância completo foi omitido desta evidência por conter dados locais.

O utilitário Canon imageFORMULA assinado apresentou **Digitalização total 169360** e **Rolos atuais 150**. O comando Nexa `--read-canon-drc225-usb` leu exatamente os mesmos valores sem iniciar uma digitalização. Em uma tentativa de digitalização WIA, o driver informou **papel obstruído no alimentador**; nenhuma imagem foi salva. Após a tentativa, o utilitário e o comando mostraram **169361** e **151**. Portanto, o contador aumentou uma unidade mesmo com erro de digitalização: é o valor de manutenção informado pelo fabricante, não uma garantia de documento digitalizado com sucesso. Não houve reset.

## Evidências

- Este modelo se comunica com o computador por USB 2.0. O pacote Canon para Windows inclui driver ISIS/TWAIN/WIA.
- O utilitário oficial Canon imageFORMULA exibe **Total Scanning** (digitalizações acumuladas) e **Current Rollers** (páginas após o último reset do rolete) em *Properties → Maintenance*.
- A consulta WIA neste equipamento expôs firmware e parâmetros de digitalização, mas não expôs o contador. A documentação pública consultada **não estabelece uma API programática para leitura desses dois valores**. O leitor experimental usa automação da página de manutenção do utilitário Canon assinado.
- A Canon lista o **DR-C225II por USB** no DR Scanner Management Tool e exige o **Canon Scanner Management Agent** no computador cliente para gerenciá-lo. Essa documentação descreve a arquitetura de gerenciamento, mas não publica um contrato de API para extrair o contador total do Nexa; a integração direta depende de documentação e suporte do fabricante.
- Fontes: https://gdlp01.c-wss.com/gds/8/0300041198/01/DR-C225W_II_User_Manual_EN.pdf (capítulo de manutenção); https://sg.canon/en/support/0100992023 (driver Canon).
- Gerenciamento oficial: https://canon.jp/biz/product/office-device/imageformula/software/dr-scanner-management.

## O que este incremento entrega

- Descoberta **sob demanda**, sem credencial e somente Windows, de impressoras USB e dispositivos de imagem de **qualquer fabricante**, incluindo a Canon. Requer o driver PnP funcional.
- Comando: a partir de `collector`, execute `cargo run -p nexa-collector-daemon -- --discover-usb`. Não requer `config.local.json`, token, SNMP ou SQLite.
- Botão **Detectar impressoras USB** na aba **Impressoras** do console Tauri. A tabela mostra nome, tipo, status Windows e VID:PID quando disponível.
- O scanner observado pelo usuário é `CANON DR-C225 USB` (classe `Image`, status `OK`, `VID_1083&PID_1658`); portanto **não se pode filtrar apenas VID_04A9**. O nome mostrado pelo driver não comprova revisão II; verificar etiqueta/documentação antes da homologação.
- `cargo run -p nexa-collector-daemon -- --read-canon-drc225-usb` devolve JSON com `meter_scan_total` e `roller_count_resettable`, somente com **um** DR-C225 USB presente, utilitário Canon assinado e sessão Windows interativa. O perfil `canon-dr-c225-usb-utility-v1` foi verificado nesta combinação de hardware/driver/utilitário. A janela pode aparecer brevemente; o leitor fecha por **Cancelar** e não aciona Redefinir, Aplicar ou digitalização.
- Uma entrada opcional `usb_scanners` na configuração associa o identificador PnP local exato a um ID de equipamento já cadastrado para o cliente. Quando o daemon é executado na sessão interativa, ele lê periodicamente o contador total e o persiste na outbox como `meter_scan`, para o envio HTTPS/ACK existente. O contador reiniciável dos rolos fica no diagnóstico e nunca substitui o total. Se o dispositivo não corresponder, o utilitário falhar ou a fila estiver cheia, nenhuma amostra fictícia é criada. **O identificador PnP pode depender da porta USB e não comprova o número de série físico**; troca de equipamento exige conferência humana e atualização do vínculo.
- A API aceita amostra somente de digitalização do equipamento autorizado, preserva UUID idempotente e expõe o campo no histórico. **Não há cadastro automático de novos scanners ou de cliente pelo VID:PID**. Uma nova unidade do mesmo modelo reutiliza o leitor, mas requer seu próprio cadastro e vínculo ao identificador local; unidades simultâneas no mesmo host ainda não são suportadas por este leitor.
- O Console Tauri mostra a descoberta USB e oferece **Ler total do scanner Canon** quando identifica este VID:PID. Os dois valores são exibidos separadamente, sem transmissão. O modo **Windows Service** recusa a configuração USB porque a interface gráfica do fabricante depende da sessão do usuário: registra o motivo e para com êxito, evitando o ciclo de reinicialização configurado no SCM. Para operação de serviço sem janela será necessária interface oficial Canon ou agente auxiliar interativo supervisionado.

## Homologação no computador com o scanner

1. Conectar a Canon DR-C225 II via USB e instalar o driver oficial. O perfil testado aqui usa o utilitário Canon imageFORMULA 1.7.11512.4001 e driver da família DR-C225; outras versões exigem teste.
2. Verificar que o scanner funciona no CaptureOnTouch.
3. Abrir o Canon imageFORMULA Utility, selecionar a Canon USB e consultar **Maintenance**; anotar *Total Scanning* e *Current Rollers*. **Não clicar em Reset.** Uma tentativa de digitalização neste laboratório avançou ambos os valores, mas terminou com aviso de obstrução; verificar fisicamente o alimentador antes de outro ensaio.
4. Executar `cargo run -p nexa-collector-daemon -- --discover-usb` ou a descoberta na aba Impressoras. Se a lista vier vazia, executar no PowerShell `Get-PnpDevice -PresentOnly | Where-Object { $_.FriendlyName -match 'DR.C225|imageFORMULA' } | Format-List FriendlyName,InstanceId,Status,Class` e conferir o driver. Ao compartilhar logs, ocultar a parte identificadora/serial de `InstanceId`.
5. Executar `cargo run -p nexa-collector-daemon -- --read-canon-drc225-usb` e comparar com a tela oficial. Para testar a outbox, cadastrar previamente o scanner como equipamento do cliente na API e configurar `usb_scanners` com seu ID e o `instance_id` PnP local. Não colocar o identificador real em documentação pública.
6. Para produção, obter da Canon uma interface de contador **documentada e de somente leitura** ou homologar um auxiliar interativo supervisionado com versões/idiomas suportados. Valor indisponível continua **null**, nunca zero. O contador de rolos reiniciável não é enviado como total.

## Critérios pendentes para liberar coleta automática

- [x] Diferenciar total do scanner de contador de roletes (que admite reset) e comparar leitura Nexa com o utilitário Canon neste host.
- [x] Observar avanço de uma unidade após a tentativa WIA; houve aviso de obstrução e **não há prova de passagem completa da folha nem de digitalização concluída**.
- [ ] Provar leitura **sem interface interativa** para Windows Service.
- [ ] Validar pelo menos outra unidade DR-C225 II e outras versões/idiomas do utilitário.
- [ ] Validar equipamento desligado, USB desconectado, dois scanners, driver ausente e acesso negado.
- [x] Exigir vínculo USB ao equipamento autorizado, validar perfil e impedir enfileiramento se identidade/leitura não conferirem.
- [ ] Validar execução em Windows Service (conta de serviço), outbox offline, reconexão e reenfileiramento idempotente.
- [ ] Verificar recebimento e histórico no Laravel e no painel do Nexa com teste E2E e hardware real.

O perfil USB é **experimental em sessão interativa**. O backend pode receber `meter_scan` apenas após vínculo explícito ao equipamento do cliente. A leitura de outra unidade do mesmo modelo e a execução sem GUI continuam pendentes de homologação.
