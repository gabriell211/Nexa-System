import { expect, test, type Page } from '@playwright/test';

export async function runAlertWorkflow(page:Page): Promise<void> {
  const unique=Date.now().toString()+'-'+Math.floor(Math.random()*100_000);
  const customer='Alerta E2E Cliente '+unique;
  const model='Model E2E '+unique;
  const unit='Unidade E2E '+unique;
  const subject='Chamado E2E alerta '+unique;
  const alertTitle='Alerta de hardware E2E '+unique;

  await test.step('Create customer and a printer with real database persistence',async()=>{
    await page.goto('/clientes');
    await page.getByRole('button',{name:'Novo cliente'}).click();
    await page.getByLabel('Nome / razão social').fill(customer);
    await page.getByRole('button',{name:'Salvar cliente'}).click();
    await expect(page.getByText(customer,{exact:true})).toBeVisible();

    await page.goto('/parque');
    await page.getByRole('button',{name:'Adicionar impressora ao parque'}).click();
    const dialog=page.getByRole('dialog');
    await dialog.getByLabel('Buscar cliente da impressora').fill(customer);
    await dialog.locator('#printer-customer').selectOption({label:customer});
    await dialog.locator('#printer-brand').fill('Kyocera');
    await dialog.locator('#printer-model').fill(model);
    await dialog.getByRole('button',{name:'Salvar impressora'}).click();
    await expect(page.getByRole('row').filter({hasText:model}).getByRole('button',{name:model})).toBeVisible();
    await expect(page.getByRole('row').filter({hasText:model})
      .getByText('Sem leitura',{exact:true})).toBeVisible();
    await page.getByRole('row').filter({hasText:model})
      .getByRole('button',{name:/Histórico de contadores da impressora/}).click();
    const history=page.getByRole('dialog',{name:/Contadores — Kyocera/});
    await expect(history.getByText('Nenhuma leitura encontrada para o período.')).toBeVisible();
    await history.getByLabel('Data inicial').fill('2026-10-14');
    await history.getByLabel('Data final').fill('2026-10-15');
    await expect(history.getByRole('button',{name:'Prévia de incrementos'})).toBeEnabled();
    await history.getByRole('button',{name:'Prévia de incrementos'}).click();
    await expect(history.getByRole('heading',{name:'Incrementos observados — não faturáveis'})).toBeVisible();
    await expect(history.getByText(/Dados insuficientes para comparar/)).toBeVisible();
    await expect(history.getByText(/Não utilizar para faturamento automático/)).toBeVisible();
    await history.getByRole('button',{name:'Fechar histórico de contadores'}).click();
  });

  await test.step('Find a customer unit remotely and persist the printer assignment',async()=>{
    await page.goto('/clientes');
    await page.getByLabel('Pesquisar clientes').fill(customer);
    await page.getByRole('link',{name:customer}).click();
    await page.getByRole('button',{name:'Nova unidade'}).click();
    const editor=page.getByRole('dialog');
    await editor.getByLabel('Nome').fill(unit);
    await editor.getByRole('button',{name:'Salvar'}).click();
    await expect(page.getByText(unit,{exact:true})).toBeVisible();

    await page.goto('/parque');
    await page.getByLabel('Pesquisar impressoras').fill(model);
    const printer=page.getByRole('row').filter({hasText:model});
    await printer.getByRole('button',{name:/Localização da impressora/}).click();
    const assignment=page.getByRole('dialog');
    await assignment.getByLabel('Buscar unidade da impressora').fill(unit);
    const unitOption=assignment.locator('#assigned-location option').filter({hasText:unit}).first();
    const unitId=await unitOption.getAttribute('value');
    expect(unitId).not.toBeNull();
    await assignment.locator('#assigned-location').selectOption(unitId!);
    await assignment.getByLabel('Motivo da movimentação').fill('Instalação validada pelo navegador E2E');
    await assignment.getByRole('button',{name:'Registrar localização'}).click();
    await expect(assignment.getByText('Nova localização registrada com sucesso.')).toBeVisible();
    await expect(assignment.getByText(unit,{exact:true}).first()).toBeVisible();
    await assignment.getByRole('button',{name:'Fechar'}).click();

    await printer.getByRole('button',{name:/Localização da impressora/}).click();
    const reopened=page.getByRole('dialog');
    await reopened.getByLabel('Buscar unidade da impressora').fill('busca sem resultado E2E');
    await expect(reopened.getByText('Nenhuma opção nesta página. Ajuste a busca ou avance.').first()).toBeVisible();
    await expect(reopened.locator('#assigned-location')).toHaveValue(unitId!);
    await reopened.getByRole('button',{name:'Fechar'}).click();
  });

  await test.step('Open an OS for the same printer from the administration',async()=>{
    await page.goto('/chamados');
    await page.getByRole('button',{name:'Abrir OS'}).click();
    await page.getByLabel('Buscar cliente do chamado').fill(customer);
    await page.getByLabel('Cliente',{exact:true}).selectOption({label:customer});
    await page.getByLabel('Buscar impressora do chamado').fill(model);
    const option=page.locator('#ticket-printer option').filter({hasText:model}).first();
    const printerId=await option.getAttribute('value');
    expect(printerId).not.toBeNull();
    await page.locator('#ticket-printer').selectOption(printerId!);
    await page.getByLabel('Assunto').fill(subject);
    await page.getByLabel('Descrição').fill('Falha real registrada pela interface para vincular um alerta.');
    await page.getByRole('button',{name:'Abrir OS'}).click();
    const dialog=page.getByRole('dialog');
    await expect(dialog.getByText(subject,{exact:true})).toBeVisible();
    await dialog.getByRole('button',{name:'Fechar detalhes'}).click();
  });

  await test.step('Register manual alert and correlate it with a ticket',async()=>{
    await page.goto('/alertas');
    await expect(page.getByRole('heading',{name:'Alertas operacionais'})).toBeVisible();
    await page.getByLabel('Buscar impressora do alerta').fill(model);
    const option=page.locator('#alert-printer option').filter({hasText:model}).first();
    const printerId=await option.getAttribute('value');
    expect(printerId).not.toBeNull();
    await page.locator('#alert-printer').selectOption(printerId!);
    await page.getByLabel('Código da ocorrência').fill('E2E_PAPER_JAM');
    await page.getByLabel('Título').fill(alertTitle);
    await page.getByLabel('Descrição (opcional)').fill('Observação humana, não telemetria automática.');
    await page.getByRole('button',{name:'Registrar alerta'}).click();
    await expect(page.getByText(alertTitle,{exact:true})).toBeVisible();

    await page.getByRole('row',{name:new RegExp(alertTitle)})
      .getByRole('button',{name:'Detalhes do alerta'}).click();
    const detail=page.getByRole('dialog');
    await expect(detail.getByText('Origem: registrado por operador',{exact:false})).toBeVisible();
    await detail.getByLabel('Buscar chamado para o alerta').fill(subject);
    const ticketOption=detail.locator('#alert-ticket option').filter({hasText:subject}).first();
    const ticketId=await ticketOption.getAttribute('value');
    expect(ticketId).not.toBeNull();
    await detail.locator('#alert-ticket').selectOption(ticketId!);
    await detail.getByRole('button',{name:'Vincular chamado'}).click();
    await expect(detail.getByText(/Chamado vinculado:/)).toBeVisible();

    await detail.getByLabel('Novo estado').selectOption('resolved');
    await detail.getByLabel('Justificativa').fill('Operador confirmou resolução física da falha');
    await detail.getByRole('button',{name:'Salvar estado'}).click();
    await expect(detail.getByText('Resolvido',{exact:true})).toBeVisible();
    await detail.getByRole('button',{name:'Fechar alerta'}).click();
  });


  await test.step('Persist linked/resolved incident across a real reload',async()=>{
    await page.reload();
    await page.getByLabel('Filtrar por estado').selectOption('resolved');
    await expect(page.getByText(alertTitle,{exact:true})).toBeVisible();
  });
}
