# Nexa Collector — catálogo MIB/OID multi-fabricantes

**Situação em 16/10/2026:** base técnica genérica implementada; **nenhum modelo listado abaixo foi automaticamente homologado para faturamento** por este documento ou pelo catálogo. As fabricantes possuem milhares de modelos, OEMs, firmwares e revisões; não existe OID único e universal para "cópia P&B", "cópia colorida", "digitalização" ou "percentual de toner" em todos eles.

## Fabricantes e MIBs

| Marca | Enterprise/PEN (identificação inicial) | Documentos ou famílias de MIB | Disponibilidade/limite |
|---|---|---|---|
| Ricoh | `1.3.6.1.4.1.367` | `RicohPrivateMIB`, `Printer-MIB` | A Ricoh documenta árvore privada com contadores totais; APIs e descrições podem requerer acesso ao programa de desenvolvedores. Fonte: https://emea.ricoh-developer.com/node/636 |
| Konica Minolta | `1.3.6.1.4.1.18334` | `Printer-MIB` + MIB privada por modelo/geração | Registro PEN na IANA; manual bizhub documenta SNMP. **Não afirmar** MIB privada descarregável nem inventar contadores. Fontes: https://www.iana.org/assignments/enterprise-numbers/?q=18334 e https://manuals.konicaminolta.eu/bizhub-651i-551i-451i/EN/contents/WC_12_03_05.html |
| Kyocera | `1.3.6.1.4.1.1347` | `KMCOMMON-MIB`, `KYOCERA-MIB`, `Printer-MIB` | Há famílias privadas identificadas; `KYOCERA-MIB` arquivada remonta a modelos antigos, não homologação de ECOSYS moderno. Fonte: https://mibs.observium.org/object/KMCOMMON-MIB/kyocera |
| HP | `1.3.6.1.4.1.11` | HP Jetdirect (NP), HP PML, `Printer-MIB` | HP Web Jetadmin usa Printer MIB genérica e MIBs privadas; Jetdirect e FutureSmart podem variar. Fonte: https://support.hp.com/br-pt/document/ish_6720123-7035621-16 |
| Samsung | `1.3.6.1.4.1.236` | `SAMSUNG-COMMON-MIB`, `SAMSUNG-PRINTER-EXT-MIB`, `Printer-MIB` | Modelos Samsung antigos têm árvores próprias mesmo após aquisição do negócio de impressão pela HP. Fonte: https://mibs.observium.org/mib/SAMSUNG-COMMON-MIB/ |
| Canon | `1.3.6.1.4.1.1602` | `Printer-MIB` e MIB privada por família | A linha imageRUNNER ADVANCE documenta SNMPv1/v3 e consulta de números de impressões; sem prova de OID privado universal. Fonte: https://files.canon-europe.com/files/soft03-48695/Manual/imageRUNNER_ADVANCE_6500i_Series_User_Guide_PT.pdf |
| Epson | `1.3.6.1.4.1.1248` | `Printer-MIB`, objetos Epson dependentes do firmware | A documentação da WF-C8690B indica SNMP/161 para consulta de MIB; tanques e manutenção podem ser não expostos no padrão. Fonte: https://download4.epson.biz/sec_pubs/wf-c8690b/admg/en/manual.pdf |
| Xerox | `1.3.6.1.4.1.253` | Xerox XCMI, `XEROX-COMMON-MIB`, `XEROX-GENERAL-MIB`, `Printer-MIB` | **Pacotes oficiais 2026** SMIv1/SMIv2/Textual Conventions: https://origin-download.support.xerox.com/pub/drivers/MIBs/ |
| Brother | `1.3.6.1.4.1.2435` | `BROTHER-MIB`, `Printer-MIB`, `HOST-RESOURCES-MIB` | A Brother exige **solicitação oficial** para disponibilização da MIB privada; alguns OIDs públicos de referência são históricos e não garantem modelos atuais. Fonte: https://help.brother-usa.com/app/answers/detail/a_id/52976/~/how-to-obtain-brother-snmp-mib-information |

A árvore empresarial pode ser diferente em hardware OEM ou MFP remarcada. O Nexa só identifica automaticamente a fabricante quando o `sysObjectID` realmente aponta para um desses PENs; descrições de texto não substituem identificação física. Uma MIB descreve objetos, mas **não garante** que determinado equipamento implemente cada OID.

## OIDs padronizados (SNMP GET/WALK somente leitura)

