import { randomUUID } from 'node:crypto';
import { expect, test } from '@playwright/test';

const baseUrl = 'http://127.0.0.1:5173';
const tenant = process.env.NEXA_BOOTSTRAP_TENANT ?? 'nexa-demo';
const ownerEmail = process.env.NEXA_BOOTSTRAP_EMAIL ?? '';
const password = process.env.NEXA_BOOTSTRAP_PASSWORD ?? '';

test.skip(({ isMobile }) => isMobile, 'O cenário usa Chromium desktop no job de reposição.');

test('fornecedor criado pode ser pesquisado, editado e desativado sem apagar o cadastro', async ({ page }) => {
  expect(ownerEmail).not.toBe('');
  expect(password.length).toBeGreaterThanOrEqual(12);

  await page.goto(baseUrl);
  await page.getByLabel('Identificador da empresa').fill(tenant);
  await page.getByLabel('E-mail').fill(ownerEmail);
  await page.getByLabel('Senha').fill(password);
  await page.getByRole('button', { name: /Entrar no painel/ }).click();
  await expect(page.getByRole('heading', { name: 'Visão geral' })).toBeVisible();

  await page.goto(baseUrl + '/estoque');
  await page.getByRole('button', { name: 'Fornecedores', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Fornecedores de suprimentos' })).toBeVisible();

  const code = 'E2E-SUP-' + randomUUID().slice(0, 8).toUpperCase();
  const originalName = 'Distribuidora E2E ' + code;
  const revisedName = 'Distribuidora Revisada ' + code;

  await page.getByLabel('Código do fornecedor *').fill(code);
  await page.getByLabel('Nome do fornecedor *', { exact: true }).fill(originalName);
  await page.getByLabel('E-mail do fornecedor').fill('supply@example.test');
  await page.getByRole('button', { name: 'Cadastrar fornecedor' }).click();
  await expect(page.getByRole('status').filter({ hasText: 'Fornecedor cadastrado e auditado.' })).toBeVisible();

  await page.getByLabel('Pesquisar fornecedores').fill(code);
  const row = page.getByRole('row').filter({ hasText: code });
  await expect(row).toBeVisible();
  await expect(row.getByText(originalName)).toBeVisible();
  await expect(row.getByText('Ativo', { exact: true })).toBeVisible();

  await row.getByRole('button', { name: 'Editar ' + code }).click();
  await page.getByLabel('Nome do fornecedor (edição) *').fill(revisedName);
  await page.getByRole('checkbox', { name: 'Fornecedor ativo' }).uncheck();
  await page.getByRole('button', { name: 'Salvar fornecedor' }).click();
  await expect(page.getByRole('status').filter({ hasText: 'Fornecedor atualizado e auditado.' })).toBeVisible();

  await page.getByLabel('Filtrar situação').selectOption('inactive');
  await expect(row).toBeVisible();
  await expect(row.getByText(revisedName)).toBeVisible();
  await expect(row.getByText('Inativo', { exact: true })).toBeVisible();

  await page.getByLabel('Filtrar situação').selectOption('active');
  await expect(row).toHaveCount(0);
  await page.getByLabel('Filtrar situação').selectOption('all');
  await expect(row).toBeVisible();
});
