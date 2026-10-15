import { useState, type FormEvent } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { api, readableError } from '../api';
import { PagedEntitySelect } from '../components/PagedEntitySelect';
import { centsFromBrazilianValue } from '../utils/currency';

interface SupplierOption { id: number; code: string; name: string; active: boolean }
interface StockItemOption { id: number; sku: string; name: string; active: boolean }
interface StockLocationOption { id: number; name: string; active: boolean }
interface BatchResponse { data: { id: number; line_count: number; invoice_reference: string } }

interface EntryLine {
  key: string;
  itemId: string;
  destinationId: string;
  quantity: string;
  lotNumber: string;
  expiresOn: string;
  unitCost: string;
}

function emptyLine(): EntryLine {
  return {
    key: crypto.randomUUID(), itemId: '', destinationId: '',
    quantity: '1', lotNumber: '', expiresOn: '', unitCost: '',
  };
}

export function StockReceiptBatchPanel({ token }: { token: string | null }) {
  const queryClient = useQueryClient();
  const [supplierId, setSupplierId] = useState('');
  const [invoice, setInvoice] = useState('');
  const [reason, setReason] = useState('');
  const [lines, setLines] = useState<EntryLine[]>(() => [emptyLine()]);
  const [requestId, setRequestId] = useState(() => crypto.randomUUID());
  const [notice, setNotice] = useState('');

  function changed(): void {
    setRequestId(crypto.randomUUID());
    setNotice('');
    create.reset();
  }

  function editLine(key: string, patch: Partial<EntryLine>): void {
    setLines(current => current.map(line => line.key === key ? { ...line, ...patch } : line));
    changed();
  }

  const valid = Boolean(supplierId) && invoice.trim().length >= 1 &&
    invoice.trim().length <= 100 && reason.trim().length >= 5 && reason.trim().length <= 500 &&
    lines.length >= 1 && lines.length <= 30 && lines.every(line => {
      const quantity = Number(line.quantity);
      return Boolean(line.itemId && line.destinationId && line.lotNumber.trim()) &&
        line.lotNumber.trim().length <= 100 &&
        line.quantity.trim() !== '' && Number.isInteger(quantity) &&
        quantity >= 1 && quantity <= 1000000 &&
        centsFromBrazilianValue(line.unitCost) !== null;
    });

  const create = useMutation({
    mutationFn: () => api<BatchResponse>('/stock/receipt-batches', token, {
      method: 'POST',
      body: JSON.stringify({
        request_id: requestId,
        supplier_id: Number(supplierId),
        invoice_reference: invoice.trim(),
        reason: reason.trim(),
        items: lines.map(line => ({
          item_id: Number(line.itemId),
          destination_location_id: Number(line.destinationId),
          quantity: Number(line.quantity),
          lot_number: line.lotNumber.trim(),
          expires_on: line.expiresOn || null,
          unit_cost_cents: centsFromBrazilianValue(line.unitCost),
        })),
      }),
    }),
    onSuccess: async response => {
      setNotice('Entrada em lote #' + response.data.id + ' confirmada: ' +
        response.data.line_count + ' linhas registradas.');
      setSupplierId('');
      setInvoice('');
      setReason('');
      setLines([emptyLine()]);
      setRequestId(crypto.randomUUID());
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['stock-receipts'] }),
        queryClient.invalidateQueries({ queryKey: ['stock-receipt-batches'] }),
        queryClient.invalidateQueries({ queryKey: ['stock-list'] }),
        queryClient.invalidateQueries({ queryKey: ['stock-reorder-needs'] }),
        queryClient.invalidateQueries({ queryKey: ['plan-reorder-needs'] }),
      ]);
    },
  });

  function submit(event: FormEvent<HTMLFormElement>): void {
    event.preventDefault();
    if (valid && !create.isPending) create.mutate();
  }

  return <section aria-label="Recebimento em lote" className="stock-forms">
    <form className="stock-form" onSubmit={submit}>
      <h3>Receber múltiplos materiais da mesma nota</h3>
      <p className="stock-hint">
        Conferência física obrigatória. Todas as linhas são confirmadas juntas;
        se uma linha falhar, nenhum saldo é registrado.
        Em caso de conexão incerta, reenvie sem alterar os campos para manter o identificador.
      </p>
      <div className="stock-form-grid">
        <PagedEntitySelect<SupplierOption> id="batch-supplier" label="Fornecedor da nota *"
          searchLabel="Buscar fornecedor da nota" placeholder="Código ou nome"
          emptyLabel="Selecionar fornecedor" value={supplierId}
          onChange={value => { setSupplierId(value); changed(); }}
          queryKey={['batch-suppliers']} endpoint="/stock/suppliers" filters={{ active: 1 }}
          optionLabel={entry => entry.code + ' — ' + entry.name}
          required disabled={create.isPending} />
        <div className="stock-field">
          <label htmlFor="batch-invoice">Referência da nota *</label>
          <input id="batch-invoice" required maxLength={100} value={invoice}
            disabled={create.isPending}
            onChange={event => { setInvoice(event.target.value); changed(); }} />
        </div>
        <div className="stock-field">
          <label htmlFor="batch-reason">Justificativa do recebimento *</label>
          <input id="batch-reason" required minLength={5} maxLength={500} value={reason}
            disabled={create.isPending}
            onChange={event => { setReason(event.target.value); changed(); }} />
        </div>
      </div>

      {lines.map((line, index) => <fieldset key={line.key} className="stock-form">
        <legend>Item da nota {index + 1}</legend>
        <div className="stock-form-grid">
          <PagedEntitySelect<StockItemOption>
            id={'batch-item-' + line.key} label={'Material do item ' + (index + 1) + ' *'}
            searchLabel={'Buscar material do item ' + (index + 1)}
            placeholder="SKU ou descrição" emptyLabel="Selecionar material"
            value={line.itemId} required
            onChange={value => editLine(line.key, { itemId: value })}
            queryKey={['batch-item-options']} endpoint="/stock/items"
            filters={{ active_only: true }}
            optionLabel={entry => entry.sku + ' — ' + entry.name}
            disabled={create.isPending} />
          <PagedEntitySelect<StockLocationOption>
            id={'batch-location-' + line.key} label={'Destino do item ' + (index + 1) + ' *'}
            searchLabel={'Buscar destino do item ' + (index + 1)}
            placeholder="Depósito ou unidade" emptyLabel="Selecionar destino"
            value={line.destinationId} required
            onChange={value => editLine(line.key, { destinationId: value })}
            queryKey={['batch-location-options']} endpoint="/stock/locations"
            filters={{ active_only: true }}
            optionLabel={entry => entry.name}
            disabled={create.isPending} />
          <div className="stock-field">
            <label htmlFor={'batch-quantity-' + line.key}>Quantidade do item {index + 1} *</label>
            <input id={'batch-quantity-' + line.key} type="number" min={1} max={1000000}
              step={1} required value={line.quantity} disabled={create.isPending}
              onChange={event => editLine(line.key, { quantity: event.target.value })} />
          </div>
          <div className="stock-field">
            <label htmlFor={'batch-lot-' + line.key}>Lote do item {index + 1} *</label>
            <input id={'batch-lot-' + line.key} required maxLength={100} value={line.lotNumber}
              disabled={create.isPending}
              onChange={event => editLine(line.key, { lotNumber: event.target.value })} />
          </div>
          <div className="stock-field">
            <label htmlFor={'batch-cost-' + line.key}>Custo unitário do item {index + 1} (R$) *</label>
            <input id={'batch-cost-' + line.key} required inputMode="decimal" placeholder="68,75"
              value={line.unitCost} disabled={create.isPending}
              onChange={event => editLine(line.key, { unitCost: event.target.value })} />
          </div>
          <div className="stock-field">
            <label htmlFor={'batch-expiry-' + line.key}>Validade do item {index + 1} (opcional)</label>
            <input id={'batch-expiry-' + line.key} type="date" value={line.expiresOn}
              disabled={create.isPending} min={new Date().toISOString().slice(0, 10)}
              onChange={event => editLine(line.key, { expiresOn: event.target.value })} />
          </div>
        </div>
        {line.unitCost.trim() && centsFromBrazilianValue(line.unitCost) === null &&
          <p role="alert" className="notice error">O custo do item {index + 1} é inválido.</p>}
        {lines.length > 1 && <button className="button ghost" type="button" disabled={create.isPending}
          onClick={() => { setLines(current => current.filter(item => item.key !== line.key)); changed(); }}>
          Remover item {index + 1}
        </button>}
      </fieldset>)}

      <div className="inline">
        <button type="button" className="button ghost"
          disabled={lines.length >= 30 || create.isPending}
          onClick={() => { setLines(current => [...current, emptyLine()]); changed(); }}>
          Adicionar item à nota
        </button>
        <span>{lines.length}/30 itens</span>
      </div>
      {create.error && <p role="alert" className="notice error">{readableError(create.error)}</p>}
      <button type="submit" className="button primary" disabled={!valid || create.isPending}>
        {create.isPending ? 'Confirmando lote...' : 'Confirmar todos os itens da nota'}
      </button>
    </form>
    {notice && <p role="status" className="notice success">{notice}</p>}
  </section>;
}
