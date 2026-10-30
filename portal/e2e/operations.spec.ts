import { test, expect } from '@playwright/test';
import { runAlertWorkflow } from './helpers/alert-workflow';

test('secure login, customers, tickets, stock and logout (real browser)', async ({page}) => {
  // Only one login per browser profile; production's 5/min/IP
  // brute-force protection is intentionally kept enabled.
  await test.step('Authenticate with real cookie session and CSRF', async () => {
    await page.goto('/');
    await page.getByLabel('Identificador da empresa').fill('nexa-demo');
    await page.getByLabel('E-mail').fill('smoke-owner@example.test');
    await page.getByLabel('Senha').fill('test-only-long-password-2026');
    await page.getByRole('button',{name:/Entrar no painel/}).click();
    await expect(page.getByRole('heading',{name:'Visão geral'})).toBeVisible();
  });

  await test.step('Create and persist a customer in the admin portal', async () => {
    await page.goto('/clientes');
    await expect(page.getByRole('heading',{name:'Clientes'})).toBeVisible();
    await page.getByRole('button',{name:'Novo cliente'}).click();
    const name='E2E Customer '+Date.now()+'-'+Math.floor(Math.random()*100_000);
    await page.getByLabel('Nome / razão social').fill(name);
    await page.getByRole('button',{name:'Salvar cliente'}).click();
    await expect(page.getByText(name,{exact:true})).toBeVisible();
    await page.goto('/contratos');
    await expect(page.getByRole('heading',{name:'Contratos'})).toBeVisible();
    await page.getByRole('button',{name:'Novo contrato'}).click();
    const contract = page.getByRole('dialog', {name:'Novo contrato'});
    await contract.getByLabel('Buscar cliente').fill(name);
    await contract.getByLabel('Cliente',{exact:true}).selectOption({label:name});
    const number = 'E2E-CON-'+Date.now()+'-'+Math.floor(Math.random()*100_000);
    await contract.getByLabel('Número do documento').fill(number);
    await contract.getByLabel('Identificação').fill('Contrato de homologação E2E');
    await contract.getByLabel('Unidade prestadora').fill('Unidade teste');
    await contract.getByLabel('Local de cobrança').fill('Rua Teste, 100');
    await contract.getByLabel('Início da vigência').fill('2026-01-01');
    await contract.getByLabel('Início do faturamento').fill('2026-01-31');
    await contract.getByLabel('Fim da vigência, se houver').fill('2026-12-31');
    await contract.getByLabel('Padrão deste cliente').check();
    await contract.getByRole('button',{name:'Salvar contrato'}).click();
    await expect(page.getByRole('status').filter({hasText:'Contrato salvo com histórico auditável.'})).toBeVisible();
    await page.getByLabel('Pesquisar contratos').fill(number);
    const contractRow = page.getByRole('row').filter({hasText:number});
    await expect(contractRow).toBeVisible();
    await contractRow.getByRole('button',{name:'Ver ciclo de '+number}).click();
    const cycle = page.getByRole('dialog',{name:'Ciclo de faturamento'});
    await cycle.getByLabel('Data de referência').fill('2026-02-28');
    await expect(cycle.getByText('2026-02-28 a 2026-03-30')).toBeVisible();
    await cycle.getByRole('button',{name:'Fechar'}).click();
    await page.goto('/chamados');
    await expect(page.getByRole('heading',{name:'Chamados técnicos'})).toBeVisible();
    await page.getByRole('button',{name:'Abrir OS'}).click();
    await page.getByLabel('Buscar cliente do chamado').fill(name);
    await page.getByLabel('Cliente',{exact:true}).selectOption({label:name});
  });

  await test.step('Open an actual ticket, add internal comment, and transition status', async () => {
    const subject='E2E impressão '+Date.now()+'-'+Math.floor(Math.random()*100_000);
    await page.getByLabel('Assunto').fill(subject);
    await page.getByLabel('Descrição').fill('A impressora não libera as páginas de impressão.');
    await page.getByRole('button',{name:'Abrir OS'}).click();
    await expect(page.getByText(subject,{exact:true})).toBeVisible();
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
  });

  await test.step('Read actual dashboard telemetry safeguards and persisted lot positions', async () => {
    await page.goto('/dashboard');
    await expect(page.getByText('Nenhum indicador sem coleta comprovada')).toBeVisible();
    await page.goto('/estoque');
    await expect(page.getByRole('heading',{name:'Estoque e movimentações'})).toBeVisible();
    await page.getByRole('button',{name:'Lotes'}).click();
    await expect(page.getByText('Nenhum registro nesta categoria.')).toBeVisible();
    await page.goto('/chamados');
    await expect(page.getByRole('heading',{name:'Chamados técnicos'})).toBeVisible();
  });

  await test.step('Read separate agent heartbeat and upload diagnostics from real API', async () => {
    await page.goto('/agentes');
    await expect(page.getByRole('heading',{name:'Agentes de coleta'})).toBeVisible();
    await expect(page.getByText('Heartbeat do serviço e última entrega de contadores são sinais independentes.')).toBeVisible();
    await expect(page.getByText('Nenhum agente registrado nesta carteira.')).toBeVisible();
  });

  await runAlertWorkflow(page);

  await test.step('Invalidate server session and reject stale browser reload', async () => {
    const logout = page.getByRole('button',{name:'Encerrar sessão'});
    await expect(logout).toBeInViewport();
    await logout.click();
    await expect(page.getByRole('button',{name:/Entrar no painel/})).toBeVisible();
    await page.reload();
    await expect(page.getByRole('heading',{name:'Acesse sua operação.'})).toBeVisible();
  });
});
