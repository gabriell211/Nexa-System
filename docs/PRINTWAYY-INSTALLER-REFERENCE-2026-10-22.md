# PrintWayy Client: instalador e catálogo de referência para o Nexa Collector

## Origem e escopo

Análise estática do pacote local `C:\Users\Admin\OneDrive\Desktop\Setup PrintWayy` em 22/10/2026. Não foi executado o instalador nem registrado serviço. A pasta contém o conteúdo extraído de um instalador NSIS, incluindo `NetClientService`, `LinkService`, `LocalAdminTool`, `UpdaterService`, `UpdaterGuardianService`, `Database/LOCALADMIN.sdf` e `OIDMapping/Mapping.pdmap`. O arquivo `uninstall.exe.nsis` começa com cabeçalho Nullsoft/NSIS; a pasta não contém um `setup.exe` para verificar o fluxo completo de instalação.

Os executáveis do cliente e da ferramenta local reportam versão `9.26.1.0` e têm assinatura Authenticode válida de N5 SOFTWARE LTDA. Há também componentes anteriores `9.18.2.0`, SQL Server Compact e dependências .NET Framework 4.0. Os arquivos de configuração indicam integração HTTPS com a API PrintWayy. Essas observações descrevem o pacote; não asseguram que os serviços foram instalados ou executados neste host.

## Decodificação do mapeamento

`Mapping.pdmap` contém 136.000 bytes de texto Base64. A primeira decodificação gera 102.000 bytes binários. A rotina `Compression.DecompressAndDecrypt(string)` da biblioteca `Southwayy.PrintWayy.Framework.Util.dll` fornecida no próprio pacote produz JSON válido. O arquivo original foi preservado.

- Fonte SHA-256: `4323c33efd3fa14edbf74e7d53d73bbfd67ce06fb7d23c58c4b6cad107e1ba48`.
- Versão do mapeamento: `2`; data interna: `2026-09-22T19:59:23.107296-03:00`.
- Cópia integral legível: `C:\Users\Admin\OneDrive\Desktop\Setup PrintWayy\OIDMapping\Mapping.decoded.json`.
- Lista de modelos e estados para consulta: `C:\Users\Admin\OneDrive\Desktop\Setup PrintWayy\OIDMapping\homologacoes-printwayy-2026-09-22.csv`.

O JSON integral contém notas internas de suporte na coluna `Observation`. Ele permanece fora do repositório Nexa. O catálogo incorporado ao agente omite notas, URLs internas, serializações HTML/JSON/XML e outros dados que não são necessários para a consulta de compatibilidade.

## Cobertura encontrada

| Item | Quantidade |
| --- | ---: |
| Modelos | 2.142 |
| Fabricantes | 26 |
| Definições de OID | 912 |
| Mapeamentos HTML / JSON / XML / OM | 341 / 75 / 40 / 4 |
| Rede aprovada / não testada / incompatível no PrintWayy | 1.898 / 240 / 4 |
| USB aprovado / não testado / incompatível no PrintWayy | 920 / 1.165 / 57 |
| Aprovados nas duas interfaces | 688 |

