import { useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, queryString, readableError } from '../api';
import { PagedEntitySelect } from '../components/PagedEntitySelect';
import { PurchaseReceiptMatchPanel } from './PurchaseReceiptMatchPanel';
import { SupplierReturnsPanel } from './SupplierReturnsPanel';
import { centsFromBrazilianValue, currencyFromCents } from '../utils/currency';
import type { Page } from '../types';

type OrderStatus = 'draft' | 'approved' | 'cancelled';

interface SupplierOption { id: number; code: string; name: string; active: boolean }
interface ApprovedPlanOption { id: number; due_on: string; status: string }
interface PlanLine {
  id: number;
  quantity: number;
  remaining_quantity: number;
  item: { id: number; sku: string; name: string; unit: string };
  location: { id: number; name: string };
}
interface PlanDetails { id: number; status: string; items: PlanLine[] }
interface DraftLine { plan_line_id: number; quantity: string; cost: string }
interface PurchaseOrder {
  id: number; status: OrderStatus; version: number; plan_id: number; supplier_id: number;
  request_id: string; notes: string | null; total_cents: number;
  supplier: SupplierOption | null;
  created_by: { id: number; name: string } | null;
  approved_by: { id: number; name: string } | null;
  review_note: string | null; cancel_reason: string | null;
  items_count: number;
  fulfillment: {
    ordered_quantity: number; gross_received_quantity: number;
    returned_quantity: number; pending_return_quantity: number;
    retained_quantity: number; outstanding_quantity: number;
    replacement_needed_quantity: number;
    status: 'return_review_pending' | 'replacement_required' |
      'net_received' | 'awaiting_receipt' | 'partially_received';
  };
  items: Array<{
    id: number; plan_line_id: number; quantity: number;
    unit_cost_cents: number; line_total_cents: number;
    received_quantity: number; gross_received_quantity: number;
    returned_quantity: number; pending_return_quantity: number;
    retained_quantity: number; replacement_needed_quantity: number;
    outstanding_quantity: number; cost_divergence: boolean;
    receipts: Array<{ purchase_receipt_match_id: number; returned_quantity: number; pending_return_quantity: number;
      receipt_detail_id: number; invoice_reference: string; lot_number: string;
      quantity: number; unit_cost_cents: number; variance_reason: string | null;
      received_at: string | null }>;
    item: { id: number; sku: string; name: string; unit: string } | null;
    destination: { id: number; name: string } | null;
  }>;
}

const names: Record<OrderStatus, string> = {
  draft: 'Aguardando aprovação', approved: 'Aprovado', cancelled: 'Cancelado',
};

