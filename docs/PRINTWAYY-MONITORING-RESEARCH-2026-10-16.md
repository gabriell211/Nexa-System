# Como a PrintWayy monitora equipamentos — evidência pública (16/10/2026)

Esta análise usa somente documentação e catálogo públicos. Uma indicação de "homologado" no catálogo não identifica quais medidores são coletados nem divulga o protocolo USB ou os OIDs de cada modelo.

## Fluxo publicado

1. O PrintWayy Client é instalado no local do equipamento. Para impressoras de rede, o serviço **Net Client Service** consulta diretamente o IP configurado via **SNMP**, grava dados em arquivo `.plog` compactado/criptografado e o **Link Service** o envia por HTTPS. O [FAQ](https://help.printwayy.com/duvidas-frequentes/) menciona consulta a cada 30 minutos; o [tutorial de monitoramento](https://help.printwayy.com/monitorando-impressoras/) menciona 60 minutos. Essas fontes divergem quanto à frequência exata.
2. A [tela de monitoramento](https://help.printwayy.com/monitorando-impressoras/) permite informar IP, configuração SNMP e credenciais HTTP. A comunicação é testada e os contadores encontrados são mostrados **antes** de acionar `Monitorar`. O [FAQ](https://help.printwayy.com/duvidas-frequentes/) cita a senha HTTP como necessária para capturar contadores de alguns lotes da Brother MFC-T4500DW. A descoberta em lote oferece broadcast ou faixa de IP, seguida de `Salvar impressoras` e `Monitorar` como etapas separadas.
3. Para USB, a mesma [documentação](https://help.printwayy.com/monitorando-impressoras/) descreve busca no computador conectado, seleção, salvamento e monitoramento. O [FAQ](https://help.printwayy.com/duvidas-frequentes/) exige um Client em cada host USB; relata efeitos de mudança de porta, registros antigos de número de série, suporte bidirecional e software de status do fabricante. **Não publica** API, comando USB, driver, formato de mensagem ou método de leitura de contadores.
4. O [monitoramento remoto](https://help.printwayy.com/monitorando-remotamente/) usa Client local com varredura configurável de 6 a 168 horas ou reserva prévia de IP. A [homologação de rede](https://help.printwayy.com/dispositivos-homologados/) é descrita como ensaio assistido com impressões A4/A3, preto e branco, cor e digitalização para comparar a variação dos contadores. Isso é uma validação empírica por modelo, não prova de que uma porta aberta baste.
5. A PrintWayy também oferece [lançamento **manual**](https://help.printwayy.com/adicionando-contadores/) de P&B, cor e digitalizações. Portanto, a presença do campo "digitalizações" num relatório não comprova leitura automática desse medidor em todos os modelos.
6. A [instalação do Client](https://help.printwayy.com/instalacao-client/) usa uma chave para vinculá-lo ao cliente e conserva sua lista local de impressoras em uma pasta `Database`. As [notas públicas](https://help.printwayy.com/ultimas-atualizacoes/) citam relatórios agendados de digitalizações, sem especificar como cada modelo fornece o contador.

## Catálogo público consultado

Na [lista de modelos](https://app.printwayy.com/Modelos), cada linha separa o resultado **Rede** do resultado **USB**:

| Modelo | Rede | USB | Relação com o laboratório Nexa |
| --- | --- | --- | --- |
| HP OfficeJet 7740 | Homologado | Ainda não testado | Há endpoint eSCL e portas de impressão no host, mas nenhum contador foi lido pelo Nexa |
| Epson L6270 | Homologado | Homologado | Fila `USB005` existe no Windows; presença física e contadores ainda não comprovados neste host |
| Brother ADS-1000W | Homologado | Ainda não testado | Exemplo de scanner dedicado listado para rede; o catálogo não especifica o medidor |
| Canon DR-S150 | Homologado | Ainda não testado | Exemplo de scanner dedicado Canon listado para rede |
| Canon DR-C225 / DR-C225 II | Não localizado na lista consultada | Não localizado na lista consultada | O Canon USB local foi detectado pelo Nexa, mas não há evidência pública de homologação PrintWayy desse modelo |

## O que a documentação técnica permite concluir

- A [Printer MIB v2, RFC 3805](https://www.rfc-editor.org/rfc/rfc3805.html) define `prtMarkerLifeCount` como contador da unidade indicada por **`prtMarkerCounterUnit`**. O valor isolado não deve ser rotulado automaticamente como páginas faturáveis; índice, unidade, persistência e mapeamento P&B/cor exigem validação por equipamento.
- O [manual Canon DR-C225 II](https://gdlp01.c-wss.com/gds/8/0300041198/01/DR-C225W_II_User_Manual_EN.pdf) mostra `Total Scanning` e `Current Rollers` no utilitário/driver. A documentação consultada não divulga API programática para ler esses valores. O contador de roletes admite reset e não equivale a produção vitalícia.
- A [Brother confirma](https://support.brother.com/g/b/faqend.aspx?c=us&faqid=faq00002892_002&lang=en&prod=ads1000w_us) contador de digitalizações no ADS-1000W usado para manutenção e passível de reset. A [MIB privada Brother](https://help.brother-usa.com/app/answers/detail/a_id/52976/~/how-to-obtain-brother-snmp-mib-information) pode exigir solicitação oficial. Nenhuma dessas fontes demonstra qual mecanismo a PrintWayy usa para esse modelo.

## Implicações para o Nexa Collector

1. Criar perfis de medição por **fabricante, modelo, firmware e interface**, com identidade verificada e origem do contador. Um OID SNMP configurado sem unidade/semântica não basta para faturamento.
2. Para rede, testar SNMP com credencial fornecida pelo operador e consultar todos os OIDs necessários ao perfil; usar HTTP/eSCL somente quando houver campo documentado e validação real. A descoberta eSCL atual do Nexa prova capacidade de scanner, não o total digitalizado.
3. Para USB, integrar apenas um provider documentado e somente leitura por modelo/driver. PnP, fila do Windows e VID:PID são evidências de conexão, não de acesso a contador. Evitar alterar suporte bidirecional, serviços do fabricante ou configuração do equipamento automaticamente.
4. Incluir ensaio antes/depois com operador no equipamento: fotografar/registrar os contadores físicos, executar trabalhos controlados e comparar delta, unidade, canais, resets, comunicação offline e troca de IP/porta. Preservar a distinção entre leitura automática, lançamento manual e dado indisponível.

**Conclusão:** a arquitetura pública da PrintWayy combina agente local, SNMP em rede, configuração HTTP quando necessária, catálogo de modelos/interface e homologação empírica. O método exato de leitura USB e os mapeamentos privados por modelo não estão publicados nas fontes examinadas. O Canon DR-C225 USB local continua homologado apenas para descoberta PnP no Nexa.
