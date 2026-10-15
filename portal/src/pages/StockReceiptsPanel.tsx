import { useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, queryString, readableError } from '../api';
import { PagedEntitySelect } from '../components/PagedEntitySelect';
import { StockReceiptBatchPanel } from './StockReceiptBatchPanel';
import { centsFromBrazilianValue, currencyFromCents } from '../utils/currency';
import type { Page } from '../types';

interface SupplierOption { id: number; code: string; name: string; active: boolean }
interface ItemOption { id: number; sku: string; name: string; active: boolean }
interface LocationOption { id: number; name: string; active: boolean }

interface Receipt {
  id: number;
  supplier_id: number;
  invoice_reference: string;
  lot_number: string;
  expires_on: string | null;
  unit_cost_cents: number;
  supplier: { id: number; code: string; name: string };
  movement: {
    id: number;
    item_id: number;
    destination_location_id: number;
    quantity: number;
    item: { id: number; sku: string; name: string } | null;
    destination: { id: number; name: string } | null;
  };
}

export function StockReceiptsPanel({ token }: { token: string | null }) {
  const queryClient = useQueryClient();
  const [page, setPage] = useState(1);
  const [supplierId, setSupplierId] = useState('');
  const [itemId, setItemId] = useState('');
  const [locationId, setLocationId] = useState('');
  const [quantity, setQuantity] = useState('');
  const [invoice, setInvoice] = useState('');
  const [lot, setLot] = useState('');
  const [expiry, setExpiry] = useState('');
  const [unitCost, setUnitCost] = useState('');
  const [reason, setReason] = useState('');
  const [requestId, setRequestId] = useState(() => crypto.randomUUID());
  const [notice, setNotice] = useState('');

  const receipts = useQuery({
    queryKey: ['stock-receipts', page],
    queryFn: () => api<Page<Receipt>>('/stock/receipts' + queryString({
      page, per_page: 25,
    }), token),
  });
  const costCents = centsFromBrazilianValue(unitCost);
  const count = Number(quantity);
  const validQuantity = quantity !== '' && Number.isInteger(count) && count >= 1 && count <= 1000000;
  const validForm = Boolean(supplierId && itemId && locationId && invoice.trim() && lot.trim())
    && validQuantity && costCents !== null && reason.trim().length >= 5 && reason.trim().length <= 500;
  const create = useMutation({
    mutationFn: () => api<{ data: Receipt }>('/stock/receipts', token, {
      method: 'POST',
      body: JSON.stringify({
        request_id: requestId, supplier_id: Number(supplierId), item_id: Number(itemId),
        destination_location_id: Number(locationId), quantity: count,
        invoice_reference: invoice.trim(), lot_number: lot.trim(),
        expires_on: expiry || null, unit_cost_cents: costCents, reason: reason.trim(),
      }),
    }),
    onSuccess: async result => {
      setNotice('Entrada documentada #' + result.data.id + ' registrada com auditoria.');
      setSupplierId(''); setItemId(''); setLocationId('');
      setQuantity(''); setInvoice(''); setLot(''); setExpiry(''); setUnitCost(''); setReason('');
      setRequestId(crypto.randomUUID());
      setPage(1);
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['stock-receipts'] }),
        queryClient.invalidateQueries({ queryKey: ['stock-list'] }),
        queryClient.invalidateQueries({ queryKey: ['stock-reorder-needs'] }),
        queryClient.invalidateQueries({ queryKey: ['plan-reorder-needs'] }),
      ]);
    },
  });

  function changed(setter: (value: string) => void, value: string): void {
    setter(value);
    // Reuse the same UUID on a transport failure; only a changed payload creates a new operation.
    setRequestId(crypto.randomUUID());
    create.reset();
  }

  function submit(event: FormEvent<HTMLFormElement>): void {
    event.preventDefault();
    if (!create.isPending && validForm) create.mutate();
  }

  const currentPage = receipts.data?.meta?.current_page ?? receipts.data?.current_page ?? page;
  const lastPage = receipts.data?.meta?.last_page ?? receipts.data?.last_page ?? 1;
  const total = receipts.data?.meta?.total ?? receipts.data?.total ?? 0;

  return <section aria-label="Entradas documentadas">
    {notice && <p role="status" className="notice success">{notice}</p>}
    <div className="data-card stock-listing">
      <h2>Recebimentos com documento</h2>
      <p className="stock-hint">
        Cada entrada registra uma única linha com fornecedor, nota, lote, custo e quantidade.
        A operação altera o saldo real e não deve ser repetida com outro identificador após falha de conexão.
      </p>
      {receipts.isPending ? <p role="status">Carregando entradas...</p> : receipts.error ?
        <p role="alert" className="notice error">{readableError(receipts.error)}</p> :
        receipts.data.data.length === 0 ? <p>Nenhum recebimento documentado.</p> :
        <div className="table-wrap">
          <table>
            <thead><tr>
              <th>Nota / documento</th><th>Fornecedor</th><th>Item</th><th>Destino</th>
              <th>Lote / validade</th><th>Quantidade</th><th>Custo unitário</th>
            </tr></thead>
            <tbody>{receipts.data.data.map(receipt => <tr key={receipt.id}>
              <td><strong>{receipt.invoice_reference}</strong><small>Entrada #{receipt.id}</small></td>
              <td>{receipt.supplier.name}<small>{receipt.supplier.code}</small></td>
              <td>{receipt.movement.item?.sku ?? '#' + receipt.movement.item_id}
                <small>{receipt.movement.item?.name ?? ''}</small></td>
              <td>{receipt.movement.destination?.name ??
                '#' + receipt.movement.destination_location_id}</td>
              <td>{receipt.lot_number}<small>{receipt.expires_on ?? 'Sem validade informada'}</small></td>
              <td>{receipt.movement.quantity.toLocaleString('pt-BR')}</td>
              <td>{currencyFromCents(receipt.unit_cost_cents)}</td>
            </tr>)}</tbody>
          </table>
        </div>}
      {!receipts.isPending && !receipts.error && <div className="pagination">
        <span>Página {currentPage} de {lastPage} · {total} entradas</span>
        <div className="inline">
          <button type="button" className="button ghost" disabled={currentPage <= 1}
            onClick={() => setPage(currentPage - 1)}>Anterior</button>
          <button type="button" className="button ghost" disabled={currentPage >= lastPage}
            onClick={() => setPage(currentPage + 1)}>Próxima</button>
        </div>
      </div>}
    </div>
    <div className="stock-forms">
      <form className="stock-form" onSubmit={submit}>
        <h3>Registrar entrada documentada</h3>
        <p className="stock-hint">Confirme a nota e o recebimento físico antes de registrar a entrada.
          O identificador da operação é mantido em tentativas repetidas sem alterações.</p>
        <div className="stock-form-grid">
          <PagedEntitySelect<SupplierOption> id="receipt-supplier" label="Fornecedor *"
            searchLabel="Buscar fornecedor para entrada" placeholder="Nome ou código"
            emptyLabel="Selecionar fornecedor" value={supplierId} required
            onChange={value => changed(setSupplierId, value)}
            queryKey={['receipt-suppliers']} endpoint="/stock/suppliers"
            filters={{ active: 1 }} optionLabel={entry => entry.code + ' — ' + entry.name}
            disabled={create.isPending} />
          <PagedEntitySelect<ItemOption> id="receipt-item" label="Material *"
            searchLabel="Buscar material para entrada" placeholder="SKU ou descrição"
            emptyLabel="Selecionar material" value={itemId} required
            onChange={value => changed(setItemId, value)}
            queryKey={['receipt-items']} endpoint="/stock/items"
            filters={{ active_only: true }} optionLabel={entry => entry.sku + ' — ' + entry.name}
            disabled={create.isPending} />
          <PagedEntitySelect<LocationOption> id="receipt-location" label="Local de entrada *"
            searchLabel="Buscar local para entrada" placeholder="Nome do depósito ou unidade"
            emptyLabel="Selecionar destino" value={locationId} required
            onChange={value => changed(setLocationId, value)}
            queryKey={['receipt-locations']} endpoint="/stock/locations"
            filters={{ active_only: true }} optionLabel={entry => entry.name}
            disabled={create.isPending} />
          <div className="stock-field">
            <label htmlFor="receipt-invoice">Nota / documento *</label>
            <input id="receipt-invoice" required maxLength={100} value={invoice}
              disabled={create.isPending} onChange={event => changed(setInvoice, event.target.value)} />
          </div>
          <div className="stock-field">
            <label htmlFor="receipt-lot">Número do lote *</label>
            <input id="receipt-lot" required maxLength={100} value={lot}
              disabled={create.isPending} onChange={event => changed(setLot, event.target.value)} />
          </div>
          <div className="stock-field">
            <label htmlFor="receipt-quantity">Quantidade recebida *</label>
            <input id="receipt-quantity" type="number" required min={1} max={1000000} step={1}
              value={quantity} disabled={create.isPending}
              onChange={event => changed(setQuantity, event.target.value)} />
          </div>
          <div className="stock-field">
            <label htmlFor="receipt-unit-cost">Custo unitário (R$) *</label>
            <input id="receipt-unit-cost" required inputMode="decimal" placeholder="68,75"
              value={unitCost} disabled={create.isPending}
              onChange={event => changed(setUnitCost, event.target.value)} />
          </div>
          <div className="stock-field">
            <label htmlFor="receipt-expiry">Validade do lote (opcional, UTC)</label>
            <input id="receipt-expiry" type="date" value={expiry} disabled={create.isPending}
              min={new Date().toISOString().slice(0, 10)}
              onChange={event => changed(setExpiry, event.target.value)} />
          </div>
          <div className="stock-field">
            <label htmlFor="receipt-reason">Justificativa *</label>
            <input id="receipt-reason" required minLength={5} maxLength={500} value={reason}
              disabled={create.isPending} onChange={event => changed(setReason, event.target.value)} />
          </div>
        </div>
        {unitCost.trim() && costCents === null &&
          <p role="alert" className="notice error">Informe um custo válido com até duas casas decimais.</p>}
        {create.error && <p role="alert" className="notice error">{readableError(create.error)}</p>}
        <button type="submit" className="button primary" disabled={!validForm || create.isPending}>
          {create.isPending ? 'Registrando entrada...' : 'Confirmar entrada documentada'}
        </button>
      </form>
    </div>
    <StockReceiptBatchPanel token={token} />
  </section>;
}
