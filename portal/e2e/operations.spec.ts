import { test, expect, type Page } from '@playwright/test';

const login = async (page:Page):Promise<void> => {
  await page.goto('/');
  await page.getByLabel('Identificador da empresa').fill('nexa-demo');
  await page.getByLabel('E-mail').fill('smoke-owner@example.test');
  await page.getByLabel('Senha').fill('test-only-long-password-2026');
  await page.getByRole('button',{name:/Entrar no painel/}).click();
  await expect(page.getByRole('heading',{name:'Visão geral'})).toBeVisible();
};

test('login failure does not expose the backoffice', async ({page}) => {
  await page.goto('/');
  await page.getByLabel('Identificador da empresa').fill('nexa-demo');
  await page.getByLabel('E-mail').fill('smoke-owner@example.test');
  await page.getByLabel('Senha').fill('invalid-password');
  await page.getByRole('button',{name:/Entrar no painel/}).click();
  await expect(page.getByRole('alert')).toContainText('Credenciais inválidas');
  await expect(page.getByRole('heading',{name:'Visão geral'})).toHaveCount(0);
});

test('real browser creates customer, opens ticket, comments and logs out', async ({page}) => {
  await login(page);
  await page.goto('/clientes');
  await expect(page.getByRole('heading',{name:'Clientes'})).toBeVisible();
  await page.getByRole('button',{name:'Novo cliente'}).click();
  const customerName='E2E Customer '+Date.now()+'-'+Math.floor(Math.random()*100_000);
  await page.getByLabel('Nome / razão social').fill(customerName);
  await page.getByRole('button',{name:'Salvar cliente'}).click();
  await expect(page.getByText(customerName,{exact:true})).toBeVisible();

  await page.goto('/chamados');
  await expect(page.getByRole('heading',{name:'Chamados técnicos'})).toBeVisible();
  const subject='E2E impressão '+Date.now()+'-'+Math.floor(Math.random()*100_000);
  await page.getByLabel('Cliente',{exact:true}).selectOption({label:customerName});
  await page.getByLabel('Assunto').fill(subject);
  await page.getByLabel('Descrição').fill('A impressora não libera as páginas de impressão.');
  await page.getByRole('button',{name:'Abrir chamado'}).click();
  await expect(page.getByText(subject,{exact:true})).toBeVisible();
  await page.getByRole('row',{name:new RegExp(subject)}).getByRole('button',{name:'Detalhes'}).click();
  const dialog=page.getByRole('dialog');
  await expect(dialog.getByText(subject,{exact:true})).toBeVisible();

  await dialog.getByLabel('Nova mensagem').fill('Mensagem interna validada pelo navegador E2E');
  await expect(dialog.getByLabel('Comentário interno (não visível ao cliente)')).toBeChecked();
  await dialog.getByRole('button',{name:'Enviar comentário'}).click();
  await expect(dialog.getByText('Mensagem interna validada pelo navegador E2E')).toBeVisible();

  await dialog.getByLabel('Novo estado').selectOption('unassigned');
  await dialog.getByLabel('Motivo').fill('Chamado encaminhado para fila técnica');
  await dialog.getByRole('button',{name:'Atualizar estado'}).click();
  await expect(dialog.getByText('Sem responsável')).toBeVisible();
  await dialog.getByRole('button',{name:'Fechar detalhes'}).click();

  await page.getByRole('button',{name:'Encerrar sessão'}).click();
  await expect(page.getByRole('button',{name:/Entrar no painel/})).toBeVisible();
  await page.reload();
  await expect(page.getByRole('heading',{name:'Acesse sua operação.'})).toBeVisible();
});

test('authenticated UI never displays fabricated telemetry and loads persistent stock', async ({page}) => {
  await login(page);
  await page.goto('/dashboard');
  await expect(page.getByText('Nenhum indicador sem coleta comprovada')).toBeVisible();
  await page.goto('/estoque');
  await expect(page.getByRole('heading',{name:'Estoque e movimentações'})).toBeVisible();
  await page.getByRole('button',{name:'Lotes'}).click();
  await expect(page.getByText('Nenhum registro nesta categoria.')).toBeVisible();
  await page.goto('/chamados');
  await expect(page.getByRole('heading',{name:'Chamados técnicos'})).toBeVisible();
});
