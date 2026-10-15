import { randomUUID } from 'node:crypto';
import { expect, test, type APIRequestContext, type Page } from '@playwright/test';

const baseUrl = 'http://127.0.0.1:5173';
const apiRoot = baseUrl + '/api/v1/browser';
const tenant = process.env.NEXA_BOOTSTRAP_TENANT ?? 'nexa-demo';
const email = process.env.NEXA_BOOTSTRAP_EMAIL ?? '';
const password = process.env.NEXA_BOOTSTRAP_PASSWORD ?? '';

test.skip(({ isMobile }) => isMobile, 'Recebimento em massa usa o job desktop dedicado.');

async function post(request: APIRequestContext, path: string, payload: object): Promise<number> {
  const session = await request.get(apiRoot + '/auth/csrf');
  expect(session.status()).toBe(200);
  const csrf: { csrf_token: string } = await session.json();
  const result = await request.post(apiRoot + path, {
    data: payload,
    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf.csrf_token },
  });
  expect(result.status(), path + ': ' + await result.text()).toBe(201);
  return (await result.json() as { data: { id: number } }).data.id;
}

async function total(request: APIRequestContext, path: string): Promise<number> {
  const result = await request.get(apiRoot + path + '?per_page=1');
  expect(result.status(), await result.text()).toBe(200);
  const body: { total?: number; meta?: { total: number } } = await result.json();
  return body.total ?? body.meta?.total ?? -1;
}

async function login(page: Page): Promise<void> {
  await page.goto(baseUrl);
  await page.getByLabel('Identificador da empresa').fill(tenant);
  await page.getByLabel('E-mail').fill(email);
  await page.getByLabel('Senha').fill(password);
  await page.getByRole('button', { name: /Entrar no painel/ }).click();
  await expect(page.getByRole('heading', { name: 'Visão geral' })).toBeVisible();
}

test('nota com dois itens registra dois lotes e apenas duas movimentações', async ({ page }) => {
  expect(email).not.toBe('');
  expect(password.length).toBeGreaterThanOrEqual(12);
  await login(page);
  const request = page.context().request;
  const suffix = randomUUID().slice(0, 8).toUpperCase();
  const code = 'E2E-MULTI-SUP-' + suffix;
  const skuA = 'E2E-MULTI-A-' + suffix;
  const skuB = 'E2E-MULTI-B-' + suffix;
  const locationName = 'Depósito multi ' + suffix;
  const invoice = 'NF-MULTI-' + suffix;

  await post(request, '/stock/suppliers', { code, name: 'Fornecedor em lote ' + suffix });
  await post(request, '/stock/items', {
    sku: skuA, name: 'Toner multi A ' + suffix, category: 'toner',
  });
  await post(request, '/stock/items', {
    sku: skuB, name: 'Toner multi B ' + suffix, category: 'toner',
  });
  await post(request, '/stock/locations', { name: locationName, kind: 'provider' });

  const movementsBefore = await total(request, '/stock/movements');
  const receiptsBefore = await total(request, '/stock/receipts');

  await page.goto(baseUrl + '/estoque');
  await page.getByRole('button', { name: 'Entradas', exact: true }).click();
  const bulk = page.getByRole('region', { name: 'Recebimento em lote' });

  await bulk.getByLabel('Buscar fornecedor da nota').fill(code);
  await bulk.getByLabel('Fornecedor da nota *', { exact: true })
    .selectOption({ label: code + ' — Fornecedor em lote ' + suffix });
  await bulk.getByLabel('Referência da nota *').fill(invoice);
  await bulk.getByLabel('Justificativa do recebimento *')
    .fill('Recebimento em lote conferido fisicamente no depósito');

  await bulk.getByLabel('Buscar material do item 1').fill(skuA);
  await bulk.getByLabel('Material do item 1 *', { exact: true })
    .selectOption({ label: skuA + ' — Toner multi A ' + suffix });
  await bulk.getByLabel('Buscar destino do item 1').fill(locationName);
  await bulk.getByLabel('Destino do item 1 *', { exact: true })
    .selectOption({ label: locationName });
  await bulk.getByLabel('Quantidade do item 1 *').fill('2');
  await bulk.getByLabel('Lote do item 1 *').fill('LOTE-A-' + suffix);
  await bulk.getByLabel('Custo unitário do item 1 (R$) *').fill('68,75');

  await bulk.getByRole('button', { name: 'Adicionar item à nota' }).click();
  await bulk.getByLabel('Buscar material do item 2').fill(skuB);
  await bulk.getByLabel('Material do item 2 *', { exact: true })
    .selectOption({ label: skuB + ' — Toner multi B ' + suffix });
  await bulk.getByLabel('Buscar destino do item 2').fill(locationName);
  await bulk.getByLabel('Destino do item 2 *', { exact: true })
    .selectOption({ label: locationName });
  await bulk.getByLabel('Quantidade do item 2 *').fill('3');
  await bulk.getByLabel('Lote do item 2 *').fill('LOTE-B-' + suffix);
  await bulk.getByLabel('Custo unitário do item 2 (R$) *').fill('72,50');

  await bulk.getByRole('button', { name: 'Confirmar todos os itens da nota' }).click();
  await expect(bulk.getByRole('status').filter({
    hasText: '2 linhas registradas.',
  })).toBeVisible();

  expect(await total(request, '/stock/movements')).toBe(movementsBefore + 2);
  expect(await total(request, '/stock/receipts')).toBe(receiptsBefore + 2);

  await expect(page.getByRole('row').filter({ hasText: 'LOTE-A-' + suffix })).toBeVisible();
  await expect(page.getByRole('row').filter({ hasText: 'LOTE-B-' + suffix })).toBeVisible();
  const grouped = page.getByRole('region', { name: 'Notas recebidas em lote' });
  await grouped.getByLabel('Buscar referência da nota').fill(invoice);
  const noteRow = grouped.getByRole('row').filter({ hasText: invoice });
  await expect(noteRow).toBeVisible();
  await noteRow.getByRole('button', { name: 'Ver itens da nota ' + invoice }).click();
  const dialog = page.getByRole('dialog');
  await expect(dialog.getByText('LOTE-A-' + suffix)).toBeVisible();
  await expect(dialog.getByText('LOTE-B-' + suffix)).toBeVisible();
  await dialog.getByRole('button', { name: 'Fechar nota em lote' }).click();
  await page.reload();
  await page.getByRole('button', { name: 'Entradas', exact: true }).click();
  await expect(page.getByRole('row').filter({ hasText: 'LOTE-A-' + suffix })).toBeVisible();
});
