import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';

const root = new URL('../', import.meta.url).pathname;
const routes = [
  'app/_layout.tsx', 'app/login.tsx', 'app/+not-found.tsx', 'app/(secure)/_layout.tsx',
  'app/(secure)/index.tsx', 'app/(secure)/ticket/[id]/index.tsx',
  'app/(secure)/ticket/[id]/parts.tsx',
  'app/(secure)/ticket/[id]/signature.tsx',
];
for (const route of routes) {
  const file = join(root, route);
  if (!existsSync(file)) throw new Error('Rota ausente: ' + route);
  if (!/export default/.test(readFileSync(file, 'utf8'))) {
    throw new Error('Rota sem componente padrão: ' + route);
  }
}
const layout = readFileSync(join(root, 'app/_layout.tsx'), 'utf8');
if (!layout.includes('Stack.Protected')) throw new Error('Falta proteção de rotas autenticadas.');
const config = JSON.parse(readFileSync(join(root, 'package.json'), 'utf8'));
if (config.main !== 'expo-router/entry') throw new Error('O entry point não usa Expo Router.');
const api = readFileSync(join(root, 'src/api.ts'), 'utf8');
for (const route of ['/mobile/auth/login', '/mobile/auth/me', '/mobile/auth/logout',
  '/mobile/tickets', '/mobile/stock-items', '/part-requests', '/signature']) {
  if (!api.includes(route)) throw new Error('Contrato Laravel ausente: ' + route);
}
console.log('Rotas Expo Router, fallback e contratos Laravel presentes.');
