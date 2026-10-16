# Verificação de impressoras e scanners — rede local e USB (16/10/2026)

Escopo: descoberta e leitura do contador no Windows de laboratório, seguida de uma tentativa de digitalização WIA autorizada pelo usuário. Endereços privados, identificador de instância USB e cabeçalhos HTTP completos foram omitidos deste documento. Nenhum equipamento foi cadastrado, associado a cliente ou habilitado para envio remoto de medidores.

O levantamento da [documentação pública da PrintWayy](PRINTWAYY-MONITORING-RESEARCH-2026-10-16.md) explica os caminhos publicados de SNMP, USB e homologação por modelo, além das lacunas que impedem inferir contadores a partir da descoberta.

| Equipamento observado | Evidência real | Resultado |
| --- | --- | --- |
| Multifuncional com servidor HTTP `KM-MFP` | Portas de impressão 9100 e 515 acessíveis; `/eSCL/ScannerCapabilities` devolveu HTTP 500 | Candidato de impressão; scanner e contadores não homologados |
| HP OfficeJet Pro 7740 | Portas 9100, 515 e 631 acessíveis; eSCL `ScannerCapabilities` devolveu XML HTTP 200 com modelo; `ScannerStatus` devolveu XML HTTP 200 e estado `Idle` | Descoberta de impressão e endpoint de scanner eSCL verificados; digitalização e contadores não homologados |
| Canon DR-C225 II USB | Etiqueta física confirmada pelo usuário; driver anuncia `DR-C225`, PnP `OK`, VID:PID `1083:1658`. Utilitário Canon assinado e leitor Nexa retornaram 169360/150 (total/rolos), depois 169361/151 após tentativa WIA que relatou obstrução | Leitura local do total e avanço observados neste equipamento; digitalização concluída, operação em serviço e outras unidades não homologadas |
| Epson L6270 USB | Fila Windows configurada em `USB005`, sem interface PnP de impressora presente no momento do teste | Apenas configuração da fila observada; presença física não homologada |

## Código e verificações

- `--discover-local` agora consulta eSCL HTTP/80 de forma limitada para listar também scanners dedicados sem portas de impressão. O retorno é apenas um candidato. eSCL sobre HTTPS exclusivo, WSD e dispositivos autenticados permanecem fora desta busca.
- `--discover-usb` usa `pwsh.exe` quando `powershell.exe` não existe no PATH; uma falha do script não é ocultada por fallback.
- `--read-canon-drc225-usb` consulta a página de manutenção do utilitário Canon em sessão interativa, valida modelo, VID:PID, assinatura e leitura numérica. O daemon enfileirou uma amostra real `meter_scan=169360` com `meter_total=null` em SQLite de laboratório, sem token nem upload. A API/portal passaram a ter campo próprio `meter_scan`.
- Testes do núcleo Rust, build TypeScript/Vite do console e comandos reais `--discover-local` e `--discover-usb` foram executados neste host. O teste de eSCL cobre XML válido HTTP 200, HTTP 500 e resposta HTML sem capacidades.

## Aceite ainda necessário para coleta automática

1. Confirmar proprietário/cliente, identificação física e modelo em cada equipamento, sem inferir identidade por IP, porta ou VID:PID.
2. Para impressoras, obter credencial SNMP autorizada e OID de contador documentado por modelo; comparar pelo menos duas leituras com o painel físico, incluindo impressão de teste controlada e tratamento de reset.
3. Para scanners, validar digitalização concluída e método documentado de consulta de contadores, se existente. A automação da GUI Canon serve como perfil experimental nesta sessão; eSCL `ScannerCapabilities` e `ScannerStatus` não fornecem total de páginas digitalizadas.
4. Associar somente o equipamento confirmado a um Collector/cliente da API e verificar outbox, ACK e histórico sem duplicação. Até lá, nenhum medidor é transmitido ou usado para faturamento.
