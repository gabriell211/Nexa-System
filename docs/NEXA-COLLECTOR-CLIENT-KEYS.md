# Credenciais do Collector por cliente

O Nexa **não usa uma chave permanente compartilhada por todas as instalações de um cliente**.
Cada instalação do Collector é cadastrada como agente independente, vinculado ao tenant
e cliente selecionados. A credencial contém:

- `collector_id`: UUID público da instalação, usado no arquivo `config.json`.
- `collector_token`: segredo aleatório de 32 bytes (64 caracteres hexadecimais), usado
  como Bearer token ou arquivo protegido de credencial do serviço.

## Emissão no painel

1. Entre com um usuário **owner** ou **admin** do tenant.
2. Acesse **Clientes**, localize um cliente ativo e clique no ícone de chave.
3. Confirme **Gerar chave do Collector** e copie o **ID** e a **chave**.
4. Provisione o Collector no equipamento do cliente com o ID e a chave. Cada
   equipamento/agente deve ter credencial própria, sem reutilização.
5. Feche a janela. A chave original **não é recuperável**; somente seu
   hash SHA-256 é armazenado em `collector_agents.token_hash`.

O endpoint autenticado `POST /api/v1/browser/customers/{customer}/collector-credentials`
(em clientes de API legados: `/api/v1/customers/{customer}/collector-credentials`)
responde com `customer_id`, `collector_id` e `collector_token`, uma única vez,
com `Cache-Control: no-store, private`. Emissões são auditadas **sem** salvar o
token original ou seu hash no histórico.

O `collector_id` não autentica sozinho. A chave deve ser fornecida
separadamente, de modo seguro e sem anexá-la a tickets, capturas de tela ou
logs. A criação da credencial por si só **não instala, ativa ou configura**
o daemon: agenda, faixas, SNMP e a vinculação continuam no agente.

## Revogação e substituição

Emitir uma credencial nova **não revoga** as antigas. Para substituir um Collector,
revogue o ID antigo com `php artisan nexa:collector-revoke {collector_id}` após
migrar a instalação. O serviço deixa de autenticar imediatamente após a revogação.

É possível emitir credenciais pelo comando administrativo
`php artisan nexa:collector-register {customer_id}`; ele conserva a mesma
política de segredo exibido apenas uma vez.

## Segurança

- Permissões verificadas no servidor (somente owner/admin), além da interface.
- O cliente é buscado dentro do tenant autenticado; ID de outro tenant retorna 404.
- Clientes inativos não recebem credenciais novas.
- Requisição via navegador usa sessão HttpOnly e validação de CSRF; emissão
  possui limite de chamadas.
- Nunca há endpoint GET para revelar tokens antigos; os recursos de cliente,
  agentes e auditoria não retornam o segredo.
