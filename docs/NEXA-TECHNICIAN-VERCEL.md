# Nexa Técnico ↔ Laravel na Vercel

O aplicativo Expo roda no dispositivo Android/iOS e aponta automaticamente para
`https://nexa-system-5nkp.vercel.app/api/v1`.
`EXPO_PUBLIC_NEXA_API_URL` permite apontar para homologação sem alterar o código.

## Contratos

- `POST /mobile/auth/login`: login global e senha (somente técnico); Sanctum com 12 horas.
- `GET /mobile/auth/me`: verificação do token armazenado com SecureStore.
- `GET /mobile/tickets`: somente OS atribuídas ao técnico.
- `POST /mobile/tickets/{id}/start`: início de atendimento.
- `GET /mobile/stock-items` e `POST /mobile/tickets/{id}/part-requests`: peças.
- `POST /mobile/tickets/{id}/signature`: aceite assinado, SHA-256 e resolução da OS.
- `GET /tickets/{id}/signature/image`: recuperação autenticada de evidência, inclusive em outra instância de servidor.

A evidência PNG é criptografada via `APP_KEY` e persistida no PostgreSQL
(`service_ticket_signatures.png_ciphertext`), não no filesystem efêmero da Vercel.
Manter a mesma `APP_KEY` entre deploys é obrigatório para recuperação de evidências.
A tabela não deve ser exibida em API Resources; apenas a rota autorizada pode
descriptografar e transmitir a assinatura. São aceitas assinaturas históricas
armazenadas localmente em ambientes on-premises que ainda possuam os arquivos.

## Testes

`npm run backend:smoke` em `app-mobile-tecnico/` verifica HTTPS, JSON e status de rotas sem credenciais.
`php artisan test --filter=TechnicianMobileWorkflowTest` valida login, isolamento, OS,
peças, assinatura, recuperação sem arquivo local e fechamento administrativo.

**Limite da homologação remota:** a consulta pública não realiza login de técnico.
O banco de demonstração precisa ter uma conta técnica criada e uma OS atribuída,
e o APK/iOS ainda requer execução real no aparelho. Não publique senhas de teste.
