import { randomUUID } from 'node:crypto';
import { expect, test, type APIRequestContext, type Page } from '@playwright/test';

const baseUrl = 'http://127.0.0.1:5173';
const apiRoot = baseUrl + '/api/v1/browser';
const tenant = process.env.NEXA_BOOTSTRAP_TENANT ?? 'nexa-demo';
const email = process.env.NEXA_BOOTSTRAP_EMAIL ?? '';
const password = process.env.NEXA_BOOTSTRAP_PASSWORD ?? '';

test.skip(({ isMobile }) => isMobile, 'A entrada documentada é validada no job Chromium desktop.');

async function login(page: Page): Promise<void> {
  await page.goto(baseUrl);
  await page.getByLabel('Identificador da empresa').fill(tenant);
  await page.getByLabel('E-mail').fill(email);
  await page.getByLabel('Senha').fill(password);
  await page.getByRole('button', { name: /Entrar no painel/ }).click();
  await expect(page.getByRole('heading', { name: 'Visão geral' })).toBeVisible();
}

async function post(request: APIRequestContext, path: string, payload: object): Promise<number> {
  const csrfResponse = await request.get(apiRoot + '/auth/csrf');
  expect(csrfResponse.status()).toBe(200);
  const csrf: { csrf_token: string } = await csrfResponse.json();
  const response = await request.post(apiRoot + path, {
    data: payload,
    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf.csrf_token },
  });
  expect(response.status(), path + ': ' + await response.text()).toBe(201);
  const result: { data: { id: number } } = await response.json();
  return result.data.id;
}

async function count(request: APIRequestContext, path: string): Promise<number> {
  const response = await request.get(apiRoot + path + '?per_page=1');
  expect(response.status(), await response.text()).toBe(200);
  const result: { meta?: { total: number }; total?: number } = await response.json();
  return result.meta?.total ?? result.total ?? -1;
}

test('entrada documentada com fornecedor, lote e custo persiste uma só movimentação', async ({ page }) => {
  expect(email).not.toBe('');
  expect(password.length).toBeGreaterThanOrEqual(12);
  await login(page);
  const request = page.context().request;

  const suffix = randomUUID().slice(0, 8).toUpperCase();
  const code = 'E2E-RCPT-SUP-' + suffix;
  const sku = 'E2E-RCPT-ITEM-' + suffix;
  const locationName = 'Depósito de entrada ' + suffix;
  const invoice = 'NF-E2E-' + suffix;
  const lot = 'LOTE-E2E-' + suffix;

  const supplierId = await post(request, '/stock/suppliers', {
    code, name: 'Distribuidora do teste ' + suffix,
  });
  const itemId = await post(request, '/stock/items', {
    sku, name: 'Toner de entrada ' + suffix, category: 'toner', unit: 'un',
  });
  const locationId = await post(request, '/stock/locations', {
    name: locationName, kind: 'provider',
  });

  const previousReceipts = await count(request, '/stock/receipts');
  const previousMovements = await count(request, '/stock/movements');

  await page.goto(baseUrl + '/estoque');
  await page.getByRole('button', { name: 'Entradas', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Recebimentos com documento' })).toBeVisible();

  await page.getByLabel('Buscar fornecedor para entrada').fill(code);
  await page.getByLabel('Fornecedor *', { exact: true })
    .selectOption({ label: code + ' — Distribuidora do teste ' + suffix });
  await page.getByLabel('Buscar material para entrada').fill(sku);
  await page.getByLabel('Material *', { exact: true })
    .selectOption({ label: sku + ' — Toner de entrada ' + suffix });
  await page.getByLabel('Buscar local para entrada').fill(locationName);
  await page.getByLabel('Local de entrada *', { exact: true })
    .selectOption({ label: locationName });

  await page.getByLabel('Nota / documento *').fill(invoice);
  await page.getByLabel('Número do lote *').fill(lot);
  await page.getByLabel('Quantidade recebida *').fill('3');
  await page.getByLabel('Custo unitário (R$) *').fill('68,75');
  await page.getByLabel('Justificativa *').fill('Recebimento físico conferido pelo almoxarifado');

  await page.getByRole('button', { name: 'Confirmar entrada documentada' }).click();
  await expect(page.getByRole('status').filter({ hasText: 'registrada com auditoria.' })).toBeVisible();

  const row = page.getByRole('row').filter({ hasText: invoice });
  await expect(row).toBeVisible();
  await expect(row.getByText(lot)).toBeVisible();
  await expect(row.getByText(sku)).toBeVisible();
  await expect(row.getByText(locationName)).toBeVisible();
  await expect(row.getByText('R$ 68,75')).toBeVisible();

  expect(await count(request, '/stock/receipts')).toBe(previousReceipts + 1);
  expect(await count(request, '/stock/movements')).toBe(previousMovements + 1);

  const response = await request.get(apiRoot + '/stock/receipts?per_page=100');
  expect(response.status()).toBe(200);
  const result: { data: Array<{
    supplier_id: number; invoice_reference: string; lot_number: string; unit_cost_cents: number;
    movement: { item_id: number; destination_location_id: number; quantity: number };
  }> } = await response.json();
  expect(result.data.find(record => record.invoice_reference === invoice)).toMatchObject({
    supplier_id: supplierId, lot_number: lot, unit_cost_cents: 6875,
    movement: { item_id: itemId, destination_location_id: locationId, quantity: 3 },
  });

  await page.reload();
  await page.getByRole('button', { name: 'Entradas', exact: true }).click();
  await expect(page.getByRole('row').filter({ hasText: invoice })).toBeVisible();
});