| MIB | Campo | OID de coluna/base | Atenção |
|---|---|---|---|
| SNMPv2-MIB | `sysDescr` | `1.3.6.1.2.1.1.1.0` | Descrição não constitui identificação verificada |
| SNMPv2-MIB | `sysObjectID` | `1.3.6.1.2.1.1.2.0` | Identifica tipo do agente, não necessariamente fabricante da mecânica |
| SNMPv2-MIB | `sysName` | `1.3.6.1.2.1.1.5.0` | Nome editável pelo administrador |
| HOST-RESOURCES-MIB | `hrDeviceDescr` | `1.3.6.1.2.1.25.3.2.1.3` | Caminhar `hrDeviceIndex` |
| HOST-RESOURCES-MIB | `hrPrinterStatus` | `1.3.6.1.2.1.25.3.5.1.1` | Status; não um contador |
| HOST-RESOURCES-MIB | `hrPrinterDetectedErrorState` | `1.3.6.1.2.1.25.3.5.1.2` | Bits de erros; interpretá-los conforme RFC |
| Printer-MIB | `prtGeneralPrinterName` | `1.3.6.1.2.1.43.5.1.1.16` | Nome potencialmente editável; índice dinâmico |
| Printer-MIB | `prtGeneralSerialNumber` | `1.3.6.1.2.1.43.5.1.1.17` | Verificar se número real coincide com etiqueta e painel |
| Printer-MIB | `prtMarkerCounterUnit` | `1.3.6.1.2.1.43.10.2.1.3` | 7=impressions, 8=sheets; outras unidades **não são páginas** |
| Printer-MIB | `prtMarkerLifeCount` | `1.3.6.1.2.1.43.10.2.1.4` | Counter32; índice `hrDeviceIndex.prtMarkerIndex` |
| Printer-MIB | `prtMarkerPowerOnCount` | `1.3.6.1.2.1.43.10.2.1.5` | Zera no power cycle, impróprio como total vitalício |
| Printer-MIB | `prtMarkerProcessColorants` | `1.3.6.1.2.1.43.10.2.1.6` | Quantidade de corantes não implica contagem colorida |
| Printer-MIB | `prtMarkerSuppliesClass` | `1.3.6.1.2.1.43.11.1.1.4` | Consumível vs recipiente |
| Printer-MIB | `prtMarkerSuppliesType` | `1.3.6.1.2.1.43.11.1.1.5` | Tipo de consumível |
| Printer-MIB | `prtMarkerSuppliesDescription` | `1.3.6.1.2.1.43.11.1.1.6` | Lista dinâmica, sem assumir índices CMYK fixos |
| Printer-MIB | `prtMarkerSuppliesSupplyUnit` | `1.3.6.1.2.1.43.11.1.1.7` | Sem unidade/capacidade compatível, não calcular % |
| Printer-MIB | `prtMarkerSuppliesMaxCapacity` | `1.3.6.1.2.1.43.11.1.1.8` | -2=unknown, -1=other; não são 0 |
| Printer-MIB | `prtMarkerSuppliesLevel` | `1.3.6.1.2.1.43.11.1.1.9` | -3=some remaining; -2=unknown; -1=other |
| Printer-MIB | `prtMarkerColorantValue` | `1.3.6.1.2.1.43.12.1.1.4` | Cor pelo índice real da tabela |

Referências: https://www.rfc-editor.org/rfc/rfc3805.html , https://www.rfc-editor.org/rfc/rfc2790.html e https://www.rfc-editor.org/rfc/rfc3418.html

### Brother — objetos privados documentados publicamente

| Campo | OID numérico | Garantia |
|---|---|---|
| `BROTHER-MIB::brInfoModelNumber.0` | `1.3.6.1.4.1.2435.2.3.9.4.2.1.5.5.9.0` | Identificação enumerada presente em **algumas gerações históricas**; não equivale ao nome comercial de todo modelo atual e **não é contador**. |

Fonte da definição publicada: https://mibs.observium.org/object/BROTHER-MIB/brInfoModelNumber . A própria fabricante instrui solicitar a MIB privada à Brother: https://help.brother-usa.com/app/answers/detail/a_id/52976/~/how-to-obtain-brother-snmp-mib-information .

Não supor que `brInfoModelNumber` esteja implementado nos modelos DCP/HL/MFC/ADS mais recentes. O catálogo marca `billing_approved: false`; SNMP GET/WALK somente com dispositivo autorizado e credenciais read-only do operador. Não incorporar arquivos MIB privados ao repositório sem direito de redistribuição.

