# Nexa Técnico — aplicativo Android e iOS

Módulo Expo/React Native + TypeScript para técnicos de campo. **Implementação inicial; não homologado em produção.**

## Fluxo operacional implementado

1. O administrativo cria um chamado na API existente e atribui `assigned_user_id` ao técnico.
2. O técnico autentica pelo aplicativo e enxerga **apenas chamados atribuídos a si**.
3. No chamado, visualiza cliente, descrição, prioridade e impressora; inicia o atendimento.
4. Pode consultar o catálogo e solicitar peças (quantidade, motivo, UUID de idempotência).
5. A equipe responsável revisa a solicitação pelo endpoint administrativo. **Aprovar a peça não dá baixa automática de estoque.**
6. Após a manutenção, registra o serviço executado e coleta o nome e a assinatura desenhada na tela pelo responsável do cliente, com aceite explícito.
7. A API valida o PNG, salva a evidência em armazenamento privado, calcula SHA-256, registra usuário, data, IP e dispositivo, e resolve o chamado na mesma transação. O encerramento administrativo permanece separado.

### Desenvolvimento local

Requer Node LTS e Expo SDK 54.

```bash
cd app-mobile-tecnico
cp .env.example .env
# configure EXPO_PUBLIC_NEXA_API_URL=https://SEU-SERVIDOR/api/v1
npm install
npx expo install --fix
npm run typecheck
npm start
```

Para um celular físico em desenvolvimento, a URL deve ser alcançável pelo aparelho. Em produção, **HTTPS é obrigatório**. É necessário migrar o backend com `php artisan migrate` antes de testar a integração.

## Contratos disponíveis

- `POST /api/v1/mobile/auth/login`, `GET /api/v1/mobile/auth/me`, `POST /api/v1/mobile/auth/logout`
- `GET /api/v1/mobile/tickets`, `GET /api/v1/mobile/tickets/{id}`, `POST /api/v1/mobile/tickets/{id}/start`
- `GET /api/v1/mobile/stock-items?q=...`, `POST /api/v1/mobile/tickets/{id}/part-requests`
- `POST /api/v1/mobile/tickets/{id}/signature`
- Administrativo: `GET /api/v1/tickets/part-requests`, `POST /api/v1/tickets/part-requests/{id}/review`

A autenticação mobile usa tokens Sanctum por empresa, com validade de 12 horas e armazenamento no SecureStore do aparelho. Os tokens mobile só podem acessar `/api/v1/mobile/*`.

## Pontos ainda não homologados

- Integração visual da fila de aprovação no portal administrativo.
- Transferência/baixa de estoque a partir de solicitação aprovada.
- Upload de fotografias e vídeos pelo aplicativo.
- Notificações push, fila offline e renovação de sessão.
- Testes reais em Android/iOS, assinatura com caneta e exigências jurídicas específicas do cliente.

**Assinatura eletrônica simples:** comprova o aceite registrado no sistema e preserva evidência, mas não constitui automaticamente assinatura qualificada ICP-Brasil. A identidade e a autorização do signatário devem ser conferidas pelo técnico.
