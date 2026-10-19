# Menus administrativos e contas de acesso

Referência visual: agrupamento por **Impressoras, Financeiro, Alertas,
Suprimentos, Chamados, Relatórios e Configurações**. Mantém marca e cores
próprias do Nexa. O menu mostra somente rotas cuja API já existe.
Subfunções sem operações homologadas são identificadas como
**Em implantação**, sem números fictícios nem botões inertes.

## Rotas de menu implementadas

| Grupo | Ação | API/tela efetiva |
|---|---|---|
| Impressoras | Parque completo | /parque, `GET /printers` |
| Impressoras | Impressoras por cliente | /parque?view=customer; requer selecionar cliente, filtra pela própria carteira no backend |
| Impressoras | Novas impressoras | /parque?new=1; requer role com gestão de impressoras |
| Financeiro | Contratos | /contratos (faturamento fechado ainda não implementado) |
| Alertas | Ativos, vinculados, ignorados, resolvidos | /alertas?status=..., filtro real na API |
| Suprimentos | Catálogo e fornecedores | /suprimentos; níveis SNMP por modelo ainda não homologados |
| Chamados | Sem responsável, abertos e encerrados | /chamados?status=..., `open_only` da API |
| Relatórios | Histórico de páginas por impressora | /parque, abrir histórico do equipamento |
| Configurações | Contas de usuários | /equipe, restrito a owner/admin |
| Configurações | Agentes de coleta | /agentes, restrito a equipes com acesso |
| Governança | Auditoria | /auditoria, owner/admin |

As demais entradas são desabilitadas e documentam o motivo.
Não copiar contagens de alertas ou contratos de capturas de tela.

## Adicionar conta

Conta **de cliente** ou **de colaborador/técnico** é criada com
`POST /api/v1/browser/accounts` (apenas owner/admin autenticado).
O operador seleciona explicitamente um ou mais clientes ativos da
mesma prestadora. A conta nasce **inativa**, sempre com
`customer_scope=assigned` e nunca com acesso irrestrito à carteira.
Uma única pessoa não é associada silenciosamente a um usuário
preexistente por e-mail.

A API retorna ao administrador um único token aleatório de 256 bits
para enviar por canal seguro ao titular. Só o hash SHA-256 é armazenado.
O link `/ativar#tenant=<slug>&token=<hex>` usa um fragmento,
não um parâmetro enviado ao servidor. A ativação exige senha forte,
tem validade de 24 horas e é de uso único. O link deve ser copiado e
enviado manualmente; **o envio automático de convite ainda não foi
implementado**. Após a ativação, clientes entram no `portal-cliente`
e técnicos no painel interno.

As únicas preferências do print implementadas com enforcement real por
enquanto são: `view_reports` (API analítica e valores de contadores) e
`view_other_tickets` (acesso a chamados de outros solicitantes dentro
dos clientes autorizados). As permissões são verificadas no backend,
não apenas na interface. Preferências de notificações por e-mail,
custos, download de agentes, gestão de clientes/contas e permissões
de endereço individual **não estão liberadas por este PR**. A conta de
colaborador recebe o papel existente `technician`, não uma função
administrativa arbitrária.

Tests: `backend/tests/Feature/AccountProvisioningTest.php`.
A confirmação de e-mail e entrega automática de convites, antispam e
políticas avançadas de conta permanecem gates para produção.
