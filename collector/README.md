# Nexa Collector — implementação inicial

A **interface desktop usa Tauri 2.12 + React 19 + TypeScript**. A coleta roda no **daemon Rust separado**. Fechar o console não deve encerrar o daemon, desde que o serviço tenha sido iniciado pelo sistema operacional.

## O que existe no código

- Consulta SNMPv2c de um OID de páginas **explicitamente configurado e homologado** por impressora; apenas contador real é aceito (indisponível não é zero).
- Configuração de equipamentos por IP, ID numérico atribuído pelo backend e rede/CIDR autorizada; não existe varredura automática nesta etapa.
- Outbox persistente em SQLite com modo WAL, chave única por UUID, envio HTTPS e remoção apenas de amostras reconhecidas pelo backend.
- API Laravel autentica coletor por token individual, aceita até 100 leituras, valida propriedade do equipamento e retorna ACK idempotente.
- Tela desktop local de visão geral, impressoras e configuração, alimentada por configuração e estado SQLite reais (não há dados simulados).
- Testes escritos para durabilidade da outbox, validação de CIDR e isolamento na API.

## Limitações importantes — ainda não utilizar como coletor de produção

- **NÃO implementados:** instalador do serviço Windows/systemd/launchd, IPC daemon-console com autenticação, registro pela interface, armazenamento cifrado da fila, cofre de credenciais do sistema, SNMPv3, descoberta automática, USB, perfis por fabricante, alertas, watchdog, update assinado e diagnóstico avançado.
- A UI tem acesso **somente de leitura** à configuração e ao SQLite, não identifica com certeza se o serviço está em execução. O controle por IPC autenticado ainda está pendente.
- O token e a comunidade SNMP são fornecidos por variáveis de ambiente **somente para laboratório**. Para produção, implementar serviço protegido e cofre de segredos do SO.
- Não há teste real de SNMP, execução Cargo, Tauri build ou homologação de impressoras comprovado neste commit.

## Rodar em ambiente de desenvolvimento

Requisitos: Rust 1.90+ (Tauri 2.12), Node.js 22.12+, PHP/Composer e PostgreSQL para a API. O host precisa das dependências nativas do Tauri para seu sistema.

1. Na API, execute as migrations e cadastre tenant, usuário, cliente e impressora via endpoints de administração.
2. No servidor Laravel, gere uma credencial de coletor com `php artisan nexa:collector-register ID_DO_CLIENTE`. O comando mostra um UUID e um token apenas uma vez.
3. Crie um `collector/config.local.json` a partir de `config.example.json`; use o UUID registrado, endpoint HTTPS real, CIDR explicitamente autorizado, ID de impressora e OID validado para o dispositivo.
4. Disponibilize `NEXA_SNMP_COMMUNITY` e `NEXA_COLLECTOR_TOKEN` **apenas no ambiente seguro do processo**, nunca em `config.local.json` ou no repositório.
5. Da pasta `collector`, execute `cargo test -p nexa-collector-core` e `cargo run -p nexa-collector-daemon -- config.local.json ./queue.sqlite --once`.
6. Para a interface, configure `NEXA_COLLECTOR_CONFIG` com o caminho absoluto de `config.local.json` e `NEXA_COLLECTOR_DB` com o caminho absoluto do mesmo SQLite. Em `collector/console`, execute `npm install` e `npm run tauri dev`.

**Exemplo de IPs** usa o bloco de documentação TEST-NET-1, não representa um equipamento real.

## Próximos passos

- Habilitar TLS pinning ou política equivalente, cofre OS e comunicações autenticadas entre daemon e console.
- Implementar instaladores e supervisão nativos e adicionar recursos de configuração/autorização na interface.
- Homologar SNMPv3 por fabricante/modelo, métricas de suprimentos e USB somente onde suportado.
- Validar a outbox com queda de energia, 48h sem rede, ACK parcial, versão de esquema e proteção contra disco cheio.
- Cobrir todos os cenários QA pertinentes antes de afirmar que um requisito do README está concluído.
