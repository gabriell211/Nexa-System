import { execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { expect, test, type APIRequestContext, type Page } from '@playwright/test';

const baseUrl = process.env.NEXA_REPLENISHMENT_E2E_URL ?? 'http://127.0.0.1:5173';
const apiRoot = `${baseUrl}/api/v1/browser`;
const tenant = process.env.NEXA_BOOTSTRAP_TENANT ?? 'nexa-demo';
const ownerEmail = process.env.NEXA_BOOTSTRAP_EMAIL ?? '';
const password = process.env.NEXA_BOOTSTRAP_PASSWORD ?? '';

interface PlanResponse {
  data: {
    id: number;
    status: string;
    version: number;
    due_on: string;
    responsible: { id: number; name: string };
    created_by: { id: number; name: string };
    approved_by: { id: number; name: string } | null;
    items: Array<{
      item_id: number;
      origin: string;
      quantity: number;
      safety_item: boolean;
      note: string | null;
      suggested_quantity: number | null;
      item: { sku: string };
    }>;
  };
}

async function login(page: Page, email: string): Promise<void> {
  await page.goto(baseUrl);
  await page.getByLabel('Identificador da empresa').fill(tenant);
  await page.getByLabel('E-mail').fill(email);
  await page.getByLabel('Senha').fill(password);
  await page.getByRole('button', { name: /Entrar no painel/ }).click();
  await expect(page.getByRole('heading', { name: 'Visão geral' })).toBeVisible();
}

async function writeStock(
  request: APIRequestContext,
  method: 'post' | 'put',
  path: string,
  payload: object,
  expectedStatus: number,
): Promise<{ data: { id: number } }> {
  const csrfResponse = await request.get(`${apiRoot}/auth/csrf`);
  expect(csrfResponse.status()).toBe(200);
  const csrf: { csrf_token: string } = await csrfResponse.json();
  const response = await request[method](`${apiRoot}${path}`, {
    data: payload,
    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf.csrf_token },
  });
  expect(response.status(), `${method.toUpperCase()} ${path}: ${await response.text()}`).toBe(expectedStatus);
  return response.json();
}

async function paginatedTotal(request: APIRequestContext, path: string): Promise<number> {
  const response = await request.get(`${apiRoot}${path}?per_page=1`);
  expect(response.status(), `${path}: ${await response.text()}`).toBe(200);
  const body: { meta?: { total?: number }; total?: number } = await response.json();
  return body.meta?.total ?? body.total ?? -1;
}

test.skip(({ isMobile }) => isMobile,
  'O cenário desktop usa dois logins em um job isolado e preserva o limite real de 5 logins/minuto.');