**Não usar** `prtMarkerLifeCount` de forma isolada para identificar P&B, cor, digitalizações, A3/A4, frente e verso, cópias ou produção faturável. Mesmo se a unidade for impressões/sheets, a semântica da cobrança precisa de perfil **modelo + firmware + índice + modalidade** validado antes/depois em hardware real.

## Fluxo para todos os modelos, novos e antigos

1. Operador autoriza o host e a rede no Collector, fornece credencial de consulta **read-only** (preferir SNMPv3 authPriv). Nunca testar comunidades default automaticamente.
2. `GET sysObjectID.0`, `GET sysDescr.0`; `WALK hrDeviceDescr`, `prtGeneralSerialNumber`, `prtMarkerCounterUnit`, `prtMarkerLifeCount`, descrições/tipos/unidades/níveis de suprimentos e `prtMarkerColorantValue`. Os OIDs do catálogo são **bases**, não instâncias `.1.1` fixas.
3. Resolver as tabelas pelos índices completos observados; para toner, correlacionar `hrDeviceIndex`, `prtMarkerSuppliesIndex`, `prtMarkerSuppliesColorantIndex` e `prtMarkerColorantIndex`; níveis -3/-2/-1 não viram zero, alerta falso ou porcentagem.
4. Identificar classe como: `genérico com MIB`, `privado conhecido mas não certificado`, ou `modelo/firmware homologado`. Modelos OEM e equipamentos sem SNMP válido ficam como `não identificado`.
5. Em homologação, conferir etiqueta/serial, firmware, relatório físico, uma impressão A4 P&B, uma impressão colorida, uma cópia, um duplex e uma digitalização **quando aplicável**, com deltas de cada contador antes/depois. Repetir em rede offline, reinício, troca de cartucho e atualização de firmware.
6. Guardar **revisão imutável do perfil** com `sysObjectID`, modelo, firmware, serial, OIDs e índices, semântica, unidade, protocolo, fonte da MIB, amostras de teste, operador, data e resultado. Bloquear faturamento automático até homologado.
7. Para cada modelo novo, manter fallback genérico de **inventário/alertas**, sem inventar métricas específicas. Para leitura somente USB ou sem SNMP, exige provider real e independente; PnP/VID:PID não são contadores.

### Famílias prioritárias para ensaio

Estas são *filas de homologação*, não lista de compatibilidade aprovada:

| Marca | Exemplos para montar testes |
|---|---|
| Ricoh | MP C4504/C4502, IM C, MP 3xxx, P |
| Konica Minolta | bizhub C2xx/C3xx/C4xx, i-Series, Accurio |
| Kyocera | ECOSYS MA5500ifx, TASKalfa, ECOSYS P/M |
| HP | LaserJet Pro/Enterprise, PageWide, OfficeJet |
| Samsung | ProXpress SL-M, Xpress SL-M, MultiXpress |
| Canon | imageRUNNER ADVANCE/DX, imageCLASS/i-SENSYS, PIXMA/MAXIFY |
| Epson | EcoTank L6270, WorkForce WF-4820, WF-C, SureColor |
| Xerox | WorkCentre 78xx, AltaLink B/C, VersaLink B/C, Phaser |
| Brother | DCP-L, HL-L, MFC-L, MFC-J, DCP-J, ADS (scanners), impressoras de etiquetas com SNMP quando documentado |

### Catálogo disponível no Nexa

O módulo `collector/core/src/mib_registry.rs` reúne todas as nove PENs, 19 OIDs padronizados, interpretação segura de índices/counters e interpretação de capacidade/nível. Execute o comando **offline**, sem IP ou credencial:

```sh
nexa-collector-daemon --mib-catalog
```

Retorna JSON com fabricante, OIDs, fontes e `billing_approved: false` para cada objeto não homologado. Esse catálogo **não** implementa ainda SNMPv3, SNMP WALK, upload de suprimentos ou aprovação automática de equipamentos no portal. Não faz nenhuma varredura por conta própria.

## Critérios de aceitação de produção

Não declarar cobertura dos "todos os modelos" até dispor de inventário real de modelos/firmwares e testes para cada perfil. O padrão facilita incluir modelos novos dinamicamente, mas não cobre sozinho os contadores proprietários de cópia, cor e scan. Registrar evidências no [gate de produção #60](https://github.com/gabriell211/Nexa-System/issues/60).

**Licenças:** não copiar arquivos MIB proprietários de terceiros diretamente para o repositório sem verificar direitos de redistribuição; referenciar o fabricante e permitir instalação local autorizada.
