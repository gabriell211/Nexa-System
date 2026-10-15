import { randomUUID } from 'node:crypto';
import { expect, test, type APIRequestContext, type Page } from '@playwright/test';

const baseUrl = process.env.NEXA_REORDER_E2E_URL ?? 'http://127.0.0.1:5173';
const apiRoot = `${baseUrl}/api/v1/browser`;
const ownerEmail = process.env.NEXA_BOOTSTRAP_EMAIL ?? '';
const password = process.env.NEXA_BOOTSTRAP_PASSWORD ?? '';
const tenant = process.env.NEXA_BOOTSTRAP_TENANT ?? 'nexa-demo';

async function login(page: Page): Promise<void> {
  await page.goto(baseUrl);
  await page.getByLabel('Identificador da empresa').fill(tenant);
  await page.getByLabel('E-mail').fill(ownerEmail);
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
  return ((await response.json()) as { data: { id: number } }).data.id;
}

test.skip(({ isMobile }) => isMobile, 'Um cenário desktop cobre o fluxo sem consumir logins extras no limite real por IP.');

test('política de reposição mostra saldo zero, sugestão, filtros, edição e remoção', async ({ page }) => {
  expect(ownerEmail).not.toBe('');
  expect(password.length).toBeGreaterThanOrEqual(12);

  await login(page);

  const suffix = randomUUID().slice(0, 8).toUpperCase();
  const sku = `E2E-REORDER-${suffix}`;
  const itemName = `Toner reposição ${suffix}`;
  const locationName = `Depósito reposição ${suffix}`;
  const request = page.context().request;

  await test.step('Cadastrar item e local sem registrar entrada de estoque', async () => {
    await createStock(request, '/stock/items', {
      sku, name: itemName, category: 'toner', unit: 'un',
    });
    await createStock(request, '/stock/locations', {
      name: locationName, kind: 'provider',
    });
  });

  await page.goto(`${baseUrl}/estoque`);
  await page.getByRole('button', { name: 'Necessidades', exact: true }).click();
  await expect(page.getByText('Nenhuma compra ou remessa é criada automaticamente.')).toBeVisible();

  await test.step('Configurar limites e observar necessidade crítica com saldo zero', async () => {
    await page.getByLabel('Buscar item para política').fill(sku);
    await page.getByLabel('Item *').selectOption({ label: `${sku} — ${itemName}` });
    await page.getByLabel('Buscar local para política').fill(locationName);
    await page.getByLabel('Local *').selectOption({ label: locationName });
    await page.getByLabel('Crítico *').fill('2');
    await page.getByLabel('Segurança *').fill('4');
    await page.getByLabel('Mínimo *').fill('6');
    await page.getByLabel('Alvo *').fill('12');
    await page.getByRole('button', { name: 'Salvar política' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Política de reposição salva.' })).toBeVisible();

    const row = page.getByRole('region', { name: 'Necessidades de reposição' })
      .getByRole('row').filter({ hasText: sku });
    await expect(row).toBeVisible();
    await expect(row.locator('td').nth(2)).toHaveText('0 un');
    await expect(row.locator('td').nth(5)).toHaveText('0 un');
    await expect(row.locator('td').nth(6)).toHaveText('2 / 4 / 6 / 12');
    await expect(row.locator('td').nth(7)).toHaveText('12 un');
    await expect(row.locator('td').nth(8)).toHaveText('Crítico');
  });

  const row = page.getByRole('region', { name: 'Necessidades de reposição' })
    .getByRole('row').filter({ hasText: sku });
  await test.step('Filtrar por estado e voltar às necessidades', async () => {
    await page.getByLabel('Mostrar').selectOption('ok');
    await expect(row).toHaveCount(0);
    await page.getByLabel('Mostrar').selectOption('critical');
    await expect(row).toBeVisible();
    await page.getByLabel('Mostrar').selectOption('needs');
    await expect(row).toBeVisible();
  });

  await test.step('Editar o alvo e remover a política pela interface', async () => {
    await row.getByRole('button', { name: 'Editar' }).click();
    await expect(page.getByRole('heading', { name: 'Editar política de reposição' })).toBeVisible();
    await page.getByLabel('Alvo *').fill('15');
    await page.getByRole('button', { name: 'Salvar política' }).click();
    await expect(row.locator('td').nth(6)).toHaveText('2 / 4 / 6 / 15');
    await expect(row.locator('td').nth(7)).toHaveText('15 un');

    page.once('dialog', dialog => dialog.accept());
    await page.getByRole('button', { name: 'Remover política' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Política de reposição removida.' })).toBeVisible();
    await expect(row).toHaveCount(0);
  });
});
