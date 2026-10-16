# Canon imageFORMULA DR-C225 II — homologação USB para o Nexa

**Situação: descoberta USB implementada; leitura automática dos contadores ainda NÃO homologada.**

## Evidências

- Este modelo se comunica com o computador por USB 2.0. O pacote Canon para Windows inclui driver ISIS/TWAIN/WIA.
- O utilitário oficial Canon imageFORMULA exibe **Total Scanning** (digitalizações acumuladas) e **Current Rollers** (páginas após o último reset do rolete) em *Properties → Maintenance*.
- A documentação pública consultada **não estabelece uma API programática para leitura desses dois valores**. Interfaces de digitalização ISIS, TWAIN e WIA não implicam a existência de propriedades públicas dos contadores.
- Fontes: https://gdlp01.c-wss.com/gds/8/0300041198/01/DR-C225W_II_User_Manual_EN.pdf (capítulo de manutenção); https://sg.canon/en/support/0100992023 (driver Canon).

## O que este incremento entrega

- Descoberta **sob demanda**, sem credencial e somente Windows, de impressoras USB e dispositivos de imagem de **qualquer fabricante**, incluindo a Canon. Requer o driver PnP funcional.
- Comando: a partir de `collector`, execute `cargo run -p nexa-collector-daemon -- --discover-usb`. Não requer `config.local.json`, token, SNMP ou SQLite.
- Botão **Detectar impressoras USB** na aba **Impressoras** do console Tauri. A tabela mostra nome, tipo, status Windows e VID:PID quando disponível.
- O scanner observado pelo usuário é `CANON DR-C225 USB` (classe `Image`, status `OK`, `VID_1083&PID_1658`); portanto **não se pode filtrar apenas VID_04A9**. O nome mostrado pelo driver não comprova revisão II; verificar etiqueta/documentação antes da homologação.
- Não realiza varredura de documentos, leitura/escrita de registradores USB, reset de roletes, registro de equipamentos nem coleta/envio de valores.

## Homologação no computador com o scanner

1. Conectar a Canon DR-C225 II via USB e instalar o driver oficial atualizado.
2. Verificar que o scanner funciona no CaptureOnTouch.
3. Abrir o Canon imageFORMULA Utility/Driver Setting Tool, selecionar a Canon USB e consultar **Maintenance**; anotar *Total Scanning* e *Current Rollers* antes e após digitalizar 3 folhas. **Não clicar em Reset.**
4. Executar `cargo run -p nexa-collector-daemon -- --discover-usb` ou a descoberta na aba Impressoras. Se a lista vier vazia, executar no PowerShell `Get-PnpDevice -PresentOnly | Where-Object { $_.FriendlyName -match 'DR.C225|imageFORMULA' } | Format-List FriendlyName,InstanceId,Status,Class` e conferir o driver. Ao compartilhar logs, ocultar a parte identificadora/serial de `InstanceId`.
5. Confirmar com a Canon um método **documentado e de somente leitura** para consultar os contadores em Windows, ou investigar um recurso WIA/TWAIN exposto pelo driver mediante documentação/inspeção de laboratório. Capturar o resultado contra os valores oficiais.
6. Só depois do passo 5 implementar o provider de contadores e testes de integração, com vinculação explícita a equipamento e cliente no backend, idempotência, fila local e ACK existente. Um contador indisponível deve ficar **null**, nunca ser reportado como zero. Se o contador de roletes zerar, preservar histórico separado da medição de vida útil.

## Critérios pendentes para liberar coleta automática

- [ ] Provar leitura **sem abrir interface interativa** e sem iniciar digitalização.
- [ ] Diferenciar total vitalício de contador de roletes (que admite reset).
- [ ] Confirmar valores iguais aos do utilitário Canon antes/depois de digitalizar 3 folhas.
- [ ] Validar equipamento desligado, USB desconectado, dois scanners, driver ausente e acesso negado.
- [ ] Vincular USB a impressora/equipamento autorizado do cliente, impedir leitura/enfileiramento de medidores inválidos.
- [ ] Validar execução em Windows Service (conta de serviço), outbox offline, reconexão e reenfileiramento idempotente.
- [ ] Verificar recebimento e histórico no Laravel e no painel do Nexa com teste E2E e hardware real.

O daemon continua exclusivamente SNMP para **coleta de contadores efetivos** neste incremento. Nenhum dado de scanner USB é enviado à API enquanto não houver driver de contador verificável.
