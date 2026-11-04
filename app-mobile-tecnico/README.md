# Nexa Técnico — aplicativo Android e iOS

Módulo Expo/React Native + TypeScript para técnicos de campo. **Implementação inicial; não homologado em produção.**

## Fluxo operacional implementado

1. O administrativo cria um chamado na API existente e atribui `assigned_user_id` ao técnico.
2. O técnico entra com **nome de usuário exclusivo no Nexa e senha individual**, definida por ele no convite de ativação; enxerga **apenas chamados atribuídos a si**.
3. No chamado, visualiza cliente, descrição, prioridade e impressora; inicia o atendimento.
4. Pode consultar o catálogo e solicitar peças (quantidade, motivo, UUID de idempotência).
5. A equipe responsável revisa a solicitação pelo endpoint administrativo. **Aprovar a peça não dá baixa automática de estoque.**
6. Após a manutenção, registra o serviço executado e coleta o nome e a assinatura desenhada na tela pelo responsável do cliente, com aceite explícito.
7. A API valida o PNG, salva a evidência em armazenamento privado, calcula SHA-256, registra usuário, data, IP e dispositivo, e resolve o chamado na mesma transação. O encerramento administrativo permanece separado.

## Identidade visual

O ícone com o monograma N de circuitos azuis foi extraído da logo aprovada pelo projeto. O arquivo versionado é `assets/brand-mark.webp`. No `npm install`, o script `scripts/prepare-brand-assets.mjs` gera:

- `assets/brand-mark.png` para a interface React Native.
- `assets/app-icon.png` para o aplicativo instalado.
- `assets/adaptive-icon.png` para o ícone adaptativo Android.
- `assets/splash.png` para a tela inicial.

Os PNGs são derivados, não precisam ser versionados; todos podem ser recriados com `npm run brand:generate` e validados por `npm run brand:check`. A paleta corporativa fica centralizada em `src/brand/theme.ts`, e o componente visual da marca em `src/brand/BrandIdentity.tsx`.

### Desenvolvimento local

Requer Node LTS e Expo SDK 54.

```bash
cd app-mobile-tecnico
cp .env.example .env
# A URL oficial https://nexa-system-5nkp.vercel.app/api/v1 já é o padrão
# Substitua EXPO_PUBLIC_NEXA_API_URL apenas para homologação ou desenvolvimento
npm install
npx expo install --fix
npm run typecheck
npm start
```

Para um celular físico em desenvolvimento, a URL deve ser alcançável pelo aparelho. Em produção, **HTTPS é obrigatório**. O app funciona no Android/iOS e se comunica com o backend Laravel hospedado na Vercel, usando `https://nexa-system-5nkp.vercel.app/api/v1` por padrão. É necessário migrar o backend antes de testar operações reais.

O comando `npm run backend:smoke` consulta a API de produção sem credenciais e verifica os contratos de autenticação. O CI mobile também o executa. Isso não substitui um teste autenticado num dispositivo físico.

## Contratos disponíveis

- `POST /api/v1/mobile/auth/login`, `GET /api/v1/mobile/auth/me`, `POST /api/v1/mobile/auth/logout`
- `GET /api/v1/mobile/tickets`, `GET /api/v1/mobile/tickets/{id}`, `POST /api/v1/mobile/tickets/{id}/start`
- `GET /api/v1/mobile/stock-items?q=...`, `POST /api/v1/mobile/tickets/{id}/part-requests`
- `POST /api/v1/mobile/tickets/{id}/signature`
- Administrativo: `GET /api/v1/tickets/part-requests`, `POST /api/v1/tickets/part-requests/{id}/review`

A autenticação mobile usa **`login_name` + senha** (não e-mail), tokens Sanctum por empresa, com validade de 12 horas e armazenamento no SecureStore do aparelho. Os tokens mobile só podem acessar `/api/v1/mobile/*`. O administrativo escolhe um nome de usuário globalmente exclusivo ao criar a conta do colaborador e entrega um convite de ativação de uso único: a senha não é compartilhada com o administrador. Técnicos antigos recebem um nome de usuário migrado automaticamente; a equipe pode consultá-lo na lista de contas.

## Pontos ainda não homologados

- Integração visual da fila de aprovação no portal administrativo.
- Transferência/baixa de estoque a partir de solicitação aprovada.
- Upload de fotografias e vídeos pelo aplicativo.
- Notificações push, fila offline e renovação de sessão.
- Testes reais em Android/iOS, assinatura com caneta e exigências jurídicas específicas do cliente.

**Assinatura eletrônica simples:** comprova o aceite registrado no sistema e preserva evidência, mas não constitui automaticamente assinatura qualificada ICP-Brasil. A identidade e a autorização do signatário devem ser conferidas pelo técnico.

## Navegação e build Android v0.2.0

Expo Router 6: login público e páginas protegidas de chamados, detalhe da OS, solicitação de peças e assinatura. O Stack nativo controla o botão Voltar e os links diretos. A API Laravel continua sendo a autoridade de acesso por token e OS atribuída.

npm install; npm run typecheck; npm run routes:check; npm run bundle:android.

O perfil EAS 'preview' gera APK instalável para testes e 'production' gera AAB. Uma publicação assinada precisa de credenciais Android gerenciadas; APK de teste não deve ser distribuído como release final.
