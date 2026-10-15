import { execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { expect, test, type APIRequestContext, type Page } from '@playwright/test';

const baseUrl = process.env.NEXA_STOCK_COUNT_E2E_URL ?? 'http://127.0.0.1:5173';
const password = process.env.NEXA_BOOTSTRAP_PASSWORD ?? '';
const tenant = process.env.NEXA_BOOTSTRAP_TENANT ?? 'nexa-demo';
const ownerEmail = process.env.NEXA_BOOTSTRAP_EMAIL ?? '';
const apiRoot = `${baseUrl}/api/v1/browser`;

type RecordWithId = { data: { id: number } };

async function login(page: Page, email: string): Promise<void> {
  await page.goto(baseUrl);
  await page.getByLabel('Identificador da empresa').fill(tenant);
  await page.getByLabel('E-mail').fill(email);
  await page.getByLabel('Senha').fill(password);
  await page.getByRole('button', { name: /Entrar no painel/ }).click();
  await expect(page.getByRole('heading', { name: 'Visão geral' })).toBeVisible();
}

async function createStock(request: APIRequestContext, path: string, payload: object): Promise<number> {
  const csrfResponse = await request.get(`${apiRoot}/auth/csrf`);
  expect(csrfResponse.status()).toBe(200);
  const csrf: { csrf_token: string } = await csrfResponse.json();
  const response = await request.post(`${apiRoot}${path}`, {
    data: payload,
    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf.csrf_token },
  });
  expect(response.status(), `${path}: ${await response.text()}`).toBe(201);
  return ((await response.json()) as RecordWithId).data.id;
}

test.skip(({ isMobile }) => isMobile, 'Uma execução desktop usa dois logins e preserva o limite real de 5 logins/minuto no CI.');

test('contador registra divergência e outra pessoa aprova o ajuste no estoque real', async ({ page, browser }) => {
  expect(ownerEmail).not.toBe('');
  expect(password.length).toBeGreaterThanOrEqual(12);
  execFileSync('php', [fileURLToPath(new URL('../../scripts/fixtures/seed_stock_count_e2e.php', import.meta.url))], {
    cwd: fileURLToPath(new URL('../../backend', import.meta.url)),
    env: process.env,
    stdio: 'pipe',
  });

  const suffix = randomUUID().slice(0, 8).toUpperCase();
  const sku = `E2E-COUNT-${suffix}`;
  const locationName = `Depósito contagem ${suffix}`;

  await test.step('Owner prepares a real item, location and opening balance through the browser API', async () => {
    await login(page, ownerEmail);
    const request = page.context().request;
    const item = await createStock(request, '/stock/items', {
      sku, name: `Toner contagem ${suffix}`, category: 'toner', unit: 'un',
    });
    const location = await createStock(request, '/stock/locations', {
      name: locationName, kind: 'provider',
    });
    await createStock(request, '/stock/movements', {
      request_id: randomUUID(), kind: 'receipt', item_id: item,
      source_location_id: null, destination_location_id: location,
      quantity: 10, reason: 'Entrada inicial para contagem E2E',
    });
  });

  const warehouseContext = await browser.newContext({ baseURL: baseUrl, viewport: { width: 1280, height: 900 } });
  try {
    const counter = await warehouseContext.newPage();
    await test.step('Warehouse records eight units and sees that review needs a manager', async () => {
      await login(counter, 'stock-counter@example.test');
      await counter.goto(`${baseUrl}/estoque`);
      await counter.getByRole('button', { name: 'Contagens', exact: true }).click();
      await counter.getByLabel('Buscar item para contagem').fill(sku);
      await counter.getByLabel('Item *').selectOption({ label: `${sku} — Toner contagem ${suffix}` });
      await counter.getByLabel('Buscar local para contagem').fill(locationName);
      await counter.getByLabel('Local *').selectOption({ label: locationName });
      await counter.getByLabel('Quantidade contada *').fill('8');
      await counter.getByLabel('Justificativa *').fill('Duas unidades ausentes na conferência física');
      await counter.getByRole('button', { name: 'Registrar contagem' }).click();
      const dialog = counter.getByRole('dialog');
      await expect(dialog.getByText('Pendente')).toBeVisible();
      await expect(dialog.getByText('A revisão está disponível para owner, admin ou manager.')).toBeVisible();
      await expect(dialog.getByRole('button', { name: 'Aprovar contagem' })).toHaveCount(0);
      await dialog.getByRole('button', { name: 'Fechar contagem' }).click();
      await expect(counter.getByRole('row').filter({ hasText: sku }).getByText('Pendente')).toBeVisible();
    });

    await test.step('Owner approves and sees the balance plus ledger movement', async () => {
      await page.goto(`${baseUrl}/estoque`);
      await page.getByRole('button', { name: 'Contagens', exact: true }).click();
      const countRow = page.getByRole('row').filter({ hasText: sku });
      await countRow.getByRole('button', { name: 'Detalhes / revisão' }).click();
      const dialog = page.getByRole('dialog');
      await expect(dialog.getByText('10 un')).toBeVisible();
      await expect(dialog.getByText('8 un')).toBeVisible();
      await expect(dialog.getByText('-2 un')).toBeVisible();
      await dialog.getByLabel('Justificativa da decisão *').fill('Divergência validada por segunda pessoa');
      await dialog.getByRole('button', { name: 'Aprovar contagem' }).click();
      await expect(dialog.getByText('Aprovada')).toBeVisible();
      await expect(dialog.getByText(/Movimentação de ajuste: #\d+/)).toBeVisible();
      await dialog.getByRole('button', { name: 'Fechar contagem' }).click();

      await page.getByRole('button', { name: 'Saldos', exact: true }).click();
      const balanceRow = page.getByRole('row').filter({ hasText: sku });
      await expect(balanceRow.getByText('8 un')).toHaveCount(2); // físico e disponível
      await page.getByRole('button', { name: 'Movimentações', exact: true }).click();
      const movementRow = page.getByRole('row').filter({ hasText: sku }).filter({ hasText: 'Baixa' });
      await expect(movementRow.getByText('2', { exact: true })).toBeVisible();
      await expect(movementRow.getByText('Divergência validada por segunda pessoa')).toBeVisible();
    });
  } finally {
    await warehouseContext.close();
  }
});