test('necessidade com saldo zero vira proposta editada e aprovada por outra pessoa sem remessa automática', async ({ page, browser }) => {
  expect(ownerEmail).not.toBe('');
  expect(password.length).toBeGreaterThanOrEqual(12);

  // The production bootstrap has only an owner. A guarded fixture adds a warehouse
  // user exclusively to an existing SQLite *e2e* database.
  execFileSync('php', [fileURLToPath(new URL('../../scripts/fixtures/seed_stock_count_e2e.php', import.meta.url))], {
    cwd: fileURLToPath(new URL('../../backend', import.meta.url)),
    env: process.env,
    stdio: 'pipe',
  });

  const suffix = randomUUID().slice(0, 8).toUpperCase();
  const sku = `E2E-PLAN-${suffix}`;
  const manualSku = `E2E-MANUAL-${suffix}`;
  const itemName = `Toner planejamento ${suffix}`;
  const manualName = `Peça manual ${suffix}`;
  const locationName = `Depósito planejamento ${suffix}`;
  const dueOn = new Date(Date.now() + 14 * 86_400_000).toISOString().slice(0, 10);
  const revisedDueOn = new Date(Date.now() + 21 * 86_400_000).toISOString().slice(0, 10);

  await login(page, 'stock-counter@example.test');
  const warehouseRequest = page.context().request;
  let itemId = 0;
  let manualItemId = 0;
  let baselineShipments = 0;
  let baselineMovements = 0;

  await test.step('Cadastrar dois materiais, local e política sem entrada física', async () => {
    itemId = (await writeStock(warehouseRequest, 'post', '/stock/items', {
      sku, name: itemName, category: 'toner', unit: 'un',
    }, 201)).data.id;
    manualItemId = (await writeStock(warehouseRequest, 'post', '/stock/items', {
      sku: manualSku, name: manualName, category: 'part', unit: 'un',
    }, 201)).data.id;
    const locationId = (await writeStock(warehouseRequest, 'post', '/stock/locations', {
      name: locationName, kind: 'provider',
    }, 201)).data.id;
    await writeStock(warehouseRequest, 'put', `/stock/reorder-policies/${itemId}/${locationId}`, {
      critical_quantity: 2, safety_quantity: 4, minimum_quantity: 6, target_quantity: 12,
    }, 201);
    baselineShipments = await paginatedTotal(warehouseRequest, '/stock/shipments');
    baselineMovements = await paginatedTotal(warehouseRequest, '/stock/movements');
  });

  await page.goto(`${baseUrl}/estoque`);
  await page.getByRole('button', { name: 'Planejamento', exact: true }).click();
  await expect(page.getByText('A aprovação não reserva estoque e não cria compra ou remessa automaticamente.')).toBeVisible();

  let planId = 0;
  await test.step('Selecionar sugestão para saldo zero, atribuir responsável e criar proposta', async () => {
    const need = page.getByRole('row').filter({ hasText: sku });
    await expect(need).toBeVisible();
    await expect(need.getByText('0 un')).toBeVisible();
    await expect(need.getByText('12 un')).toBeVisible();
    await page.getByRole('checkbox', { name: `Selecionar ${sku} em ${locationName}` }).check();
    await page.getByLabel('Data limite *').fill(dueOn);
    await page.getByLabel('Buscar responsável da proposta').fill('Contador E2E');
    await page.getByLabel('Responsável *').selectOption({ label: 'Contador E2E' });
    await page.getByLabel('Observações').fill('Reposição calculada para revisão humana');
    await page.getByRole('button', { name: 'Criar proposta com sugestões' }).click();

    const dialog = page.getByRole('dialog');
    const heading = dialog.getByRole('heading', { name: /^Proposta #\d+$/ });
    await expect(heading).toBeVisible();
    planId = Number((await heading.textContent())?.match(/\d+/)?.[0]);
    expect(planId).toBeGreaterThan(0);
    await expect(dialog.getByText('Política · 12 sugerido')).toBeVisible();
    await expect(dialog.getByLabel(`Quantidade do item ${sku}`)).toHaveValue('12');
    await expect(dialog.getByRole('button', { name: 'Aprovar proposta' })).toHaveCount(0);
  });

  await test.step('Ajustar quantidade, segurança, responsável e prazo e incluir item manual', async () => {
    const dialog = page.getByRole('dialog');
    await dialog.getByLabel(`Quantidade do item ${sku}`).fill('10');
    await dialog.getByRole('row').filter({ hasText: sku })
      .getByRole('checkbox', { name: 'Item de segurança' }).check();
    await dialog.getByLabel(`Nota do item ${sku}`).fill('Manter reserva de segurança no planejamento');
    await dialog.getByLabel('Data limite *').fill(revisedDueOn);
    await dialog.getByLabel('Buscar responsável').fill('Administrador Nexa');
    await dialog.getByLabel('Responsável *').selectOption({ label: 'Administrador Nexa' });

    await dialog.getByLabel('Buscar material para proposta').fill(manualSku);
    await dialog.getByLabel('Material', { exact: true }).selectOption({ label: `${manualSku} — ${manualName}` });
    await dialog.getByLabel('Buscar local para proposta').fill(locationName);
    await dialog.getByLabel('Local', { exact: true }).selectOption({ label: locationName });
    await dialog.getByRole('button', { name: 'Incluir item' }).click();
    await dialog.getByLabel(`Quantidade do item ${manualSku}`).fill('3');
    await dialog.getByLabel(`Nota do item ${manualSku}`).fill('Inclusão manual para manutenção preventiva');
    await dialog.getByRole('button', { name: 'Salvar ajustes' }).click();

    await expect(page.getByRole('status').filter({ hasText: 'Proposta atualizada.' })).toBeVisible();
    await expect(dialog.getByText('versão 2')).toBeVisible();
    await expect(dialog.getByLabel(`Quantidade do item ${sku}`)).toHaveValue('10');
    await expect(dialog.getByLabel(`Quantidade do item ${manualSku}`)).toHaveValue('3');
    await dialog.getByRole('button', { name: 'Fechar proposta' }).click();

    const row = page.getByRole('region', { name: 'Propostas de reposição' })
      .getByRole('row').filter({ has: page.getByText(`#${planId}`, { exact: true }) });
    await expect(row.getByText('Em revisão')).toBeVisible();
    await expect(row.getByText('Administrador Nexa')).toBeVisible();
    await expect(row.locator('td').nth(2)).toHaveText('2');
  });

  const ownerContext = await browser.newContext({ baseURL: baseUrl, viewport: { width: 1280, height: 900 } });
  try {
    const owner = await ownerContext.newPage();
    await test.step('Outro usuário autorizado aprova e vê a proposta persistida', async () => {
      await login(owner, ownerEmail);
      await owner.goto(`${baseUrl}/estoque`);
      await owner.getByRole('button', { name: 'Planejamento', exact: true }).click();
      const row = owner.getByRole('region', { name: 'Propostas de reposição' })
        .getByRole('row').filter({ has: owner.getByText(`#${planId}`, { exact: true }) });
      await row.getByRole('button', { name: 'Detalhes / revisão' }).click();
      const dialog = owner.getByRole('dialog');
      await expect(dialog.getByLabel(`Quantidade do item ${sku}`)).toHaveValue('10');
      await expect(dialog.getByLabel(`Quantidade do item ${manualSku}`)).toHaveValue('3');
      await dialog.getByLabel('Nota da aprovação').fill('Planejamento conferido por segunda pessoa');
      await dialog.getByRole('button', { name: 'Aprovar proposta' }).click();
      await expect(owner.getByRole('status').filter({ hasText: 'Proposta aprovada e auditada.' })).toBeVisible();
      await expect(dialog.getByText('Aprovada por:')).toBeVisible();
      await expect(dialog.getByText('Planejamento conferido por segunda pessoa')).toBeVisible();
      await dialog.getByRole('button', { name: 'Fechar proposta' }).click();

      await owner.getByLabel('Estado').selectOption('approved');
      const approvedRow = owner.getByRole('region', { name: 'Propostas de reposição' })
        .getByRole('row').filter({ has: owner.getByText(`#${planId}`, { exact: true }) });
      await expect(approvedRow.getByText('Aprovada')).toBeVisible();
    });

    await test.step('Conferir dados aprovados e ausência de expedição ou movimento automático', async () => {
      const request = owner.context().request;
      const response = await request.get(`${apiRoot}/stock/replenishment-plans/${planId}`);
      expect(response.status(), await response.text()).toBe(200);
      const { data: plan } = await response.json() as PlanResponse;
      expect(plan.status).toBe('approved');
      expect(plan.version).toBe(3);
      expect(plan.due_on.slice(0, 10)).toBe(revisedDueOn);
      expect(plan.responsible.name).toBe('Administrador Nexa');
      expect(plan.approved_by?.id).not.toBe(plan.created_by.id);
      expect(plan.items).toHaveLength(2);
      expect(plan.items.find(line => line.item_id === itemId)).toMatchObject({
        origin: 'suggested', suggested_quantity: 12, quantity: 10, safety_item: true,
      });
      expect(plan.items.find(line => line.item_id === manualItemId)).toMatchObject({
        origin: 'manual', quantity: 3, note: 'Inclusão manual para manutenção preventiva',
      });
      expect(await paginatedTotal(request, '/stock/shipments')).toBe(baselineShipments);
      expect(await paginatedTotal(request, '/stock/movements')).toBe(baselineMovements);
    });
    await test.step('Reservar manualmente parte aprovada, expedir e receber com rastreio', async () => {
      const request = owner.context().request;
      const sourceName = 'Origem abastecida ' + suffix;
      const sourceId = (await writeStock(request, 'post', '/stock/locations', {
        name: sourceName, kind: 'provider',
      }, 201)).data.id;
      await writeStock(request, 'post', '/stock/movements', {
        request_id: randomUUID(), kind: 'receipt', item_id: itemId,
        source_location_id: null, destination_location_id: sourceId,
        quantity: 8, reason: 'Estoque de origem preparado para a expedição',
      }, 201);

      const response = await request.get(apiRoot + '/stock/replenishment-plans/' + planId);
      expect(response.status(), await response.text()).toBe(200);
      const body: { data: { items: Array<{ id: number; item_id: number;
        remaining_quantity: number; allocated_quantity: number }> } } = await response.json();
      const line = body.data.items.find(entry => entry.item_id === itemId);
      expect(line).toBeDefined();
      expect(line?.remaining_quantity).toBe(10);

      await owner.getByRole('region', { name: 'Propostas de reposição' })
        .getByRole('row').filter({ has: owner.getByText('#' + planId, { exact: true }) })
        .getByRole('button', { name: 'Detalhes' }).click();
      const dialog = owner.getByRole('dialog');
      const fulfillment = dialog.getByRole('region', { name: 'Executar reposição aprovada' });
      await fulfillment.getByLabel('Item aprovado *').selectOption(String(line!.id));
      await fulfillment.getByLabel('Buscar origem da remessa').fill(sourceName);
      await fulfillment.getByLabel('Origem da remessa *', { exact: true })
        .selectOption({ label: sourceName });
      await fulfillment.getByLabel('Quantidade para reservar *').fill('5');
      await fulfillment.getByLabel('Justificativa da reserva *')
        .fill('Separação autorizada para enviar ao destino planejado');
      await fulfillment.getByRole('button', { name: 'Confirmar reserva vinculada' }).click();
      await expect(fulfillment.getByRole('status').filter({
        hasText: 'A expedição ainda exige confirmação.',
      })).toBeVisible();
      await expect(fulfillment.getByText('5 já alocados de 10.')).toBeVisible();
      const linked = fulfillment.getByRole('row').filter({ hasText: 'reserved' });
      await expect(linked).toHaveCount(1);
      const shipmentId = Number((await linked.locator('td').first().innerText()).replace('#', ''));
      expect(shipmentId).toBeGreaterThan(0);

      expect(await paginatedTotal(request, '/stock/shipments')).toBe(baselineShipments + 1);
      expect(await paginatedTotal(request, '/stock/movements')).toBe(baselineMovements + 1);
      const dispatch = await writeStock(request, 'post',
        '/stock/shipments/' + shipmentId + '/dispatch', {
          request_id: randomUUID(), quantity: 5, reason: 'Expedição física conferida',
        }, 200);
      expect(dispatch.data.id).toBe(shipmentId);
      const receive = await writeStock(request, 'post',
        '/stock/shipments/' + shipmentId + '/receive', {
          request_id: randomUUID(), quantity: 5, reason: 'Recebimento físico conferido',
        }, 200);
      expect(receive.data.id).toBe(shipmentId);
      expect(await paginatedTotal(request, '/stock/movements')).toBe(baselineMovements + 3);
      const refreshed = await request.get(apiRoot + '/stock/replenishment-plans/' + planId);
      const latest: { data: { items: Array<{ id: number; allocated_quantity: number }> } } =
        await refreshed.json();
      expect(latest.data.items.find(entry => entry.id === line?.id)?.allocated_quantity).toBe(5);
      await dialog.getByRole('button', { name: 'Fechar proposta' }).click();
      // A second HTTP client performed dispatch/receive; force a fresh page load
      // rather than asserting against the React Query snapshot from before those events.
      await owner.reload();
      await owner.getByRole('button', { name: 'Planejamento', exact: true }).click();
      await owner.getByLabel('Estado').selectOption('approved');
      await owner.getByRole('region', { name: 'Propostas de reposição' })
        .getByRole('row').filter({ has: owner.getByText('#' + planId, { exact: true }) })
        .getByRole('button', { name: 'Detalhes' }).click();
      const summary = owner.getByRole('dialog').locator('.shipment-metrics');
      await expect(summary.getByText('Reservado')).toBeVisible();
      await expect(summary.getByText('Em trânsito')).toBeVisible();
      await expect(summary.locator('div').nth(2)).toHaveText(/5\s*Recebido/);
    });

  } finally {
    await ownerContext.close();
  }
});