function PurchaseOrderDetail({ orderId, token, role, userId, onClose, onChanged }: {
  orderId: number; token: string | null; role: string; userId: number;
  onClose: () => void; onChanged: () => Promise<void>;
}) {
  const [review, setReview] = useState('');
  const [reason, setReason] = useState('');
  const details = useQuery({
    queryKey: ['purchase-order-detail', orderId],
    queryFn: () => api<{ data: PurchaseOrder }>('/stock/purchase-orders/' + orderId, token),
  });
  const order = details.data?.data;
  const isManager = ['owner','admin','manager'].includes(role);
  const canApprove = order?.status === 'draft' && isManager && order.created_by?.id !== userId;
  const canCancel = order && order.status !== 'cancelled' &&
    (isManager || (order.status === 'draft' && order.created_by?.id === userId));

  const approve = useMutation({
    mutationFn: () => api('/stock/purchase-orders/' + orderId + '/approve', token, {
      method: 'POST',
      body: JSON.stringify({ expected_version: order?.version, review_note: review.trim() || null }),
    }),
    onSuccess: async () => {
      await Promise.all([details.refetch(), onChanged()]);
    },
  });
  const cancel = useMutation({
    mutationFn: () => api('/stock/purchase-orders/' + orderId + '/cancel', token, {
      method: 'POST',
      body: JSON.stringify({ expected_version: order?.version, reason: reason.trim() }),
    }),
    onSuccess: async () => {
      await Promise.all([details.refetch(), onChanged()]);
    },
  });
  return <div className="modal-overlay">
    <section className="modal plan-modal" role="dialog" aria-modal="true"
      aria-labelledby="purchase-order-heading">
      <div className="modal-header">
        <div><span className="eyebrow">COMPRAS DE SUPRIMENTOS</span>
          <h2 id="purchase-order-heading">Pedido de compra #{orderId}</h2></div>
        <button type="button" className="icon-button" aria-label="Fechar pedido"
          onClick={onClose}>✕</button>
      </div>
      {details.isPending ? <p role="status">Carregando pedido...</p> :
        details.error ? <p role="alert" className="notice error">{readableError(details.error)}</p> :
        order && <>
          <p><strong>Situação:</strong> {names[order.status]} · Versão {order.version}</p>
          <p><strong>Plano:</strong> #{order.plan_id} · <strong>Fornecedor:</strong> {order.supplier?.name ?? '#' + order.supplier_id}</p>
          <p><strong>Solicitante:</strong> {order.created_by?.name ?? '—'} ·
            <strong> Aprovado por:</strong> {order.approved_by?.name ?? '—'}</p>
          <p><strong>Observações:</strong> {order.notes || '—'}</p>
          <div className="table-wrap"><table>
            <thead><tr><th>Material</th><th>Destino</th><th>Pedido</th>
              <th>Recebido bruto</th><th>Devolvido</th><th>Em análise</th>
              <th>Retido líquido</th><th>Reposição estimada</th><th>Pendente líquido</th>
              <th>Custo unitário</th><th>Subtotal</th></tr></thead>
            <tbody>{order.items.map(line => <tr key={line.id}>
              <td>{line.item?.sku ?? '#' + line.plan_line_id}
                <small>{line.item?.name ?? '—'}</small></td>
              <td>{line.destination?.name ?? '—'}</td>
              <td>{line.quantity.toLocaleString('pt-BR')}</td>
              <td>{line.gross_received_quantity.toLocaleString('pt-BR')}</td>
              <td>{line.returned_quantity.toLocaleString('pt-BR')}</td>
              <td>{line.pending_return_quantity.toLocaleString('pt-BR')}</td>
              <td>{line.retained_quantity.toLocaleString('pt-BR')}</td>
              <td>{line.replacement_needed_quantity.toLocaleString('pt-BR')}</td>
              <td>{line.outstanding_quantity.toLocaleString('pt-BR')}</td>
              <td>{currencyFromCents(line.unit_cost_cents)}</td>
              <td>{currencyFromCents(line.line_total_cents)}</td>
            </tr>)}</tbody></table></div>
          <p><strong>Valor planejado:</strong> {currencyFromCents(order.total_cents)}</p>
          <div className="shipment-metrics" aria-label="Indicadores de recebimento e devolução">
            <div><strong>{order.fulfillment.ordered_quantity.toLocaleString('pt-BR')}</strong>
              <small>Quantidade comprada</small></div>
            <div><strong>{order.fulfillment.gross_received_quantity.toLocaleString('pt-BR')}</strong>
              <small>Recebido bruto</small></div>
            <div><strong>{order.fulfillment.returned_quantity.toLocaleString('pt-BR')}</strong>
              <small>Devolvido</small></div>
            <div><strong>{order.fulfillment.pending_return_quantity.toLocaleString('pt-BR')}</strong>
              <small>Devolução pendente</small></div>
            <div><strong>{order.fulfillment.retained_quantity.toLocaleString('pt-BR')}</strong>
              <small>Retido líquido</small></div>
            <div><strong>{order.fulfillment.replacement_needed_quantity.toLocaleString('pt-BR')}</strong>
              <small>Reposição estimada</small></div>
            <div><strong>{order.fulfillment.outstanding_quantity.toLocaleString('pt-BR')}</strong>
              <small>Pendente líquido</small></div>
          </div>
          <p><strong>Conciliação líquida:</strong> {{
            return_review_pending: 'Devolução aguardando revisão',
            replacement_required: 'Reposição necessária após devolução',
            net_received: 'Quantidade líquida integral',
            awaiting_receipt: 'Aguardando recebimento',
            partially_received: 'Recebimento líquido parcial',
          }[order.fulfillment.status]}</p>
          <p className="stock-hint">
            Retido líquido é recebido bruto menos devoluções concluídas; não representa o
            saldo físico atual (que pode ter sido consumido ou transferido).
            Reposição estimada não é confirmação de envio do fornecedor nem pedido automático.
            Devoluções pendentes não liberam nova conciliação.
          </p>
          {order.items.some(line => line.receipts.length > 0) && <div className="table-wrap">
            <h3>Notas e lotes conciliados</h3>
            <table><thead><tr><th>Material</th><th>Nota</th><th>Lote</th><th>Quantidade</th>
              <th>Custo recebido</th><th>Devolvido</th><th>Diferença</th></tr></thead><tbody>
              {order.items.flatMap(line => line.receipts.map(receipt => <tr key={receipt.receipt_detail_id}>
                <td>{line.item?.sku ?? '#' + line.id}</td>
                <td>{receipt.invoice_reference} <small>#{receipt.receipt_detail_id}</small></td>
                <td>{receipt.lot_number}</td>
                <td>{receipt.quantity}</td>
                <td>{currencyFromCents(receipt.unit_cost_cents)}</td>
                <td>{receipt.returned_quantity.toLocaleString('pt-BR')}</td>
                <td>{receipt.variance_reason ?? 'Sem diferença registrada'}</td>
              </tr>))}
            </tbody></table>
          </div>}
          {order.status === 'approved' && <SupplierReturnsPanel
            orderId={order.id} lines={order.items} token={token} role={role} userId={userId}
            onChanged={async () => { await Promise.all([details.refetch(), onChanged()]); }} />}
          {order.status === 'approved' && <PurchaseReceiptMatchPanel
            orderId={order.id} supplierId={order.supplier_id} lines={order.items}
            token={token} onChanged={async () => { await Promise.all([details.refetch(), onChanged()]); }} />}
          <p className="stock-hint">Aprovar registra o compromisso de compra. Não efetua pagamento,
            não envia pedido ao fornecedor e não credita estoque.</p>
          {canApprove && <div className="stock-form">
            <div className="stock-field">
              <label htmlFor="purchase-review-note">Nota do aprovador</label>
              <textarea id="purchase-review-note" rows={2} maxLength={500}
                value={review} disabled={approve.isPending}
                onChange={event => setReview(event.target.value)} />
            </div>
            <button type="button" className="button primary" disabled={approve.isPending || cancel.isPending}
              onClick={() => approve.mutate()}>
              {approve.isPending ? 'Aprovando...' : 'Aprovar pedido de compra'}
            </button>
            {approve.error && <p role="alert" className="notice error">{readableError(approve.error)}</p>}
          </div>}
          {order.status === 'draft' && order.created_by?.id === userId &&
            <p className="stock-hint">A aprovação exige outra pessoa com permissão de gestão.</p>}
          {canCancel && <div className="stock-form">
            <div className="stock-field">
              <label htmlFor="purchase-cancel-reason">Motivo do cancelamento *</label>
              <input id="purchase-cancel-reason" minLength={5} maxLength={500}
                value={reason} disabled={cancel.isPending}
                onChange={event => setReason(event.target.value)} />
            </div>
            <button type="button" className="button ghost"
              disabled={cancel.isPending || approve.isPending || reason.trim().length < 5}
              onClick={() => cancel.mutate()}>Cancelar pedido</button>
            {cancel.error && <p role="alert" className="notice error">{readableError(cancel.error)}</p>}
          </div>}
        </>}
    </section>
  </div>;
}

