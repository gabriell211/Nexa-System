# Nexa — identidade compartilhada

## Marca oficial

A marca utilizada pelo **Nexa System (portal administrativo)** e pelo **Nexa Técnico (mobile e perfil técnico do portal)** utiliza o mesmo símbolo N azul tecnológico com dois nós de conexão. O símbolo é mantido em formato WebP no repositório, sem letras genéricas substitutas.

- Fonte visual principal: `app-mobile-tecnico/assets/brand-mark.webp`.
- Cópias estáticas no portal: `portal/public/brand-mark.webp` e `portal/public/favicon.webp` (mesmos bytes).
- O mobile gera os arquivos PNG nativos Android/iOS a partir do WebP usando `npm run brand:generate`.
- Uso no portal: `portal/src/components/BrandLogo.tsx` para login, sidebar e topo.

## Paleta canônica

| Token | Hex | Uso |
|---|---|---|
| Navy | `#071B40` | Sidebar, títulos, base escura |
| Blue | `#0869D9` | CTA, destaques, links |
| Blue dark | `#074EA7` | Hover e foco |
| Cyan | `#08B5F6` | Detalhes e contraste na marca |
| Background | `#F3F7FD` | Superfícies claras |
| Surface | `#FFFFFF` | Cartões e áreas de leitura |

A aplicação segue o princípio de **marca única, produtos identificados pelo nome**: o portal é `Nexa System`; o app mobile é `Nexa Técnico`. Nenhuma tela altera o logotipo fornecido, nem cria uma marca paralela.

## Verificação

- Portal administrativo: `cd portal && npm ci && npm run build`.
- App técnico: `cd app-mobile-tecnico && npm install && npm run brand:check && npm run typecheck`.
- Confirmar manualmente login/sidebar/topbar do portal, favicon, login/lista/atendimento do mobile e ícones de instalação Android/iOS.