O enum original `HomologationStatus` define `0=NotTestedYet`, `1=Approved`, `2=NotCompatible`. No modelo, o campo `SNMP` representa a coluna de rede e `USB` a coluna USB. A [lista pública de modelos](https://app.printwayy.com/Modelos) e a [explicação oficial de homologação](https://help.printwayy.com/dispositivos-homologados/) confirmam que os estados são separados por interface.

Há 11 pares fabricante/modelo repetidos no snapshot, dos quais 8 têm estados diferentes entre registros. O catálogo preserva o `source_model_id`; não une nem promove automaticamente duplicatas conflitantes. Todos os IDs de OID referenciados pelos modelos foram encontrados nas 912 definições do pacote.

## Integração no Nexa Collector

O arquivo `collector/core/data/printwayy_reference_2026-09-22.json` contém os 2.142 modelos, os dois estados e OIDs candidatos para **contador total de rede**. O daemon oferece `--reference-catalog` para exportar tudo e `--reference-search <termo> [all|network|usb|both]` para consulta limitada. O Console mostra os estados por modelo e permite filtro por interface.

Para um equipamento já autorizado e vinculado ao `printer_id` do backend, `reference_model_id` pode selecionar automaticamente um único OID total de rede. Exemplo:

```json
{
  "printer_id": 123,
  "ip": "192.168.1.50",
  "reference_model_id": 31946
}
```

O ID `31946` corresponde ao modelo Epson L6270 no snapshot; o IP e o `printer_id` acima são apenas exemplo de configuração, não um equipamento descoberto.

O daemon continua exigindo CIDR autorizado e credencial SNMP. Um OID explícito diferente do OID do perfil é recusado. Entre os 1.898 modelos com rede aprovada no snapshot, 1.583 têm exatamente um OID candidato de total; 314 não têm nenhum e 1 têm dois distintos. Respostas ausentes ou inválidas não viram zero. Esta resolução configura **somente o contador total**; separação P&B/cor, suprimentos, cópias e digitalização exigem perfis específicos.

Para um equipamento **já vinculado** por IP e `printer_id`, é possível pedir seleção automática do perfil pela identidade SNMP:

```json
{
  "printer_id": 123,
  "ip": "192.168.1.50",
  "auto_detect_reference": true
}
```

A cada coleta, o agente consulta `prtGeneralPrinterName.1` e `sysDescr.0` e usa o OID total somente se os nomes observados corresponderem a modelos aprovados para rede e todos apontarem ao mesmo OID direto. Um nome genérico, modelo curto, status conflitante ou OIDs divergentes interrompem aquela leitura. Não se pode combinar essa opção com `reference_model_id` nem com um OID explícito. A detecção não cadastra equipamentos encontrados pela varredura; ainda são exigidos `printer_id`, IP autorizado e credencial SNMP. Esse caminho não foi ensaiado em impressora real nesta análise.

Na descoberta USB, o Nexa compara o nome PnP ao catálogo e mostra o estado PrintWayy apenas quando fabricante, modelo e estados das entradas coincidentes são inequívocos. A correspondência de nome não lê contador. Os 920 estados USB estão disponíveis para consulta, mas o pacote de mapeamento não contém um protocolo USB universal que o Nexa possa executar. O Nexa tem hoje apenas o leitor experimental Canon DR-C225 II em sessão interativa; ele não atende automaticamente aos outros modelos nem roda como Windows Service. Também há modelos de rede que usam HTML, JSON ou XML, sem adaptador Nexa correspondente. Portanto, não se pode declarar coleta automática de todos os 2.142 modelos nem homologação Nexa apenas com a importação.

O executável `NetClientService/Southwayy.PrintWayy.Tools.WinUSB.exe` é um instalador de driver: recebe VID, PID, GUID, caminho e nome INF e chama `libwdi` para instalar WinUSB. A pasta `LocalAdminTool/Southwayy.PrintWayy.Tools.WinUSB` contém seções PE extraídas (`.text`, `.rsrc`, certificado), não implementações adicionais de leitura. Os métodos de contador USB ficam em `Southwayy.PrintWayy.Business.Client.dll`, especialmente `USBPrintersManager`, e alternam entre PJL, comandos HTTP via USB, XML Canon e leitores Brother, Epson e Ricoh. A biblioteca depende de `LibUsbDotNet.dll`, do mapeamento local e de outras DLLs proprietárias; copiar apenas o `WinUSB.exe` para o Nexa não ativa esses leitores. A análise do `MappingManager` ainda indica uma filtragem de modelos USB a partir da lista SNMP aprovada, logo nem o binário isolado comprova suporte funcional a todas as 920 entradas USB aprovadas no catálogo.

## Critério de uso operacional

O status do PrintWayy é uma referência de terceiros. Antes de marcar um perfil como homologado **no Nexa**, é necessário confirmar modelo/firmware e série física, executar pelo menos duas leituras, comparar com o painel do equipamento e uma impressão/digitalização controlada, verificar unidade, reset e separação de contadores. O backend Nexa continua com `billing_ready=false`; este catálogo não autoriza faturamento.

O banco `LOCALADMIN.sdf` exige senha e não foi usado para gerar o catálogo. As contagens foram conferidas com parse JSON, enum de status, unicidade dos IDs e resolução de referências OID. Não houve ensaio com os 2.142 equipamentos físicos.

## Verificação local desta implementação

Neste host, `nexa-collector-daemon --discover-usb` retornou `[]`: há uma fila de impressão `EPSON L6270 Series` associada a `USB005`, mas nenhum dispositivo USB de impressão/imagem presente no PnP para uma leitura física. A instalação local do Nexa tem um registro de impressora e uma faixa autorizada, porém não possui arquivo de credencial `NEXA_SNMP_COMMUNITY`; também não há serviço `NexaCollector` registrado. Por isso, nenhuma leitura SNMP/USB de campo nem envio ao backend foi executado.

Passaram os testes do núcleo e do daemon Rust, o build React e a checagem da aplicação Tauri com o sidecar temporário. A CLI consultou o snapshot `L6270` e retornou os estados aprovados nas duas interfaces; o sidecar temporário foi removido após a checagem.