export function StockPurchaseOrdersPanel({ token, role, userId }: {
  token: string | null; role: string; userId: number;
}) {
  const queryClient = useQueryClient();
  const [status, setStatus] = useState<OrderStatus | 'all'>('all');
  const [page, setPage] = useState(1);
  const [selectedOrder, setSelectedOrder] = useState<number | null>(null);
  const [planId, setPlanId] = useState('');
  const [supplierId, setSupplierId] = useState('');
  const [notes, setNotes] = useState('');
  const [selectedLines, setSelectedLines] = useState<Record<number, DraftLine>>({});
  const [requestId, setRequestId] = useState(() => crypto.randomUUID());
  const [notice, setNotice] = useState('');

  const orders = useQuery({
    queryKey: ['purchase-orders', status, page],
    queryFn: () => api<Page<PurchaseOrder>>('/stock/purchase-orders' + queryString({
      per_page: 25, page, ...(status === 'all' ? {} : { status }),
    }), token),
  });
  const details = useQuery({
    queryKey: ['purchase-order-plan', planId],
    queryFn: () => api<{ data: PlanDetails }>('/stock/replenishment-plans/' + planId, token),
    enabled: planId !== '',
  });
  const options = details.data?.data.items ?? [];
  const enabledLines = Object.values(selectedLines);
  const valid = Boolean(planId && supplierId && details.data?.data.status === 'approved') &&
    enabledLines.length > 0 && enabledLines.length <= 30 && notes.length <= 500 &&
    enabledLines.every(line => {
      const selected = options.find(option => option.id === line.plan_line_id);
      const count = Number(line.quantity);
      const cost = centsFromBrazilianValue(line.cost);
      return selected && selected.remaining_quantity >= count && /^[1-9][0-9]*$/.test(line.quantity) &&
        count <= 1000000 && cost !== null && cost <= 100000000;
    });
  const create = useMutation({
    mutationFn: () => api<{ data: PurchaseOrder }>('/stock/purchase-orders', token, {
      method: 'POST',
      body: JSON.stringify({
        request_id: requestId, plan_id: Number(planId), supplier_id: Number(supplierId),
        notes: notes.trim() || null,
        items: enabledLines.map(line => ({
          plan_line_id: line.plan_line_id,
          quantity: Number(line.quantity),
          unit_cost_cents: centsFromBrazilianValue(line.cost),
        })),
      }),
    }),
    onSuccess: async result => {
      setNotice('Pedido de compra #' + result.data.id + ' criado para aprovação humana.');
      setSelectedOrder(result.data.id);
      setPlanId(''); setSupplierId(''); setNotes(''); setSelectedLines({});
      setRequestId(crypto.randomUUID());
      await queryClient.invalidateQueries({ queryKey: ['purchase-orders'] });
    },
  });

  function changed(): void {
    setRequestId(crypto.randomUUID());
    setNotice('');
    create.reset();
  }

  function toggle(line: PlanLine): void {
    setSelectedLines(current => {
      const next = { ...current };
      if (next[line.id]) delete next[line.id];
      else if (Object.keys(next).length < 30 && line.remaining_quantity > 0) {
        next[line.id] = { plan_line_id: line.id, quantity: '1', cost: '' };
      }
      return next;
    });
    changed();
  }

  function updateLine(id: number, partial: Partial<DraftLine>): void {
    setSelectedLines(current => current[id] ? {
      ...current, [id]: { ...current[id], ...partial },
    } : current);
    changed();
  }

  function submit(event: FormEvent<HTMLFormElement>): void {
    event.preventDefault();
    if (valid && !create.isPending) create.mutate();
  }

  async function reload(): Promise<void> {
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: ['purchase-orders'] }),
      queryClient.invalidateQueries({ queryKey: ['purchase-order-plan'] }),
      queryClient.invalidateQueries({ queryKey: ['replenishment-plan-detail'] }),
    ]);
  }
  const currentPage = orders.data?.meta?.current_page ?? orders.data?.current_page ?? page;
  const lastPage = orders.data?.meta?.last_page ?? orders.data?.last_page ?? 1;
  const total = orders.data?.meta?.total ?? orders.data?.total ?? 0;

  return <section aria-label="Pedidos de compra">
    {notice && <p role="status" className="notice success">{notice}</p>}
    <div className="data-card stock-listing">
      <h2>Pedidos de compra</h2>
      <p className="stock-hint">Pedidos vinculados a planos aprovados, com revisão por uma segunda pessoa.
        Não realizam pagamento, envio externo ou recebimento automaticamente.</p>
      <div className="stock-field">
        <label htmlFor="purchase-status-filter">Situação dos pedidos</label>
        <select id="purchase-status-filter" value={status}
          onChange={event => { setStatus(event.target.value as OrderStatus | 'all'); setPage(1); }}>
          <option value="all">Todos</option><option value="draft">Aguardando aprovação</option>
          <option value="approved">Aprovados</option><option value="cancelled">Cancelados</option>
        </select>
      </div>
      {orders.isPending ? <p role="status">Carregando pedidos...</p> :
        orders.error ? <p role="alert" className="notice error">{readableError(orders.error)}</p> :
        orders.data.data.length === 0 ? <p>Nenhum pedido de compra registrado.</p> :
        <div className="table-wrap"><table><thead><tr>
          <th>Pedido</th><th>Plano</th><th>Fornecedor</th><th>Estado</th>
          <th>Valor</th><th>Revisão</th>
        </tr></thead><tbody>{orders.data.data.map(order => <tr key={order.id}>
          <td>#{order.id}</td><td>#{order.plan_id}</td>
          <td>{order.supplier?.name ?? '#' + order.supplier_id}</td>
          <td>{names[order.status]}</td><td>{currencyFromCents(order.total_cents)}</td>
          <td><button type="button" className="button ghost"
            onClick={() => setSelectedOrder(order.id)}
            aria-label={'Revisar pedido ' + order.id}>Detalhes</button></td>
        </tr>)}</tbody></table></div>}
      {!orders.isPending && !orders.error && <div className="pagination">
        <span>Página {currentPage} de {lastPage} · {total} pedidos</span>
        <div className="inline"><button type="button" className="button ghost" disabled={currentPage <= 1}
          onClick={() => setPage(currentPage - 1)}>Anterior</button>
          <button type="button" className="button ghost" disabled={currentPage >= lastPage}
            onClick={() => setPage(currentPage + 1)}>Próxima</button></div>
      </div>}
    </div>
    <form className="stock-form" onSubmit={submit}>
      <h3>Criar solicitação de compra</h3>
      <div className="stock-form-grid">
        <PagedEntitySelect<ApprovedPlanOption> id="purchase-plan" label="Proposta aprovada *"
          searchLabel="Buscar número da proposta aprovada" placeholder="Número da proposta"
          emptyLabel="Selecionar proposta" required value={planId}
          queryKey={['purchase-approved-plans']} endpoint="/stock/replenishment-plans"
          filters={{ status: 'approved' }} optionLabel={plan => 'Proposta #' + plan.id}
          onChange={value => { setPlanId(value); setSelectedLines({}); changed(); }}
          disabled={create.isPending} />
        <PagedEntitySelect<SupplierOption> id="purchase-supplier" label="Fornecedor da compra *"
          searchLabel="Buscar fornecedor da compra" placeholder="Código ou nome"
          emptyLabel="Selecionar fornecedor" required value={supplierId}
          queryKey={['purchase-suppliers']} endpoint="/stock/suppliers"
          filters={{ active: 1 }} optionLabel={supplier => supplier.code + ' — ' + supplier.name}
          onChange={value => { setSupplierId(value); changed(); }}
          disabled={create.isPending} />
      </div>
      {details.isPending && planId && <p role="status">Carregando linhas aprovadas...</p>}
      {details.error && <p role="alert" className="notice error">{readableError(details.error)}</p>}
      {details.data && <div className="table-wrap">
        <table><thead><tr><th>Incluir</th><th>Material</th><th>Destino</th>
          <th>Disponível para compra</th><th>Quantidade</th><th>Custo unitário (R$)</th></tr></thead>
          <tbody>{options.map(line => <tr key={line.id}>
            <td><input type="checkbox" aria-label={'Selecionar compra do item ' + line.item.sku}
              checked={Boolean(selectedLines[line.id])} disabled={line.remaining_quantity <= 0 || create.isPending ||
                (!selectedLines[line.id] && enabledLines.length >= 30)}
              onChange={() => toggle(line)} /></td>
            <td><strong>{line.item.sku}</strong><small>{line.item.name}</small></td>
            <td>{line.location.name}</td>
            <td>{line.remaining_quantity.toLocaleString('pt-BR')} {line.item.unit}</td>
            <td><input type="number" min={1} max={line.remaining_quantity} step={1}
              aria-label={'Quantidade para comprar ' + line.item.sku}
              value={selectedLines[line.id]?.quantity ?? ''} disabled={!selectedLines[line.id] || create.isPending}
              onChange={event => updateLine(line.id, { quantity: event.target.value })} /></td>
            <td><input inputMode="decimal" placeholder="68,75"
              aria-label={'Custo unitário da compra ' + line.item.sku}
              value={selectedLines[line.id]?.cost ?? ''} disabled={!selectedLines[line.id] || create.isPending}
              onChange={event => updateLine(line.id, { cost: event.target.value })} /></td>
          </tr>)}</tbody></table>
      </div>}
      {details.data && options.length === 0 && <p>Esta proposta não possui materiais.</p>}
      <div className="stock-field">
        <label htmlFor="purchase-notes">Observações de compra</label>
        <textarea id="purchase-notes" rows={2} maxLength={500} value={notes}
          disabled={create.isPending} onChange={event => { setNotes(event.target.value); changed(); }} />
      </div>
      {create.error && <p role="alert" className="notice error">{readableError(create.error)}</p>}
      <button type="submit" className="button primary" disabled={!valid || create.isPending}>
        {create.isPending ? 'Salvando solicitação...' : 'Criar pedido para aprovação'}
      </button>
    </form>
    {selectedOrder !== null && <PurchaseOrderDetail orderId={selectedOrder} token={token}
      role={role} userId={userId} onClose={() => setSelectedOrder(null)} onChanged={reload} />}
  </section>;
}
